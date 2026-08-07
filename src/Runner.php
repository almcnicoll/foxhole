<?php

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/OctopusClient.php';
require_once __DIR__ . '/PriceProvider.php';
require_once __DIR__ . '/CostBasisProvider.php';
require_once __DIR__ . '/ScheduleBuilder.php';
require_once __DIR__ . '/IntelligentScheduleBuilder.php';
require_once __DIR__ . '/UsageEstimator.php';
require_once __DIR__ . '/FoxessClient.php';
require_once __DIR__ . '/SolarForecastClient.php';

/**
 * Runs the full fetch -> build -> (push) pipeline once. Shared by run.php
 * (cron, CLI-only) and run-now.php (the dashboard's manual trigger, login-only)
 * — same logic, gated by two different trust mechanisms. Never exits; callers
 * decide what to do with the result (exit code for cron, a message for the UI).
 *
 * @param ?bool $forceIntelligent overrides the `intelligent_scheduler_enabled` setting for
 *        this run only — true/false forces that mode regardless of the stored setting, null
 *        (the default, and what run-now.php always passes) reads the setting as normal. This
 *        is how run.php's --classic/--intelligent CLI flags work; there's no UI equivalent —
 *        the setting itself is what the dashboard's "Run now" and cron both read.
 * @return array{ok: bool, dryRun: bool, message: string, schedule: ?array}
 */
function runScheduler(bool $dryRun, ?bool $forceIntelligent = null): array
{
    $logger = new Logger(__DIR__ . '/../logs/scheduler.log');
    $config = [];

    try {
        $config = require __DIR__ . '/../config.php';

        $timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');
        $today = new DateTimeImmutable('today', $timezone);
        $tomorrow = $today->modify('+1 day');

        $priceProvider = new PriceProvider(new OctopusClient($logger), $config['octopus']);

        // Agile rates for tomorrow publish ~16:00 UK time. Prefer tomorrow (the
        // normal case — cron runs once, after publish, to set up the next day)
        // but fall back to today if they're not out yet, so a run before ~16:00,
        // or a missed run catching up, still does something useful instead of
        // just failing. This fallback only itself fails if today comes back
        // completely empty, which in practice hasn't been observed — Octopus's
        // published horizon can still lag by an hour or two even for "today"
        // (see OctopusClient/PriceProvider), but a partial day is usable, not
        // a failure: it's just built with whatever slots exist.
        try {
            $targetDate = $tomorrow;
            $slots = $priceProvider->resolveImport($targetDate);
        } catch (OctopusFetchException $e) {
            $logger->info("Tomorrow's rates not available (" . $e->getMessage() . '), falling back to today.');
            $targetDate = $today;
            $slots = $priceProvider->resolveImport($targetDate);
        }

        // Export prices feed ScheduleBuilder's arbitrage/discharge logic, but a failure
        // here shouldn't block the import/schedule/push path that matters more — store
        // null for the run rather than aborting it. Aligned to import by timestamp
        // rather than requiring equal counts: import is now often a same-day *prefix*
        // of a full 48 (partial-day data, see OctopusClient) while fixed-mode export is
        // always a clean 48, so raw counts routinely differ even when every import slot
        // does have a matching export entry. Matched via getTimestamp(), not a formatted
        // string — OctopusClient's slots are UTC, fixed-mode slots are the configured
        // local timezone, so the same instant can format differently between the two.
        $exportSlots = null;
        try {
            $candidate = $priceProvider->resolveExport($targetDate);
            $exportByTime = [];
            foreach ($candidate as $exportSlot) {
                $exportByTime[$exportSlot['from']->getTimestamp()] = $exportSlot;
            }
            $aligned = [];
            foreach ($slots as $importSlot) {
                $match = $exportByTime[$importSlot['from']->getTimestamp()] ?? null;
                if ($match === null) {
                    $aligned = null;
                    break;
                }
                $aligned[] = $match;
            }
            if ($aligned !== null) {
                $exportSlots = $aligned;
            } else {
                $logger->warn('Export price slots do not fully cover the import slots for this run, storing without export prices.');
            }
        } catch (OctopusFetchException $e) {
            $logger->warn('Export price fetch failed, storing without export prices: ' . $e->getMessage());
        }

        // Not on the critical path — not used by ScheduleBuilder yet (see roadmap.MD's
        // "Solar-generation-aware scheduling"), just retrieved and stored for now, same
        // best-effort treatment as export prices: log and carry on if it fails. Panel
        // geometry/enabled live in Store (settings.php), same pattern as FoxESS creds —
        // see CLAUDE.md's "More settings-table config". Forecast.Solar's free tier only
        // allows a handful of calls/day per location, so refetch at most every 2h; the
        // existing stored forecast (if any) is left as-is otherwise.
        if (getSetting('solar_enabled', '0') === '1') {
            $existingForecast = getLatestSolarForecast();
            $twoHoursAgo = new DateTimeImmutable('-2 hours', $timezone);
            $staleEnough = !$existingForecast || $existingForecast[0]['fetched_at'] < $twoHoursAgo;
            if ($staleEnough) {
                try {
                    $solarConfig = [
                        'latitude' => getSetting('solar_latitude', '0'),
                        'longitude' => getSetting('solar_longitude', '0'),
                        'declination' => getSetting('solar_declination', '0'),
                        'azimuth' => getSetting('solar_azimuth', '0'),
                        'kwp' => getSetting('solar_kwp', '0'),
                    ];
                    $forecast = (new SolarForecastClient($logger))->fetchForecast($solarConfig, $timezone);
                    saveSolarForecast($forecast, new DateTimeImmutable('now', $timezone));
                } catch (SolarForecastException $e) {
                    $logger->warn('Solar forecast fetch failed, skipping: ' . $e->getMessage());
                }
            }
        }

        $costBasis = (new CostBasisProvider($config['cost_basis']))->getCostBasis(count($slots));

        // $scheduleBuilder is always constructed — even in intelligent mode — because
        // applyOverrides()/spliceForPush() below are pure group/interval transforms that
        // only need battery config, not any of ScheduleBuilder's own price-selection
        // state, so both scheduler modes share this one instance for those two steps
        // rather than IntelligentScheduleBuilder duplicating them.
        $scheduleBuilder = new ScheduleBuilder($config['strategy'], $config['battery']);

        $useIntelligent = $forceIntelligent ?? (getSetting('intelligent_scheduler_enabled', '1') === '1');
        $logger->info('Scheduler mode: ' . ($useIntelligent ? 'intelligent' : 'classic') . ($forceIntelligent !== null ? ' (forced via CLI flag)' : ' (from settings)'));

        if ($useIntelligent) {
            // Real battery SoC makes the projected-energy simulation meaningfully more
            // accurate, but reading it means touching FoxESS credentials — skipped for a
            // dry run so `--dry-run` keeps working with no FoxESS config at all (see
            // CLAUDE.md's "Running" section); IntelligentScheduleBuilder falls back to
            // assuming the reserve floor when SoC is null, same as it does if every
            // device is unreachable below.
            //
            // Multi-device installs (settings.php's device_sns) share one modelled
            // "battery" — config.battery is one combined capacity, and the same schedule
            // gets pushed to every device — so a single device's reading shouldn't stand
            // in for the whole thing. Average whichever devices actually respond, rather
            // than taking the first success. A reading of exactly 0% is excluded entirely
            // (not averaged in, not even as a low value) — a real battery never actually
            // reads that low, so 0% means "no battery attached to this inverter" (a
            // battery-less inverter reports it that way), not "empty". One real install
            // here has exactly that device, and averaging its permanent 0% in against a
            // real battery elsewhere on the same account produced a nonsense low starting
            // point for the simulation.
            $currentSocPercent = null;
            if (!$dryRun) {
                $socApiKey = getSetting('foxess_api_key', '');
                $socDeviceSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('foxess_device_sns', '')))));
                $socReadings = [];
                foreach ($socDeviceSns as $sn) {
                    try {
                        $soc = (new FoxessClient($socApiKey, $sn, $config['foxess']['base_url']))->getBatterySoc();
                        if ($soc !== null && $soc > 0.0) {
                            $socReadings[] = $soc;
                        }
                    } catch (FoxessPushException $e) {
                        $logger->warn("Battery SoC read from $sn failed, excluding from average: " . $e->getMessage());
                    }
                }
                if ($socReadings) {
                    $currentSocPercent = array_sum($socReadings) / count($socReadings);
                }
            }
            $usageConfig = ['avg_daily_kwh' => UsageEstimator::estimateDailyKwh(
                (float) getSetting('usage_summer_kwh_month', '300'),
                (float) getSetting('usage_winter_kwh_month', '700'),
                $targetDate,
                $timezone,
                getLatestSolarForecast(),
            )];
            $intelligentBuilder = new IntelligentScheduleBuilder($config['strategy'], $config['battery'], $usageConfig);
            $schedule = $intelligentBuilder->build($slots, $exportSlots, $costBasis, getLatestSolarForecast() ?: null, $currentSocPercent);
        } else {
            $schedule = $scheduleBuilder->build($slots, $exportSlots, $costBasis);
        }
        $now = new DateTimeImmutable('now', $timezone);

        // Rates are worth recording even in a dry run — it's what powers the dashboard.
        saveRateSlots($slots, $exportSlots, $now);

        // Any "Fill your boots" / "Power down" override saved for this exact date (override.php)
        // gets carved into the schedule here — after the price logic built its plan, before
        // either the dry-run preview or the real push, so both reflect it identically.
        $overridesForTarget = getOverridesForDate($targetDate->format('Y-m-d'));
        if ($overridesForTarget) {
            $overlaid = $scheduleBuilder->applyOverrides($schedule['groups'], $schedule['explanations'], $overridesForTarget, $timezone);
            $schedule['groups'] = $overlaid['groups'];
            $schedule['explanations'] = $overlaid['explanations'];
        }

        if ($dryRun) {
            $message = 'Dry run for ' . $targetDate->format('Y-m-d') . ': ' . count($schedule['groups']) . ' group(s), not pushed. ' . $schedule['summary'];
            $logger->info($message);
            return ['ok' => true, 'dryRun' => true, 'message' => $message, 'schedule' => $schedule];
        }

        // Whichever hours are still left of today can't just be overwritten by tomorrow's
        // plan wholesale — the FoxESS scheduler has no date field, only time-of-day, so
        // that would clobber today's already-correct decisions for the rest of today (see
        // CLAUDE.md's "Today/Tomorrow fix"). Splice today's stored plan onto tomorrow's
        // freshly-built one when there's a today plan to splice against; otherwise (e.g.
        // fallback-to-today runs before ~16:00) there's nothing to splice, push as-is.
        $pushGroups = $schedule['groups'];
        $pushExplanations = $schedule['explanations'];
        if ($targetDate == $tomorrow) {
            $todayPlan = getScheduleForDate($today->format('Y-m-d'));
            if ($todayPlan['pushed_at'] !== null) {
                $nowMinutes = ((int) $now->format('G')) * 60 + (int) $now->format('i');
                $spliced = $scheduleBuilder->spliceForPush($todayPlan['groups'], $todayPlan['explanations'], $schedule['groups'], $schedule['explanations'], $nowMinutes);
                $pushGroups = $spliced['groups'];
                $pushExplanations = $spliced['explanations'];
            }
        }

        // Compared against the last *actually pushed* (i.e. spliced) groups, not the raw
        // per-date plan — the splice boundary moves every run even when nothing about the
        // underlying prices changed, so diffing raw plans would misfire the skip. A device
        // that didn't receive that content stays in "pending_device_sns" until it does —
        // without this, a run that recomputes the same content as last time would read as
        // a no-op and a device that failed earlier (e.g. a battery-less inverter offline
        // overnight — see CLAUDE.md) would never get retried once it's reachable again.
        // Recorded regardless of whether anything below actually gets pushed to FoxESS —
        // this is what index.php shows as "today's plan"/"pushed at", and it must not go
        // missing just because the resolved schedule happens to match what's already
        // active on the inverter (the common case: a "Run now" click, or an every-3h
        // cron tick, that legitimately has nothing new to say). Otherwise the dashboard
        // has no record for today's date at all and reads as "nothing has run", even
        // though the correct schedule from a prior run is still in effect. Saved before
        // the unchanged/skip check below, not after, so a skipped push doesn't skip this.
        saveSchedule($targetDate->format('Y-m-d'), $schedule['groups'], $schedule['explanations'], $now);
        pruneOldSchedules($today->format('Y-m-d'));
        setSetting('schedule_summary', $schedule['summary']);

        $lastPushed = json_decode(getSetting('last_pushed_groups_json', '') ?: 'null', true);
        $pendingSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('pending_device_sns', '')))));
        $contentChanged = $pushGroups != $lastPushed;

        if (!$contentChanged && !$pendingSns) {
            $message = 'Schedule for ' . $targetDate->format('Y-m-d') . ' unchanged from last run, skipped FoxESS push.';
            $logger->info($message);
            return ['ok' => true, 'dryRun' => false, 'message' => $message, 'schedule' => $schedule];
        }

        $apiKey = getSetting('foxess_api_key', '');
        if ($apiKey === '') {
            throw new FoxessPushException('FoxESS API key not configured — set it at settings.php');
        }
        $deviceSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('foxess_device_sns', '')))));
        if (!$deviceSns) {
            throw new FoxessPushException('No FoxESS device serial numbers configured — set them at settings.php');
        }

        // A changed schedule goes to every device; an unchanged one only retries whichever
        // devices are still pending from an earlier failed attempt.
        $devicesToPush = $contentChanged ? $deviceSns : array_values(array_intersect($deviceSns, $pendingSns));
        $clients = [];
        foreach ($devicesToPush as $sn) {
            $clients[$sn] = new FoxessClient($apiKey, $sn, $config['foxess']['base_url']);
        }
        $pushResult = pushToDevices($clients, $pushGroups, $logger);
        $stillPending = $pushResult['failedSns'];
        setSetting('pending_device_sns', implode("\n", $stillPending));

        // saveSchedule()/schedule_summary already recorded above, before the unchanged-skip
        // check, so only the push-tracking setting is left to update here.
        setSetting('last_pushed_groups_json', json_encode($pushGroups));

        if ($stillPending) {
            // "Device offline" is expected/routine for a battery-less inverter after dark —
            // it has no power to stay connected once solar generation stops. That alone
            // isn't worth an ERROR/alert-email; a genuine failure (auth, permissions, ...)
            // still is. Either way the device stays pending and gets retried next run (above).
            $hardFailureSns = array_values(array_filter(
                $stillPending,
                fn($sn) => !isOfflineFailure($pushResult['failureMessages'][$sn]),
            ));
            $message = sprintf(
                'Pushed schedule for %s to %d/%d inverter(s); still pending: %s.',
                $targetDate->format('Y-m-d'),
                count($devicesToPush) - count($stillPending),
                count($deviceSns),
                implode(', ', $stillPending),
            );
            if ($hardFailureSns) {
                $logger->error($message . ' Failure detail: ' . implode('; ', $pushResult['failures']));
                alertOnFailure($config, 'FoxESS scheduler: push incomplete', $message);
                return ['ok' => false, 'dryRun' => false, 'message' => $message, 'schedule' => $schedule];
            }
            $logger->info($message . ' (offline — expected to retry next run)');
            return ['ok' => true, 'dryRun' => false, 'message' => $message, 'schedule' => $schedule];
        }

        $message = sprintf(
            'Pushed schedule for %s to %d inverter(s): %d group(s), %d FoxESS API call(s) this run. %s',
            $targetDate->format('Y-m-d'),
            count($deviceSns),
            count($pushGroups),
            $pushResult['callCount'],
            $schedule['summary'],
        );
        $logger->info($message);
        return ['ok' => true, 'dryRun' => false, 'message' => $message, 'schedule' => $schedule];
    } catch (OctopusFetchException|ScheduleBuildException|FoxessPushException $e) {
        $label = match (true) {
            $e instanceof OctopusFetchException => 'Octopus fetch failed',
            $e instanceof ScheduleBuildException => 'Schedule build failed',
            default => 'FoxESS push failed',
        };
        $message = "$label: " . $e->getMessage();
        $logger->error($message);
        alertOnFailure($config, "FoxESS scheduler: $label", $e->getMessage());
        return ['ok' => false, 'dryRun' => $dryRun, 'message' => $message, 'schedule' => null];
    } catch (Throwable $e) {
        $message = 'Unexpected error: ' . $e->getMessage();
        $logger->error($message);
        alertOnFailure($config, 'FoxESS scheduler: unexpected error', $e->getMessage());
        return ['ok' => false, 'dryRun' => $dryRun, 'message' => $message, 'schedule' => null];
    }
}

/**
 * Called by override.php right after saving an override. Rebuilds the schedule from
 * the *last-fetched* rate slots (no new Octopus call — this isn't a real run, just a
 * re-overlay) and, if that rebuild's date has overrides, pushes the overlaid result
 * to FoxESS immediately. Always rebuilds from scratch rather than overlaying onto
 * getLatestSchedule() — that's already-overridden output from the last push, so
 * re-overlaying onto it would permanently lose whatever it trimmed the first time.
 *
 * @return array{ok: bool, message: string}
 */
function reapplyOverrides(): array
{
    $logger = new Logger(__DIR__ . '/../logs/scheduler.log');
    $config = require __DIR__ . '/../config.php';
    $timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');

    $rows = getLatestRateSlots();
    if (!$rows) {
        return ['ok' => true, 'message' => 'No rates fetched yet, so there is nothing to overlay onto yet — this will apply automatically once a run has fetched rates for that date.'];
    }

    $targetDate = $rows[0]['from']->setTimezone($timezone)->format('Y-m-d');
    $overrides = getOverridesForDate($targetDate);
    if (!$overrides) {
        return ['ok' => true, 'message' => "Saved. The currently active schedule is for $targetDate, which has no override — nothing to push now."];
    }

    $importSlots = array_map(fn($r) => ['from' => $r['from'], 'to' => $r['to'], 'rate' => $r['import_rate']], $rows);
    $exportSlots = $rows[0]['export_rate'] !== null
        ? array_map(fn($r) => ['from' => $r['from'], 'to' => $r['to'], 'rate' => $r['export_rate']], $rows)
        : null;

    $costBasis = (new CostBasisProvider($config['cost_basis']))->getCostBasis(count($importSlots));
    // $scheduleBuilder is always constructed for applyOverrides() below (a pure
    // group/interval transform, same reasoning as runScheduler()) but the base schedule
    // it overlays onto must come from whichever builder run-now/cron actually use —
    // this used to always call ScheduleBuilder::build() regardless of the
    // intelligent_scheduler_enabled setting, so saving an override produced a
    // classic-heuristic schedule even when the rest of the app was running intelligent.
    $scheduleBuilder = new ScheduleBuilder($config['strategy'], $config['battery']);
    if (getSetting('intelligent_scheduler_enabled', '1') === '1') {
        $socApiKey = getSetting('foxess_api_key', '');
        $socDeviceSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('foxess_device_sns', '')))));
        $socReadings = [];
        foreach ($socDeviceSns as $sn) {
            try {
                $soc = (new FoxessClient($socApiKey, $sn, $config['foxess']['base_url']))->getBatterySoc();
                if ($soc !== null && $soc > 0.0) {
                    $socReadings[] = $soc;
                }
            } catch (FoxessPushException $e) {
                $logger->warn("Battery SoC read from $sn failed, excluding from average: " . $e->getMessage());
            }
        }
        $currentSocPercent = $socReadings ? array_sum($socReadings) / count($socReadings) : null;
        $usageConfig = ['avg_daily_kwh' => UsageEstimator::estimateDailyKwh(
            (float) getSetting('usage_summer_kwh_month', '300'),
            (float) getSetting('usage_winter_kwh_month', '700'),
            $rows[0]['from']->setTimezone($timezone),
            $timezone,
            getLatestSolarForecast(),
        )];
        $base = (new IntelligentScheduleBuilder($config['strategy'], $config['battery'], $usageConfig))
            ->build($importSlots, $exportSlots, $costBasis, getLatestSolarForecast() ?: null, $currentSocPercent);
    } else {
        $base = $scheduleBuilder->build($importSlots, $exportSlots, $costBasis);
    }
    $overlaid = $scheduleBuilder->applyOverrides($base['groups'], $base['explanations'], $overrides, $timezone);

    $apiKey = getSetting('foxess_api_key', '');
    $deviceSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('foxess_device_sns', '')))));
    if ($apiKey === '' || !$deviceSns) {
        return ['ok' => false, 'message' => 'Saved, but not pushed — FoxESS is not configured yet (settings.php).'];
    }

    $clients = [];
    foreach ($deviceSns as $sn) {
        $clients[$sn] = new FoxessClient($apiKey, $sn, $config['foxess']['base_url']);
    }
    $pushResult = pushToDevices($clients, $overlaid['groups'], $logger);
    if ($pushResult['failures']) {
        $message = sprintf('Saved, but the push failed for %d/%d inverter(s): %s', count($pushResult['failures']), count($deviceSns), implode('; ', $pushResult['failures']));
        $logger->error($message);
        return ['ok' => false, 'message' => $message];
    }

    $now = new DateTimeImmutable('now', $timezone);
    saveSchedule($targetDate, $overlaid['groups'], $overlaid['explanations'], $now);
    setSetting('last_pushed_groups_json', json_encode($overlaid['groups']));
    setSetting('schedule_summary', $base['summary']);
    $logger->info("Override applied and pushed for $targetDate.");
    return ['ok' => true, 'message' => "Saved and pushed to today's active schedule ($targetDate)."];
}

/**
 * Pushes the same schedule to every configured device, attempting all of them
 * even if an earlier one fails — one bad inverter shouldn't stop the others
 * from getting a real, working update. The caller decides whether any
 * failures should count as an overall run failure — see runScheduler(), which
 * treats an offline device (isOfflineFailure()) differently from a real one.
 *
 * @param array<string, FoxessClient> $clients device serial number => client
 * @return array{callCount: int, failures: string[], failedSns: string[], failureMessages: array<string, string>}
 */
function pushToDevices(array $clients, array $groups, Logger $logger): array
{
    $callCount = 0;
    $failures = [];
    $failedSns = [];
    $failureMessages = [];
    foreach ($clients as $sn => $client) {
        try {
            $client->pushSchedule($groups);
            $logger->info("Pushed schedule to $sn.");
        } catch (FoxessPushException $e) {
            $logger->error("Push to $sn failed: " . $e->getMessage());
            $failures[] = "$sn: " . $e->getMessage();
            $failedSns[] = $sn;
            $failureMessages[$sn] = $e->getMessage();
        }
        $callCount += $client->callCount();
    }
    return ['callCount' => $callCount, 'failures' => $failures, 'failedSns' => $failedSns, 'failureMessages' => $failureMessages];
}

/** errno 41935 ("Device offline") is routine for a battery-less inverter after dark — see CLAUDE.md. */
function isOfflineFailure(string $message): bool
{
    return str_contains($message, 'Device offline');
}

function alertOnFailure(array $config, string $subject, string $message): void
{
    $to = $config['notify']['alert_email'] ?? null;
    if (!$to) {
        return;
    }
    // Best-effort only — the caller's exit code (cron) or on-screen message (UI) is the real signal.
    @mail($to, $subject, $message);
}
