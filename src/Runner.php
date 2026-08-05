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
        // just failing. Today's rates are always fully published by definition
        // (they were "tomorrow" yesterday), so this fallback can't itself fail
        // for the same reason.
        try {
            $targetDate = $tomorrow;
            $slots = $priceProvider->resolveImport($targetDate);
        } catch (OctopusFetchException $e) {
            $logger->info("Tomorrow's rates not available (" . $e->getMessage() . '), falling back to today.');
            $targetDate = $today;
            $slots = $priceProvider->resolveImport($targetDate);
        }

        // Export prices are informational (dashboard only — ScheduleBuilder doesn't
        // use them), so a failure here shouldn't block the import/schedule/push path
        // that actually matters. Store null for the run rather than aborting it.
        $exportSlots = null;
        try {
            $candidate = $priceProvider->resolveExport($targetDate);
            if (count($candidate) === count($slots)) {
                $exportSlots = $candidate;
            } else {
                $logger->warn(sprintf(
                    'Export price slot count (%d) does not match import (%d), storing without export prices.',
                    count($candidate),
                    count($slots),
                ));
            }
        } catch (OctopusFetchException $e) {
            $logger->warn('Export price fetch failed, storing without export prices: ' . $e->getMessage());
        }

        $costBasis = (new CostBasisProvider($config['cost_basis']))->getCostBasis(count($slots));
        $schedule = (new ScheduleBuilder($config['strategy'], $config['battery']))->build($slots, $costBasis);
        $now = new DateTimeImmutable('now', $timezone);

        // Rates are worth recording even in a dry run — it's what powers the dashboard.
        saveRateSlots($slots, $exportSlots, $now);

        if ($dryRun) {
            $message = 'Dry run for ' . $targetDate->format('Y-m-d') . ': ' . count($schedule['groups']) . ' group(s), not pushed.';
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

        saveSchedule($targetDate->format('Y-m-d'), $schedule['groups'], $now);
        $message = sprintf(
            'Pushed schedule for %s: %d group(s), %d FoxESS API call(s) this run.',
            $targetDate->format('Y-m-d'),
            count($schedule['groups']),
            $foxess->callCount(),
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
