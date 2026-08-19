<?php

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/OctopusClient.php';
require_once __DIR__ . '/PriceProvider.php';
require_once __DIR__ . '/CostBasisProvider.php';
require_once __DIR__ . '/ScheduleBuilder.php';
require_once __DIR__ . '/IntelligentScheduleBuilder.php';
require_once __DIR__ . '/Schedulers.php';
require_once __DIR__ . '/UsageEstimator.php';
require_once __DIR__ . '/FoxessClient.php';
require_once __DIR__ . '/SolarForecastClient.php';
require_once __DIR__ . '/HistoryFetcher.php';

/**
 * Runs the full fetch -> build -> (push) pipeline once. Shared by run.php
 * (cron, CLI-only) and run-now.php (the dashboard's manual trigger, login-only)
 * — same logic, gated by two different trust mechanisms. Never exits; callers
 * decide what to do with the result (exit code for cron, a message for the UI).
 *
 * @param ?string $forceSchedulerId overrides the stored `scheduler_id` setting (see
 *        Schedulers.php) for this run only — a valid scheduler id forces that scheduler
 *        regardless of what's selected on the Schedulers page, null (the default, and
 *        what run-now.php/cron.php always pass) resolves the setting as normal. This is
 *        how run.php's --classic/--intelligent CLI flags work; choosing a scheduler for
 *        real runs happens on the Schedulers page (schedulers.php), not here.
 * @return array{ok: bool, dryRun: bool, message: string, schedule: ?array}
 */
function runScheduler(bool $dryRun, ?string $forceSchedulerId = null): array
{
    $logger = new Logger(__DIR__ . '/../logs/scheduler.log');
    $config = [];

    try {
        $config = require __DIR__ . '/../config.php';

        $timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');
        $today = new DateTimeImmutable('today', $timezone);
        $tomorrow = $today->modify('+1 day');
        $now = new DateTimeImmutable('now', $timezone);

        $priceProvider = new PriceProvider(new OctopusClient($logger), $config['octopus']);

        // GitHub issue #4 ("Date-time-aware scheduling"): attempt BOTH today and
        // tomorrow every run — not "tomorrow, falling back to today" — so the schedule
        // can extend as far as pricing is actually published, not just one day at a
        // time. Agile rates for tomorrow publish ~16:00 UK time, so a run before that
        // just won't have tomorrow's rates yet, same as before; the only hard failure is
        // *neither* day producing any usable slots at all (see below), which in practice
        // hasn't been observed — Octopus's published horizon can lag an hour or two even
        // for "today", but a partial day is usable, not a failure.
        $fetchedAnyDay = false;
        foreach ([$today, $tomorrow] as $date) {
            try {
                $importSlots = $priceProvider->resolveImport($date);
            } catch (OctopusFetchException $e) {
                $logger->info('Rates for ' . $date->format('Y-m-d') . ' not available (' . $e->getMessage() . ').');
                continue;
            }
            $fetchedAnyDay = true;

            // Export prices feed arbitrage/discharge logic, but a failure here shouldn't
            // block the import/schedule/push path that matters more — store null for
            // this day rather than aborting the run. Aligned to import by timestamp
            // rather than requiring equal counts: import is often a same-day *prefix* of
            // a full 48 (partial-day data, see OctopusClient) while fixed-mode export is
            // always a clean 48, so raw counts routinely differ even when every import
            // slot does have a matching export entry. Matched via getTimestamp(), not a
            // formatted string — OctopusClient's slots are UTC, fixed-mode slots are the
            // configured local timezone, so the same instant can format differently
            // between the two.
            $exportSlots = null;
            try {
                $candidate = $priceProvider->resolveExport($date);
                $exportByTime = [];
                foreach ($candidate as $exportSlot) {
                    $exportByTime[$exportSlot['from']->getTimestamp()] = $exportSlot;
                }
                $aligned = [];
                foreach ($importSlots as $importSlot) {
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
                    $logger->warn('Export price slots do not fully cover import slots for ' . $date->format('Y-m-d') . ', storing without export prices.');
                }
            } catch (OctopusFetchException $e) {
                $logger->warn('Export price fetch failed for ' . $date->format('Y-m-d') . ', storing without export prices: ' . $e->getMessage());
            }

            // Persisted immediately per day, even in --dry-run (it's just a record of
            // what was fetched, and it's what powers the dashboard/Schedulers preview) —
            // upsertPriceSlots() never clobbers an already-known export rate with null,
            // so a later run that couldn't resolve export prices can't erase one this run
            // just recorded. See Store.php.
            upsertPriceSlots($importSlots, $exportSlots, $now);
        }
        if (!$fetchedAnyDay) {
            throw new OctopusFetchException('No rates available for today or tomorrow.');
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

            // Captured into historic_generation on every run, fresh fetch or not — see
            // HistoryFetcher.php's doc comment for why forecast history can only ever be
            // built forward like this, never backfilled. Cheap and idempotent:
            // upsertHistoricForecast() just re-writes the same value if nothing changed.
            $forecastCapturedAt = new DateTimeImmutable('now', $timezone);
            foreach (getLatestSolarForecast() as $bucket) {
                if ($bucket['from'] >= $bucket['to']) {
                    continue; // zero-width sunrise/sunset marker, see SolarForecastClient
                }
                upsertHistoricForecast($bucket['from'], $bucket['to'], $bucket['watt_hours'] / 1000, $forecastCapturedAt);
            }
        }

        // Not on the critical path, same best-effort treatment as solar forecast above — a
        // failure here should never abort a real scheduling run. Skipped for dry runs for
        // the same reason FoxESS credentials aren't read at all in that mode (see below):
        // this needs them too. See HistoryFetcher.php for what this actually does.
        if (!$dryRun) {
            try {
                $historyResult = fetchGenerationHistory($config, $logger);
                $logger->info('Generation history: ' . $historyResult['message']);
            } catch (Throwable $e) {
                $logger->warn('Generation history fetch failed, skipping: ' . $e->getMessage());
            }
        }

        // Known window: every currently-known slot from the start of today onward — may
        // span into tomorrow once published (see above). Split into calendar-day chunks;
        // each chunk gets scheduled exactly as a single-day run would have built it before
        // this multi-day support existed — see Schedulers.php's buildMultiDaySchedule(),
        // which both this function and schedulers.php's preview call.
        $knownSlots = getPriceSlotsFrom($today);
        $slotsByDate = [];
        foreach ($knownSlots as $slot) {
            $forDate = $slot['from']->setTimezone($timezone)->format('Y-m-d');
            $slotsByDate[$forDate]['importSlots'][] = ['from' => $slot['from'], 'to' => $slot['to'], 'rate' => $slot['import_rate']];
            $slotsByDate[$forDate]['exportSlots'][] = $slot['export_rate'] !== null
                ? ['from' => $slot['from'], 'to' => $slot['to'], 'rate' => $slot['export_rate']]
                : null;
        }
        // Export must be null for the whole day (not a list containing some nulls)
        // whenever any slot in that day lacks one — the schedulers expect "all or
        // nothing" per day, same alignment rule enforced per fetch above.
        foreach ($slotsByDate as $forDate => &$dayInputs) {
            if (in_array(null, $dayInputs['exportSlots'], true)) {
                $dayInputs['exportSlots'] = null;
            }
            $dayInputs['costBasis'] = (new CostBasisProvider($config['cost_basis']))->getCostBasis(count($dayInputs['importSlots']));
        }
        unset($dayInputs);

        // getBatteryConfig() reads from the settings table (editable via settings.php),
        // falling back to config.php's legacy 'battery' array (if still present) only
        // for whichever keys haven't been saved via settings.php yet — see Store.php.
        $batteryConfig = getBatteryConfig($config['battery'] ?? []);
        // $scheduleBuilder is always constructed — even when a different scheduler is
        // selected — because applyOverrides()/buildPushWindow() below are pure
        // group/interval transforms that only need battery config, not any of
        // ScheduleBuilder's own price-selection state, so every scheduler shares this
        // one instance for those two steps rather than duplicating them.
        $scheduleBuilder = new ScheduleBuilder($config['strategy'], $batteryConfig);

        // Which scheduler actually runs is chosen on the Schedulers page (schedulers.php),
        // not here — see Schedulers.php's resolveSchedulerId() for the fallback chain
        // (CLI override -> stored scheduler_id -> legacy intelligent_scheduler_enabled
        // toggle -> default).
        $schedulerId = resolveSchedulerId($forceSchedulerId);
        $schedulerName = SCHEDULER_DEFINITIONS[$schedulerId]['name'] ?? $schedulerId;
        $logger->info("Scheduler: $schedulerName" . ($forceSchedulerId !== null ? ' (forced via CLI flag)' : ' (from settings)') . ', covering ' . implode(', ', array_keys($slotsByDate)) . '.');

        // The forecast-weighted and modelling schedulers are the only ones that currently
        // need solar forecast or live battery SoC — gathered here rather than
        // unconditionally so a run using the classic scheduler doesn't pay for a live
        // FoxESS SoC call it has no use for. Gathered once for the whole run, not per day:
        // the live SoC reading only seeds day one (buildMultiDaySchedule() carries each
        // day's own projected finalSocPercent into the next; the modelling scheduler
        // always re-solves its whole rolling window fresh, so it only ever needs this
        // once anyway), and the day-length difference between adjacent calendar days is
        // immaterial to the usage estimate.
        $currentSocPercent = null;
        $solarSlots = null;
        if ($schedulerId === 'forecast_weighted_price_model' || $schedulerId === 'modelling') {
            // Real battery SoC makes the projected-energy simulation meaningfully more
            // accurate, but reading it means touching FoxESS credentials — skipped for a
            // dry run so `--dry-run` keeps working with no FoxESS config at all (see
            // CLAUDE.md's "Running" section); both schedulers fall back to assuming the
            // reserve floor when SoC is null, same as if every device is unreachable below.
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
            $solarSlots = getLatestSolarForecast() ?: null;
        }

        if ($schedulerId === 'modelling') {
            $modellingConfig = getModellingConfig();
            $scheduleByDate = buildModellingScheduleForRun($config['strategy'], $batteryConfig, $modellingConfig, $knownSlots, $now, $timezone, $solarSlots, $currentSocPercent);
        } else {
            $forecastExtras = $schedulerId === 'forecast_weighted_price_model' ? [
                'usageConfig' => ['avg_daily_kwh' => UsageEstimator::estimateDailyKwh(
                    (float) getSetting('usage_summer_kwh_month', '300'),
                    (float) getSetting('usage_winter_kwh_month', '700'),
                    $today,
                    $timezone,
                    getLatestSolarForecast(),
                )],
                'solarSlots' => $solarSlots,
                'currentSocPercent' => $currentSocPercent,
            ] : [];
            $scheduleByDate = buildMultiDaySchedule($schedulerId, $config['strategy'], $batteryConfig, $slotsByDate, $forecastExtras);
        }

        // Any "Fill your boots" / "Power down" override saved for a known date
        // (override.php) gets carved into that date's schedule here — after the price
        // logic built its plan, before either the dry-run preview or the real push, so
        // both reflect it identically. applyOverrides() is a no-op for a date with none.
        foreach ($scheduleByDate as $forDate => &$daySchedule) {
            $overridesForDate = getOverridesForDate($forDate);
            if ($overridesForDate) {
                $overlaid = $scheduleBuilder->applyOverrides($daySchedule['groups'], $daySchedule['explanations'], $overridesForDate, $timezone);
                $daySchedule['groups'] = $overlaid['groups'];
                $daySchedule['explanations'] = $overlaid['explanations'];
            }
        }
        unset($daySchedule);

        if ($dryRun) {
            $totalGroups = array_sum(array_map(fn($s) => count($s['groups']), $scheduleByDate));
            $message = 'Dry run for ' . implode(', ', array_keys($scheduleByDate)) . ': ' . $totalGroups . ' total group(s) across ' . count($scheduleByDate) . ' day(s), not pushed.';
            $logger->info($message);
            return ['ok' => true, 'dryRun' => true, 'message' => $message, 'schedule' => $scheduleByDate];
        }

        // Recorded for every known date regardless of whether anything below actually
        // gets pushed to FoxESS — this is what index.php/schedulers.php show as each
        // date's plan, and it must not go missing just because the resolved schedule
        // happens to match what's already active on the inverter (the common case: a
        // "Run now" click, or an every-3h cron tick, that legitimately has nothing new
        // to say). Saved before the push-window derivation below, not after, so a
        // skipped/failed push doesn't skip this.
        foreach ($scheduleByDate as $forDate => $daySchedule) {
            saveSchedule($forDate, $daySchedule['groups'], $daySchedule['explanations'], $now);
            upsertScheduleSummary($forDate, $daySchedule['summary']);
        }
        pruneOldSchedules($today->format('Y-m-d'));

        // The actual FoxESS push covers from the start of the current hour through 24h
        // ahead or the end of known pricing, whichever is sooner (GitHub issue #4, point
        // 3) — never a full 24h recurring cycle pretending to know hours it doesn't have
        // real prices for. See ScheduleBuilder::buildPushWindow().
        $knownDataEnd = getLatestPriceHorizon();
        $pushWindow = $scheduleBuilder->buildPushWindow($scheduleByDate, $now, $timezone, $knownDataEnd);
        $pushGroups = $pushWindow['groups'];
        $windowDescription = $pushWindow['windowStart']->format('D j M H:i') . ' to ' . $pushWindow['windowEnd']->format('D j M H:i');

        // Compared against the last *actually pushed* (i.e. windowed) groups, not any
        // single date's raw plan — the window boundary moves every run even when nothing
        // about the underlying prices changed, so diffing raw plans would misfire the
        // skip. A device that didn't receive that content stays in "pending_device_sns"
        // until it does — without this, a run that recomputes the same content as last
        // time would read as a no-op and a device that failed earlier (e.g. a
        // battery-less inverter offline overnight — see CLAUDE.md) would never get
        // retried once it's reachable again.
        $lastPushed = json_decode(getSetting('last_pushed_groups_json', '') ?: 'null', true);
        $pendingSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('pending_device_sns', '')))));
        $contentChanged = $pushGroups != $lastPushed;

        // Temporarily disabled (user request, 2026-08-11): every run now pushes to the
        // inverters regardless of whether the schedule changed. Uncomment to restore the
        // "skip an unchanged push" optimisation.
        // if (!$contentChanged && !$pendingSns) {
        //     $message = 'Push window ' . $windowDescription . ' unchanged from last run, skipped FoxESS push.';
        //     $logger->info($message);
        //     return ['ok' => true, 'dryRun' => false, 'message' => $message, 'schedule' => $scheduleByDate];
        // }

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
        // Temporarily disabled alongside the skip-check above (2026-08-11) — every run pushes
        // to every configured device now, so this always resolves to the full list.
        // $devicesToPush = $contentChanged ? $deviceSns : array_values(array_intersect($deviceSns, $pendingSns));
        $devicesToPush = $deviceSns;
        $clients = [];
        foreach ($devicesToPush as $sn) {
            $clients[$sn] = new FoxessClient($apiKey, $sn, $config['foxess']['base_url']);
        }
        $pushResult = pushToDevices($clients, $pushGroups, $logger);
        $stillPending = $pushResult['failedSns'];
        setSetting('pending_device_sns', implode("\n", $stillPending));

        // saveSchedule()/schedule_summaries already recorded above, before the push-window
        // derivation, so only the push-tracking setting is left to update here.
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
                'Pushed schedule (%s) to %d/%d inverter(s); still pending: %s.',
                $windowDescription,
                count($devicesToPush) - count($stillPending),
                count($deviceSns),
                implode(', ', $stillPending),
            );
            if ($hardFailureSns) {
                $logger->error($message . ' Failure detail: ' . implode('; ', $pushResult['failures']));
                alertOnFailure($config, 'FoxESS scheduler: push incomplete', $message);
                return ['ok' => false, 'dryRun' => false, 'message' => $message, 'schedule' => $scheduleByDate];
            }
            $logger->info($message . ' (offline — expected to retry next run)');
            return ['ok' => true, 'dryRun' => false, 'message' => $message, 'schedule' => $scheduleByDate];
        }

        $message = sprintf(
            'Pushed schedule (%s) to %d inverter(s): %d group(s), %d FoxESS API call(s) this run. %s',
            $windowDescription,
            count($deviceSns),
            count($pushGroups),
            $pushResult['callCount'],
            implode(' ', array_column($scheduleByDate, 'summary')),
        );
        $logger->info($message);
        return ['ok' => true, 'dryRun' => false, 'message' => $message, 'schedule' => $scheduleByDate];
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
 * Called by override.php right after saving an override for one or more dates. Rebuilds
 * every currently-known date's schedule from *already-stored* prices (no new Octopus
 * call — this isn't a real run, just a re-overlay), applies whatever overrides exist per
 * date, and — if any date actually has one — pushes the resulting push window to FoxESS
 * immediately, same window derivation runScheduler() uses (ScheduleBuilder::buildPushWindow()).
 * Always rebuilds from scratch rather than overlaying onto getScheduleForDate()'s stored
 * output — that's already-overridden from the last push, so re-overlaying onto it would
 * permanently lose whatever it trimmed the first time.
 *
 * @return array{ok: bool, message: string}
 */
function reapplyOverrides(): array
{
    $logger = new Logger(__DIR__ . '/../logs/scheduler.log');
    $config = require __DIR__ . '/../config.php';
    $timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');
    $today = new DateTimeImmutable('today', $timezone);
    $now = new DateTimeImmutable('now', $timezone);

    $knownSlots = getPriceSlotsFrom($today);
    if (!$knownSlots) {
        return ['ok' => true, 'message' => 'No rates fetched yet, so there is nothing to overlay onto yet — this will apply automatically once a run has fetched rates.'];
    }

    // Same per-calendar-day split as runScheduler() — see Schedulers.php's buildMultiDaySchedule().
    $slotsByDate = [];
    foreach ($knownSlots as $slot) {
        $forDate = $slot['from']->setTimezone($timezone)->format('Y-m-d');
        $slotsByDate[$forDate]['importSlots'][] = ['from' => $slot['from'], 'to' => $slot['to'], 'rate' => $slot['import_rate']];
        $slotsByDate[$forDate]['exportSlots'][] = $slot['export_rate'] !== null
            ? ['from' => $slot['from'], 'to' => $slot['to'], 'rate' => $slot['export_rate']]
            : null;
    }
    foreach ($slotsByDate as $forDate => &$dayInputs) {
        if (in_array(null, $dayInputs['exportSlots'], true)) {
            $dayInputs['exportSlots'] = null;
        }
        $dayInputs['costBasis'] = (new CostBasisProvider($config['cost_basis']))->getCostBasis(count($dayInputs['importSlots']));
    }
    unset($dayInputs);

    $hasAnyOverride = false;
    foreach (array_keys($slotsByDate) as $forDate) {
        if (getOverridesForDate($forDate)) {
            $hasAnyOverride = true;
            break;
        }
    }
    if (!$hasAnyOverride) {
        return ['ok' => true, 'message' => 'Saved. None of the currently known dates (' . implode(', ', array_keys($slotsByDate)) . ') have an override — nothing to push now.'];
    }

    $batteryConfig = getBatteryConfig($config['battery'] ?? []);
    // $scheduleBuilder is always constructed for applyOverrides()/buildPushWindow() below
    // (pure group/interval transforms, same reasoning as runScheduler()) but the base
    // schedule they overlay onto must come from whichever scheduler is actually selected
    // (see Schedulers.php) — this used to always call ScheduleBuilder::build() regardless
    // of that, so saving an override could silently produce a classic-heuristic schedule
    // even when a different scheduler was selected for real runs.
    $scheduleBuilder = new ScheduleBuilder($config['strategy'], $batteryConfig);
    $schedulerId = resolveSchedulerId();

    $currentSocPercent = null;
    $solarSlots = null;
    if ($schedulerId === 'forecast_weighted_price_model' || $schedulerId === 'modelling') {
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
        $solarSlots = getLatestSolarForecast() ?: null;
    }

    if ($schedulerId === 'modelling') {
        $modellingConfig = getModellingConfig();
        $scheduleByDate = buildModellingScheduleForRun($config['strategy'], $batteryConfig, $modellingConfig, $knownSlots, $now, $timezone, $solarSlots, $currentSocPercent);
    } else {
        $forecastExtras = $schedulerId === 'forecast_weighted_price_model' ? [
            'currentSocPercent' => $currentSocPercent,
            'usageConfig' => ['avg_daily_kwh' => UsageEstimator::estimateDailyKwh(
                (float) getSetting('usage_summer_kwh_month', '300'),
                (float) getSetting('usage_winter_kwh_month', '700'),
                $today,
                $timezone,
                getLatestSolarForecast(),
            )],
            'solarSlots' => $solarSlots,
        ] : [];
        $scheduleByDate = buildMultiDaySchedule($schedulerId, $config['strategy'], $batteryConfig, $slotsByDate, $forecastExtras);
    }

    foreach ($scheduleByDate as $forDate => &$daySchedule) {
        $overridesForDate = getOverridesForDate($forDate);
        if ($overridesForDate) {
            $overlaid = $scheduleBuilder->applyOverrides($daySchedule['groups'], $daySchedule['explanations'], $overridesForDate, $timezone);
            $daySchedule['groups'] = $overlaid['groups'];
            $daySchedule['explanations'] = $overlaid['explanations'];
        }
    }
    unset($daySchedule);

    $apiKey = getSetting('foxess_api_key', '');
    $deviceSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('foxess_device_sns', '')))));
    if ($apiKey === '' || !$deviceSns) {
        return ['ok' => false, 'message' => 'Saved, but not pushed — FoxESS is not configured yet (settings.php).'];
    }

    foreach ($scheduleByDate as $forDate => $daySchedule) {
        saveSchedule($forDate, $daySchedule['groups'], $daySchedule['explanations'], $now);
        upsertScheduleSummary($forDate, $daySchedule['summary']);
    }

    $knownDataEnd = getLatestPriceHorizon();
    $pushWindow = $scheduleBuilder->buildPushWindow($scheduleByDate, $now, $timezone, $knownDataEnd);

    $clients = [];
    foreach ($deviceSns as $sn) {
        $clients[$sn] = new FoxessClient($apiKey, $sn, $config['foxess']['base_url']);
    }
    $pushResult = pushToDevices($clients, $pushWindow['groups'], $logger);
    if ($pushResult['failures']) {
        $message = sprintf('Saved, but the push failed for %d/%d inverter(s): %s', count($pushResult['failures']), count($deviceSns), implode('; ', $pushResult['failures']));
        $logger->error($message);
        return ['ok' => false, 'message' => $message];
    }

    setSetting('last_pushed_groups_json', json_encode($pushWindow['groups']));
    $windowDescription = $pushWindow['windowStart']->format('D j M H:i') . ' to ' . $pushWindow['windowEnd']->format('D j M H:i');
    $logger->info("Override applied and pushed ($windowDescription).");
    return ['ok' => true, 'message' => "Saved and pushed the active schedule ($windowDescription)."];
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
