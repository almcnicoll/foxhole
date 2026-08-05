<?php

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/OctopusClient.php';
require_once __DIR__ . '/PriceProvider.php';
require_once __DIR__ . '/CostBasisProvider.php';
require_once __DIR__ . '/ScheduleBuilder.php';
require_once __DIR__ . '/FoxessClient.php';

/**
 * Runs the full fetch -> build -> (push) pipeline once. Shared by run.php
 * (cron, CLI-only) and run-now.php (the dashboard's manual trigger, login-only)
 * — same logic, gated by two different trust mechanisms. Never exits; callers
 * decide what to do with the result (exit code for cron, a message for the UI).
 *
 * @return array{ok: bool, dryRun: bool, message: string, schedule: ?array}
 */
function runScheduler(bool $dryRun): array
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

        $costBasis = (new CostBasisProvider($config['cost_basis']))->getCostBasis(count($slots));
        $schedule = (new ScheduleBuilder($config['strategy'], $config['battery']))->build($slots, $exportSlots, $costBasis);
        $now = new DateTimeImmutable('now', $timezone);

        // Rates are worth recording even in a dry run — it's what powers the dashboard.
        saveRateSlots($slots, $exportSlots, $now);

        if ($dryRun) {
            $message = 'Dry run for ' . $targetDate->format('Y-m-d') . ': ' . count($schedule['groups']) . ' group(s), not pushed. ' . $schedule['summary'];
            $logger->info($message);
            return ['ok' => true, 'dryRun' => true, 'message' => $message, 'schedule' => $schedule];
        }

        if ($schedule['groups'] == getLatestSchedule()['groups']) {
            $message = 'Schedule for ' . $targetDate->format('Y-m-d') . ' unchanged from last run, skipped FoxESS push.';
            $logger->info($message);
            return ['ok' => true, 'dryRun' => false, 'message' => $message, 'schedule' => $schedule];
        }

        $apiKey = getSetting('foxess_api_key', '');
        $deviceSn = getSetting('foxess_device_sn', '');
        if ($apiKey === '' || $deviceSn === '') {
            throw new FoxessPushException('FoxESS credentials not configured — set them at settings.php');
        }

        $foxess = new FoxessClient($apiKey, $deviceSn, $config['foxess']['base_url']);
        $foxess->pushSchedule($schedule['groups']);

        saveSchedule($targetDate->format('Y-m-d'), $schedule['groups'], $schedule['explanations'], $now);
        setSetting('schedule_summary', $schedule['summary']);
        $message = sprintf(
            'Pushed schedule for %s: %d group(s), %d FoxESS API call(s) this run. %s',
            $targetDate->format('Y-m-d'),
            count($schedule['groups']),
            $foxess->callCount(),
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

function alertOnFailure(array $config, string $subject, string $message): void
{
    $to = $config['notify']['alert_email'] ?? null;
    if (!$to) {
        return;
    }
    // Best-effort only — the caller's exit code (cron) or on-screen message (UI) is the real signal.
    @mail($to, $subject, $message);
}
