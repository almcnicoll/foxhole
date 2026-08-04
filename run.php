<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/Exceptions.php';
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

    if ($dryRun) {
        $logger->info('Dry run for ' . $tomorrow->format('Y-m-d') . ': ' . count($schedule['groups']) . ' group(s), not pushed.');
        echo json_encode($schedule, JSON_PRETTY_PRINT) . PHP_EOL;
        exit(0);
    }

    $lastScheduleFile = __DIR__ . '/data/last_schedule.json';
    $lastSchedule = is_file($lastScheduleFile) ? json_decode((string) file_get_contents($lastScheduleFile), true) : null;

    if ($lastSchedule == $schedule) {
        $logger->info('Schedule for ' . $tomorrow->format('Y-m-d') . ' unchanged from last run, skipping FoxESS push.');
        exit(0);
    }

    $foxess = new FoxessClient(
        $config['foxess']['api_key'],
        $config['foxess']['device_sn'],
        $config['foxess']['base_url'],
    );
    $foxess->pushSchedule($schedule['groups']);

    file_put_contents($lastScheduleFile, json_encode($schedule, JSON_PRETTY_PRINT));
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
