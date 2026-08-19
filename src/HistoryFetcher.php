<?php

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/FoxessClient.php';

/**
 * Backfills + keeps up to date the historic_generation table (see Store.php) from FoxESS's
 * report/query endpoint. Called from Runner.php on every real (non-dry-run) scheduled run,
 * and from history-fetch.php's "Fetch history now" button — see CLAUDE.md's "Generation
 * history" section for the full research/design writeup. Two independent passes, both
 * bounded per call so a single invocation finishes in a few seconds rather than trying to
 * backfill years of data in one shot:
 *
 *  - Forward catch-up: (re-)fetches from the latest local day already stored up through
 *    today, capped at HISTORY_FORWARD_CATCHUP_MAX_DAYS. Always includes today (never final
 *    until the day rolls over — only completed local hours are written, see storeDay())
 *    and re-touches the latest already-stored day too, cheap insurance against a previous
 *    run that landed mid-hour.
 *  - Backward backfill: walks one day at a time further into the past than anything
 *    stored, up to HISTORY_BACKWARD_BACKFILL_MAX_DAYS_PER_CALL days per call, stopping
 *    early and *permanently* (recorded in settings.history_backfill_exhausted_before) the
 *    moment FoxESS reports no data for a day — that's the device's own history horizon
 *    (install date, or whenever FoxESS Cloud itself started retaining data), and it never
 *    moves, so there's no reason to ever probe further back again. A single click of
 *    "Fetch history now" only advances this by one call's worth of days; click it again
 *    (or just wait for the next scheduled run) to keep walking back.
 *
 * Forecast data is deliberately NOT backfilled here — Forecast.Solar only exposes
 * *historic* forecasts on a paid tier, so there's nothing to fetch. Instead, Runner.php
 * calls upsertHistoricForecast() for each bucket the moment a live forecast fetch covers
 * it — the historic record of "what did we predict" builds up prospectively, one real
 * forecast fetch at a time, never retroactively. That's why forecast_kwh reads null for
 * any date before this feature shipped, and for any date the app happened not to be
 * running with solar forecasting enabled.
 *
 * All date/time arithmetic below uses the site's configured strategy.timezone, the same
 * fixed-location timezone every other scheduling decision in this app uses — never the
 * browser's or server's local timezone. An hour typed/read anywhere in this app means that
 * hour at the solar panels, not wherever a browser happens to be.
 *
 * GitHub issue #5 ("Modelling scheduler") added household usage history alongside
 * generation, in the same table (historic_generation.usage_kwh — see Store.php). Usage
 * fetching rides along inside this file's existing per-day loops but deliberately doesn't
 * touch their control flow at all: generation stays the sole source of truth for the
 * backward-backfill horizon (getHistoricGenerationBounds() is unchanged, generation-only),
 * and every usage fetch/store is independently try/caught (fetchAndStoreUsageForDay()) so
 * a usage-side failure or "no data" can never affect whether a day's generation gets
 * stored or whether the backfill loop keeps walking back.
 */

const HISTORY_FORWARD_CATCHUP_MAX_DAYS = 14;
const HISTORY_BACKWARD_BACKFILL_MAX_DAYS_PER_CALL = 20;
// No residential FoxESS install plausibly generates more than this in one hour — a value
// above it is far more likely to be FoxESS's own well-documented 32-bit energy-total
// overflow corruption (see TonyM1958/FoxESS-Cloud's fix_values workaround, applied there to
// chargeEnergyToTal/dischargeEnergyToTal — not confirmed to affect 'generation' specifically,
// but the guard is cheap and this table is never re-fetched once written, so a corrupted
// spike would otherwise sit there permanently).
const HISTORY_MAX_PLAUSIBLE_HOURLY_KWH = 50.0;

/** @return array{ok: bool, message: string} */
function fetchGenerationHistory(array $config, Logger $logger): array
{
    $apiKey = getSetting('foxess_api_key', '');
    $deviceSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('foxess_device_sns', '')))));
    if ($apiKey === '' || !$deviceSns) {
        return ['ok' => false, 'message' => 'FoxESS not configured yet — set the API key and device serial(s) at settings.php before fetching generation history.'];
    }

    $timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');
    $baseUrl = $config['foxess']['base_url'] ?? 'https://www.foxesscloud.com';
    $clients = [];
    foreach ($deviceSns as $sn) {
        $clients[$sn] = new FoxessClient($apiKey, $sn, $baseUrl);
    }
    $today = new DateTimeImmutable('today', $timezone);
    $daysStored = 0;

    // --- Forward catch-up: latest stored day (or yesterday, if nothing stored yet) through today ---
    $bounds = getHistoricGenerationBounds();
    $forwardStart = $bounds['latest'] !== null
        ? $bounds['latest']->setTimezone($timezone)->setTime(0, 0)
        : $today->modify('-1 day');
    $cappedStart = $today->modify('-' . HISTORY_FORWARD_CATCHUP_MAX_DAYS . ' days');
    if ($forwardStart < $cappedStart) {
        $forwardStart = $cappedStart; // a long-dead cron/gap: catch up gradually rather than one huge call
    }
    for ($day = $forwardStart; $day <= $today; $day = $day->modify('+1 day')) {
        $result = fetchDayAcrossDevices($clients, $day, $logger);
        if ($result === false || $result === null) {
            continue; // error: leave the gap, next call's forward window covers it again. No data: nothing to store.
        }
        storeDay($day, $result, $timezone, $day == $today, 'upsertHistoricGeneration');
        $daysStored++;
        fetchAndStoreUsageForDay($clients, $day, $timezone, $day == $today, $logger);
    }

    // --- Backward backfill: only runs until the data horizon is found once, ever ---
    if (getSetting('history_backfill_exhausted_before') === null) {
        $bounds = getHistoricGenerationBounds(); // re-read: the forward pass above may have set 'earliest' on a first-ever run
        $cursor = $bounds['earliest'] !== null ? $bounds['earliest']->setTimezone($timezone)->setTime(0, 0) : $today;
        for ($i = 0; $i < HISTORY_BACKWARD_BACKFILL_MAX_DAYS_PER_CALL; $i++) {
            $cursor = $cursor->modify('-1 day');
            $result = fetchDayAcrossDevices($clients, $cursor, $logger);
            if ($result === false) {
                break; // transient error — stop for this call, retry the same day next time
            }
            if ($result === null) {
                setSetting('history_backfill_exhausted_before', $cursor->modify('+1 day')->format('Y-m-d'));
                $logger->info('Generation history backfill reached the data horizon at ' . $cursor->format('Y-m-d') . '; will not probe further back.');
                break;
            }
            storeDay($cursor, $result, $timezone, false, 'upsertHistoricGeneration');
            $daysStored++;
            fetchAndStoreUsageForDay($clients, $cursor, $timezone, false, $logger);
        }
    }

    return ['ok' => true, 'message' => "Generation history: fetched $daysStored day(s)."];
}

/**
 * @param array<string, FoxessClient> $clients
 * @return array|null|false see combineDeviceGenerationResults()
 */
function fetchDayAcrossDevices(array $clients, DateTimeImmutable $day, Logger $logger): array|null|false
{
    $perDevice = [];
    foreach ($clients as $sn => $client) {
        try {
            $perDevice[] = $client->getGenerationReport((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'));
        } catch (FoxessPushException $e) {
            $logger->warn("Generation history fetch for $sn on " . $day->format('Y-m-d') . ' failed: ' . $e->getMessage());
            $perDevice[] = false;
        }
    }
    return combineDeviceGenerationResults($perDevice);
}

/**
 * Combines each configured device's report for one day into a single site-wide hourly kWh
 * array. Each device result is one of:
 *   - array  SUCCESS: real hourly values
 *   - null   NO_DATA: FoxESS has nothing for this device on this day (e.g. a second
 *            inverter added to the account after this date)
 *   - false  ERROR: the request itself failed (network, auth, a bad errno)
 *
 * Any ERROR makes the whole day untrustworthy — returns false, meaning "don't store
 * anything, try again another time". This matters because a day, once stored, is never
 * re-fetched (see this file's top doc comment): silently writing a partial/undercounted
 * sum because one of several inverters had a blip would corrupt that day's history
 * permanently. A device reporting NO_DATA is different and doesn't block the day — it just
 * contributes 0, which is what lets an older, single-inverter install's backfill continue
 * past the date a second inverter joined the account. Only when *every* device comes back
 * NO_DATA does the day itself count as NO_DATA — the signal the backward backfill loop
 * uses to detect it has reached the horizon and should stop permanently.
 *
 * @param array<int, array|null|false> $deviceResults
 */
function combineDeviceGenerationResults(array $deviceResults): array|null|false
{
    if (in_array(false, $deviceResults, true)) {
        return false;
    }
    $withData = array_values(array_filter($deviceResults, fn($r) => $r !== null));
    if (!$withData) {
        return null;
    }
    $hours = max(array_map('count', $withData));
    $totals = array_fill(0, $hours, 0.0);
    foreach ($withData as $values) {
        foreach ($values as $i => $v) {
            $totals[$i] += $v;
        }
    }
    return $totals;
}

/**
 * Persists one day's combined hourly kWh values via $upsert (upsertHistoricGeneration() or
 * upsertHistoricUsage() — both take the same (slotFrom, slotTo, kwh, updatedAt) shape). For
 * today specifically, only hours strictly before the current local hour are trusted —
 * FoxESS's report/query can return 0 (not absent) for hours that simply haven't happened
 * yet, and writing those as real zeros would understate today permanently once it's no
 * longer "today" and stops being re-fetched by the forward pass. Values beyond
 * HISTORY_MAX_PLAUSIBLE_HOURLY_KWH are treated as the known FoxESS energy-total corruption
 * bug (see this file's top comment) and dropped to 0 rather than trusted.
 */
function storeDay(DateTimeImmutable $day, array $hourlyKwh, DateTimeZone $timezone, bool $isToday, callable $upsert): void
{
    $now = new DateTimeImmutable('now', $timezone);
    $trustUpTo = $isToday ? (int) $now->format('G') : count($hourlyKwh);
    foreach ($hourlyKwh as $hour => $kwh) {
        if ($hour >= $trustUpTo) {
            break;
        }
        if ($kwh > HISTORY_MAX_PLAUSIBLE_HOURLY_KWH) {
            $kwh = 0.0;
        }
        $slotFrom = $day->setTime($hour, 0);
        $upsert($slotFrom, $slotFrom->modify('+1 hour'), $kwh, $now);
    }
}

/**
 * Best-effort household usage fetch+store for one day, ridden along inside
 * fetchGenerationHistory()'s existing per-day loops (GitHub issue #5) — deliberately
 * independent of generation's own control flow: a failure or "no data" here is caught and
 * logged, never allowed to affect whether generation was stored or whether the backward
 * backfill loop keeps walking back (that stays governed entirely by generation's own
 * NO_DATA signal). See FoxessClient::getUsageReport()'s doc comment for the `loads`
 * variable's known undercount caveat.
 */
function fetchAndStoreUsageForDay(array $clients, DateTimeImmutable $day, DateTimeZone $timezone, bool $isToday, Logger $logger): void
{
    try {
        $result = fetchUsageDayAcrossDevices($clients, $day, $logger);
        if ($result === false || $result === null) {
            return; // error or no usage data for this day/these devices — fine, just skip
        }
        storeDay($day, $result, $timezone, $isToday, 'upsertHistoricUsage');
    } catch (Throwable $e) {
        $logger->warn('Usage history fetch for ' . $day->format('Y-m-d') . ' failed, skipping: ' . $e->getMessage());
    }
}

/**
 * @param array<string, FoxessClient> $clients
 * @return array|null|false see combineDeviceGenerationResults() — reused as-is, it's
 *         generic over "an array of hourly kWh values per device" despite its name
 */
function fetchUsageDayAcrossDevices(array $clients, DateTimeImmutable $day, Logger $logger): array|null|false
{
    $perDevice = [];
    foreach ($clients as $sn => $client) {
        try {
            $perDevice[] = $client->getUsageReport((int) $day->format('Y'), (int) $day->format('n'), (int) $day->format('j'));
        } catch (FoxessPushException $e) {
            $logger->warn("Usage history fetch for $sn on " . $day->format('Y-m-d') . ' failed: ' . $e->getMessage());
            $perDevice[] = false;
        }
    }
    return combineDeviceGenerationResults($perDevice);
}
