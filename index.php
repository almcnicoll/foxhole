<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';

requireLogin();

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');

$slots = getLatestRateSlots();
$schedule = getLatestSchedule();

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

renderHeader('Dashboard');
?>

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

  <table>
    <thead><tr><th>Time</th><th>Rate (p/kWh)</th><th>Mode</th></tr></thead>
    <tbody>
    <?php foreach ($slots as $slot):
        $localFrom = $slot['from']->setTimezone($timezone);
        $localTo = $slot['to']->setTimezone($timezone);
        $slotMinutes = ((int) $localFrom->format('G')) * 60 + (int) $localFrom->format('i');
        $mode = slotWorkMode($slotMinutes, $schedule['groups']);
    ?>
      <tr>
        <td><?= htmlspecialchars($localFrom->format('H:i')) ?>–<?= htmlspecialchars($localTo->format('H:i')) ?></td>
        <td><?= htmlspecialchars(number_format($slot['rate'], 2)) ?></td>
        <td><span class="badge badge-<?= htmlspecialchars($mode) ?>"><?= htmlspecialchars($mode) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php
renderFooter();
