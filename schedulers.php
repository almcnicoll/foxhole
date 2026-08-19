<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';
require_once __DIR__ . '/src/Runner.php';

requireLogin();

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');

// POST-redirect-GET, same pattern as settings.php's cron-token regenerate button — a page
// refresh after picking a scheduler shouldn't re-submit the choice. This only ever changes
// the *stored selection* for future runs (run.php, run-now.php, cron.php) — nothing here
// pushes anything to FoxESS, same as the rest of this page just previewing, not applying.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chosenId = (string) ($_POST['scheduler_id'] ?? '');
    if (isset(SCHEDULER_DEFINITIONS[$chosenId])) {
        setSetting('scheduler_id', $chosenId);
    }
    header('Location: schedulers.php');
    exit;
}

$activeSchedulerId = resolveSchedulerId();
$today = new DateTimeImmutable('today', $timezone);

// Reuses whatever's already been fetched (same as the dashboard/reapplyOverrides()) rather
// than triggering a fresh Octopus call on every page view — this page previews, it doesn't
// re-run the pipeline. May span into tomorrow once published — see CLAUDE.md's
// "Date-time-aware scheduling" — same per-calendar-day split Runner.php uses.
$knownSlots = getPriceSlotsFrom($today);
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

// Battery specs live in the settings table now (see settings.php's "Battery" section) —
// config.php's old 'battery' array is only read as a migration fallback, same as index.php.
$batteryConfig = getBatteryConfig($config['battery'] ?? []);

/** @var array<string, array{scheduleByDate: ?array, error: ?string}> $previews scheduler id => preview result */
$previews = [];
if ($knownSlots) {
    // Best-effort live battery SoC, same averaging/0%-exclusion rules as Runner.php's
    // runScheduler() — only the forecast-weighted scheduler's preview actually uses it, but
    // it's the same one-call-per-device cost the dashboard already pays on every load, so
    // gathering it once up front for whichever preview wants it is cheap enough.
    $apiKey = getSetting('foxess_api_key', '');
    $deviceSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('foxess_device_sns', '')))));
    $socReadings = [];
    foreach ($deviceSns as $sn) {
        try {
            $soc = (new FoxessClient($apiKey, $sn, $config['foxess']['base_url'] ?? 'https://www.foxesscloud.com'))->getBatterySoc();
            if ($soc !== null && $soc > 0.0) {
                $socReadings[] = $soc;
            }
        } catch (FoxessPushException $e) {
            // Best-effort — a preview without a live SoC reading still falls back sensibly
            // (see IntelligentScheduleBuilder), same as a real run when every device is offline.
        }
    }

    $forecastExtras = [
        'currentSocPercent' => $socReadings ? array_sum($socReadings) / count($socReadings) : null,
        'usageConfig' => ['avg_daily_kwh' => UsageEstimator::estimateDailyKwh(
            (float) getSetting('usage_summer_kwh_month', '300'),
            (float) getSetting('usage_winter_kwh_month', '700'),
            $today,
            $timezone,
            getLatestSolarForecast(),
        )],
        'solarSlots' => getLatestSolarForecast() ?: null,
    ];

    $now = new DateTimeImmutable('now', $timezone);
    $modellingConfig = getModellingConfig();

    foreach (SCHEDULER_DEFINITIONS as $id => $definition) {
        try {
            if ($id === 'modelling') {
                $scheduleByDate = buildModellingScheduleForRun($config['strategy'], $batteryConfig, $modellingConfig, $knownSlots, $now, $timezone, $forecastExtras['solarSlots'], $forecastExtras['currentSocPercent']);
            } else {
                $scheduleByDate = buildMultiDaySchedule($id, $config['strategy'], $batteryConfig, $slotsByDate, $forecastExtras);
            }
            $previews[$id] = ['scheduleByDate' => $scheduleByDate, 'error' => null];
        } catch (Throwable $e) {
            $previews[$id] = ['scheduleByDate' => null, 'error' => $e->getMessage()];
        }
    }
}

renderHeader('Schedulers');
?>

<p class="muted">
    Choose which scheduling algorithm computes the plan pushed to your inverter. Each box below shows its current
    recommended schedule, calculated from the latest fetched rates — <strong>not</strong> applied or pushed to
    FoxESS. The selected scheduler is what <code>run.php</code>, the dashboard's "Run now" button, and
    <code>cron.php</code> all use for real runs, until you change it here.
</p>

<?php if (!$knownSlots): ?>
<p class="muted">No rates fetched yet, so there's nothing to preview — "Run now" on the dashboard (or run.php) needs
    to complete a fetch first. You can still pick a scheduler below; it'll take effect on the next run.</p>
<?php endif; ?>

<div class="settings-grid">
<?php foreach (SCHEDULER_DEFINITIONS as $id => $definition):
    $isActive = $id === $activeSchedulerId;
    $preview = $previews[$id] ?? null;
?>
    <fieldset class="scheduler-card<?= $isActive ? ' scheduler-card-active' : '' ?>">
        <legend>
            <?= htmlspecialchars($definition['name']) ?>
            <?php if ($isActive): ?><span class="badge badge-active">Active</span><?php endif; ?>
        </legend>
        <p class="muted"><?= htmlspecialchars($definition['description']) ?></p>

        <?php if (!$isActive): ?>
        <form method="post">
            <input type="hidden" name="scheduler_id" value="<?= htmlspecialchars($id) ?>">
            <button type="submit">Use this scheduler</button>
        </form>
        <?php endif; ?>

        <?php if ($preview === null): ?>
        <p class="muted">No preview yet — fetch rates first.</p>
        <?php elseif ($preview['error'] !== null): ?>
        <p class="error">Couldn't compute a preview: <?= htmlspecialchars($preview['error']) ?></p>
        <?php else: foreach ($preview['scheduleByDate'] as $forDate => $daySchedule): ?>
        <h4><?= htmlspecialchars((new DateTimeImmutable($forDate, $timezone))->format('D j M')) ?></h4>
        <p class="muted"><?= htmlspecialchars($daySchedule['summary']) ?></p>
        <?php if ($daySchedule['groups']): ?>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Mode</th>
                    <th>Why</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($daySchedule['groups'] as $i => $group): ?>
                <tr class="row-<?= htmlspecialchars($group['workMode']) ?>">
                    <td><?= htmlspecialchars(sprintf('%02d:%02d–%02d:%02d', $group['startHour'], $group['startMinute'], $group['endHour'], $group['endMinute'])) ?></td>
                    <td><span class="badge badge-<?= htmlspecialchars($group['workMode']) ?>"><?= htmlspecialchars($group['workMode']) ?></span></td>
                    <td><?= htmlspecialchars($daySchedule['explanations'][$i] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="muted">No force charge/discharge slots recommended — the whole day stays on SelfUse.</p>
        <?php endif; ?>
        <?php endforeach; endif; ?>
    </fieldset>
<?php endforeach; ?>
</div>

<?php
renderFooter();
