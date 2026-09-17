<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Runner.php';
require_once __DIR__ . '/src/OctopusFlexClient.php';
require_once __DIR__ . '/src/SessionOptIn.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

/** Redirects to the dashboard with the same ?ran=1&ok=&msg= banner run-now.php already uses. */
function redirectResult(bool $ok, string $message): never
{
    header('Location: index.php?' . http_build_query([
        'ran' => '1',
        'ok' => $ok ? '1' : '0',
        'msg' => $message,
    ]));
    exit;
}

$kind = (string) ($_POST['kind'] ?? '');
$code = trim((string) ($_POST['code'] ?? ''));
if (!in_array($kind, ['power_down', 'fill_your_boots'], true) || $code === '') {
    redirectResult(false, 'Opt-in request was missing its session details — please try again from the dashboard.');
}

try {
    $eventStart = new DateTimeImmutable((string) ($_POST['event_start'] ?? ''));
    $eventEnd = new DateTimeImmutable((string) ($_POST['event_end'] ?? ''));
} catch (Exception $e) {
    redirectResult(false, 'Opt-in request had an invalid event time — please try again from the dashboard.');
}

$config = require __DIR__ . '/config.php';
$timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');
$now = new DateTimeImmutable('now', $timezone);
$label = $kind === 'power_down' ? 'Power down' : 'Fill your boots';

// Fail closed on an unknown battery SoC (confirmed with the user) — never guess at
// battery state for something that costs/earns real money. Same per-device averaging
// Runner.php's reapplyOverrides() already uses for the forecast-weighted/modelling
// schedulers' own live SoC read.
$apiKey = getSetting('foxess_api_key', '');
$deviceSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('foxess_device_sns', '')))));
$socReadings = [];
foreach ($deviceSns as $sn) {
    try {
        $soc = (new FoxessClient($apiKey, $sn, $config['foxess']['base_url']))->getBatterySoc();
        if ($soc !== null) {
            $socReadings[] = $soc;
        }
    } catch (FoxessPushException $e) {
        // one device's failure doesn't necessarily invalidate the others — see the check below
    }
}
if (!$socReadings) {
    redirectResult(false, "$label opt-in aborted: couldn't read live battery SoC from any configured inverter, so preparation can't be calculated safely. Try again once FoxESS is reachable.");
}
$currentSocPercent = array_sum($socReadings) / count($socReadings);

$batteryConfig = getBatteryConfig($config['battery'] ?? []);
$prep = calculateOptInPrep($kind, $eventStart, $currentSocPercent, $batteryConfig, $now, $timezone);

$forDate = $eventStart->setTimezone($timezone)->format('Y-m-d');
saveOverride(
    $forDate,
    $kind,
    $eventStart->setTimezone($timezone)->format('H:i'),
    $eventEnd->setTimezone($timezone)->format('H:i'),
    $prep !== null ? $prep['start']->setTimezone($timezone)->format('H:i') : null,
    $prep !== null ? $prep['end']->setTimezone($timezone)->format('H:i') : null,
);

$pushResult = reapplyOverrides();
if (!$pushResult['ok']) {
    redirectResult(false, "$label preparation was saved but not confirmed on the inverter(s), so Octopus was not told: " . $pushResult['message']);
}

$prepNote = $prep === null
    ? 'No preparation was needed — the battery is already at the right level.'
    : ($prep['clamped']
        ? 'Preparation starts at local midnight (the ideal start was earlier than that, which the override system can\'t express) rather than the full calculated duration.'
        : 'Preparation window set automatically from the current battery level.');

$octopusConfig = getOctopusAccountConfig();
if ($octopusConfig['api_key'] === '' || $octopusConfig['account_number'] === '') {
    redirectResult(false, "$label preparation was saved and pushed, but Octopus account credentials aren't configured (settings.php) — opt-in was not sent.");
}

try {
    $flexClient = new OctopusFlexClient(
        $octopusConfig['api_key'],
        $octopusConfig['account_number'],
        $octopusConfig['mpan'],
        $config['octopus']['flex_campaign_slugs'] ?? [],
    );
    $flexClient->joinSession($kind, $code);
} catch (OctopusFlexException $e) {
    redirectResult(false, "$label preparation was saved and pushed, but opting in with Octopus failed: " . $e->getMessage());
}

recordSessionOptIn($code, $kind, $forDate, $now);
redirectResult(true, "$label: opted in with Octopus and pushed the schedule ($prepNote)");
