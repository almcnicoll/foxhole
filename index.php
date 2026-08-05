<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';
require_once __DIR__ . '/src/FoxessClient.php';

requireLogin();

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');

$slots = getLatestRateSlots();
$schedule = getLatestSchedule();

// Live battery state, one call per configured inverter — best-effort, the rest of the
// dashboard is all local DB reads and shouldn't break if FoxESS is slow or unreachable.
$apiKey = getSetting('foxess_api_key', '');
$deviceSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('foxess_device_sns', '')))));
$batterySocs = []; // device serial => percent (0-100) or null if unavailable
if ($apiKey !== '' && $deviceSns) {
    $baseUrl = $config['foxess']['base_url'] ?? 'https://www.foxesscloud.com';
    foreach ($deviceSns as $sn) {
        try {
            $batterySocs[$sn] = (new FoxessClient($apiKey, $sn, $baseUrl))->getBatterySoc();
        } catch (FoxessPushException $e) {
            $batterySocs[$sn] = null;
        }
    }
}

/** A group's endHour of 0 means "midnight", i.e. end of day — not the first minute of it. */
function slotWorkMode(int $slotMinutes, array $groups): string
{
    foreach ($groups as $g) {
        $start = $g['startHour'] * 60 + $g['startMinute'];
        $end = $g['endHour'] * 60 + $g['endMinute'];
        if ($end === 0) {
            $end = 24 * 60;
        }
        if ($slotMinutes >= $start && $slotMinutes < $end) {
            return $g['workMode'];
        }
    }
    return 'SelfUse';
}

function renderSlotTable(array $slotsForColumn, DateTimeZone $timezone, array $groups): void
{
    ?>
    <table>
      <thead><tr><th>Time</th><th>Import (p/kWh)</th><th>Export (p/kWh)</th><th>Mode</th></tr></thead>
      <tbody>
      <?php foreach ($slotsForColumn as $slot):
          $localFrom = $slot['from']->setTimezone($timezone);
          $localTo = $slot['to']->setTimezone($timezone);
          $slotMinutes = ((int) $localFrom->format('G')) * 60 + (int) $localFrom->format('i');
          $mode = slotWorkMode($slotMinutes, $groups);
      ?>
        <tr>
          <td><?= htmlspecialchars($localFrom->format('H:i')) ?>–<?= htmlspecialchars($localTo->format('H:i')) ?></td>
          <td><?= htmlspecialchars(number_format($slot['import_rate'], 2)) ?></td>
          <td><?= $slot['export_rate'] !== null ? htmlspecialchars(number_format($slot['export_rate'], 2)) : '—' ?></td>
          <td><span class="badge badge-<?= htmlspecialchars($mode) ?>"><?= htmlspecialchars($mode) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

/** @param array<string, ?float> $batterySocs device serial => percent, or null if unavailable */
function renderBatteryStatus(array $batterySocs): string
{
    if (!$batterySocs) {
        return '';
    }
    $multiple = count($batterySocs) > 1;
    $i = 0;
    $items = '';
    foreach ($batterySocs as $soc) {
        $i++;
        $label = htmlspecialchars($multiple ? "Battery $i" : 'Battery');
        if ($soc === null) {
            $items .= "<div class=\"battery-item\"><span class=\"battery-label\">$label:</span><span class=\"muted\">unavailable</span></div>";
        } else {
            $pct = (int) round($soc);
            $items .= "<div class=\"battery-item\"><span class=\"battery-label\">$label</span><progress value=\"$pct\" max=\"100\"></progress><span>$pct%</span></div>";
        }
    }
    return "<div class=\"battery-status\">$items</div>";
}

renderHeader('Dashboard', headerExtra: renderBatteryStatus($batterySocs));

$ran = $_GET['ran'] ?? null;
$ranOk = ($_GET['ok'] ?? null) === '1';
$ranMsg = (string) ($_GET['msg'] ?? '');
?>

<?php if ($ran): ?>
  <p class="<?= $ranOk ? 'notice' : 'error' ?>"><?= htmlspecialchars($ranMsg) ?></p>
<?php endif; ?>

<form method="post" action="run-now.php">
  <button type="submit">Run now</button>
</form>

<?php if (!$slots): ?>
  <p class="muted">No rates fetched yet — run.php hasn't completed a successful fetch.</p>
<?php else: ?>
  <p class="muted">
    Rates last fetched <?= htmlspecialchars($slots[0]['fetched_at']->setTimezone($timezone)->format('D j M, H:i')) ?>.
    <?php if ($schedule['pushed_at']): ?>
      Schedule for <?= htmlspecialchars((string) $schedule['for_date']) ?> pushed
      <?= htmlspecialchars($schedule['pushed_at']->setTimezone($timezone)->format('D j M, H:i')) ?>.
    <?php else: ?>
      No schedule pushed yet.
    <?php endif; ?>
  </p>

  <?php if ($schedule['explanations']): ?>
    <h2>Why these decisions?</h2>
    <?php $summary = getSetting('schedule_summary'); ?>
    <?php if ($summary): ?><p class="muted"><?= htmlspecialchars($summary) ?></p><?php endif; ?>
    <ul>
      <?php foreach ($schedule['explanations'] as $explanation): ?>
        <li><?= htmlspecialchars((string) $explanation) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php
  $leftSlots = [];
  $rightSlots = [];
  foreach ($slots as $slot) {
      if ((int) $slot['from']->setTimezone($timezone)->format('G') < 12) {
          $leftSlots[] = $slot;
      } else {
          $rightSlots[] = $slot;
      }
  }
  ?>
  <div class="slot-columns">
    <?php renderSlotTable($leftSlots, $timezone, $schedule['groups']); ?>
    <?php renderSlotTable($rightSlots, $timezone, $schedule['groups']); ?>
  </div>
<?php endif; ?>

<?php
renderFooter();
