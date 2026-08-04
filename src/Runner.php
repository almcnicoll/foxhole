<?php

require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/OctopusClient.php';
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
        $tomorrow = new DateTimeImmutable('tomorrow', $timezone);

        $octopus = new OctopusClient($logger);
        $slots = $octopus->fetchRatesForDate(
            $config['octopus']['product_code'],
            $config['octopus']['tariff_code'],
            $tomorrow,
        );

        $costBasis = (new CostBasisProvider($config['cost_basis']))->getCostBasis(count($slots));
        $schedule = (new ScheduleBuilder($config['strategy'], $config['battery']))->build($slots, $costBasis);
        $now = new DateTimeImmutable('now', $timezone);

        // Rates are worth recording even in a dry run — it's what powers the dashboard.
        saveRateSlots($slots, $now);

        if ($dryRun) {
            $message = 'Dry run for ' . $tomorrow->format('Y-m-d') . ': ' . count($schedule['groups']) . ' group(s), not pushed.';
            $logger->info($message);
            return ['ok' => true, 'dryRun' => true, 'message' => $message, 'schedule' => $schedule];
        }

        if ($schedule['groups'] == getLatestSchedule()['groups']) {
            $message = 'Schedule for ' . $tomorrow->format('Y-m-d') . ' unchanged from last run, skipped FoxESS push.';
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

        saveSchedule($tomorrow->format('Y-m-d'), $schedule['groups'], $now);
        $message = sprintf(
            'Pushed schedule for %s: %d group(s), %d FoxESS API call(s) this run.',
            $tomorrow->format('Y-m-d'),
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
