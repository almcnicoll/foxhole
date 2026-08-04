<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/Exceptions.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/OctopusClient.php';
require_once __DIR__ . '/src/CostBasisProvider.php';
require_once __DIR__ . '/src/ScheduleBuilder.php';
require_once __DIR__ . '/src/FoxessClient.php';

$dryRun = in_array('--dry-run', $argv, true);
$logger = new Logger(__DIR__ . '/logs/scheduler.log');
$config = [];

function alertOnFailure(array $config, string $subject, string $message): void
{
    $to = $config['notify']['alert_email'] ?? null;
    if (!$to) {
        return;
    }
    // Best-effort only — the non-zero exit code is what actually makes cron failure visible.
    @mail($to, $subject, $message);
}

try {
    $config = require __DIR__ . '/config.php';

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
        $logger->info('Dry run for ' . $tomorrow->format('Y-m-d') . ': ' . count($schedule['groups']) . ' group(s), not pushed.');
        echo json_encode($schedule, JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    if ($schedule['groups'] == getLatestSchedule()['groups']) {
        $logger->info('Schedule for ' . $tomorrow->format('Y-m-d') . ' unchanged from last run, skipping FoxESS push.');
        exit(0);
    }

    $apiKey = getSetting('foxess_api_key', '');
    $deviceSn = getSetting('foxess_device_sn', '');
    if ($apiKey === '' || $deviceSn === '') {
        throw new FoxessPushException('FoxESS credentials not configured — set them at settings.php');
    }

    $foxess = new FoxessClient($apiKey, $deviceSn, $config['foxess']['base_url']);
    $foxess->pushSchedule($schedule['groups']);

    saveSchedule($tomorrow->format('Y-m-d'), $schedule['groups'], $now);
    $logger->info(sprintf(
        'Pushed schedule for %s: %d group(s), %d FoxESS API call(s) this run.',
        $tomorrow->format('Y-m-d'),
        count($schedule['groups']),
        $foxess->callCount(),
    ));
} catch (OctopusFetchException $e) {
    $logger->error('Octopus fetch failed: ' . $e->getMessage());
    alertOnFailure($config, 'FoxESS scheduler: Octopus fetch failed', $e->getMessage());
    exit(1);
} catch (ScheduleBuildException $e) {
    $logger->error('Schedule build failed: ' . $e->getMessage());
    alertOnFailure($config, 'FoxESS scheduler: schedule build failed', $e->getMessage());
    exit(1);
} catch (FoxessPushException $e) {
    $logger->error('FoxESS push failed: ' . $e->getMessage());
    alertOnFailure($config, 'FoxESS scheduler: push failed', $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    $logger->error('Unexpected error: ' . $e->getMessage());
    alertOnFailure($config, 'FoxESS scheduler: unexpected error', $e->getMessage());
    exit(1);
}
