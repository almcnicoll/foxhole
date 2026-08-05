<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';
require_once __DIR__ . '/src/Runner.php';

requireLogin();

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');
$today = new DateTimeImmutable('today', $timezone);
$dates = ['today' => $today, 'tomorrow' => $today->modify('+1 day')];
$kinds = ['fill_your_boots' => 'Fill your boots', 'power_down' => 'Power down'];

pruneOldOverrides($today->format('Y-m-d'));

$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($dates as $which => $date) {
        $forDate = $date->format('Y-m-d');
        foreach ($kinds as $kind => $label) {
            $prefix = "{$which}_{$kind}_";
            $eventStart = trim((string) ($_POST[$prefix . 'event_start'] ?? ''));
            $eventEnd = trim((string) ($_POST[$prefix . 'event_end'] ?? ''));
            $prepStart = trim((string) ($_POST[$prefix . 'prep_start'] ?? ''));
            $prepEnd = trim((string) ($_POST[$prefix . 'prep_end'] ?? ''));

            // Both event fields blank means "clear this override" — the one way to remove one.
            if ($eventStart === '' && $eventEnd === '') {
                deleteOverride($forDate, $kind);
                continue;
            }
            if ($eventStart === '' || $eventEnd === '' || $eventEnd <= $eventStart) {
                $errors[] = "$label ($which): event start and end must both be set, with end after start.";
                continue;
            }
            if (($prepStart === '') !== ($prepEnd === '')) {
                $errors[] = "$label ($which): prepare start and end must both be set, or both left blank.";
                continue;
            }
            if ($prepStart !== '' && $prepEnd <= $prepStart) {
                $errors[] = "$label ($which): prepare end must be after prepare start.";
                continue;
            }
            saveOverride($forDate, $kind, $eventStart, $eventEnd, $prepStart !== '' ? $prepStart : null, $prepEnd !== '' ? $prepEnd : null);
        }
    }

    if (!$errors) {
        $result = reapplyOverrides();
        $notice = $result['message'];
        if (!$result['ok']) {
            $errors[] = $result['message'];
            $notice = null;
        }
    }
}

$overridesByDate = [];
foreach ($dates as $which => $date) {
    $overridesByDate[$which] = [];
    foreach (getOverridesForDate($date->format('Y-m-d')) as $row) {
        $overridesByDate[$which][$row['kind']] = $row;
    }
}

renderHeader('Override');
?>

<?php foreach ($errors as $error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endforeach; ?>
<?php if ($notice): ?><p class="notice"><?= htmlspecialchars($notice) ?></p><?php endif; ?>

<p class="muted">
  Octopus sometimes runs "Fill your boots" (use more power) or "Power down" (use less) events.
  Set the event window here and, optionally, a period beforehand to prepare the battery for it.
  Overrides only apply to the date they're set for and don't carry over to the next day.
</p>

<form method="post">
  <?php foreach ($dates as $which => $date): ?>
    <fieldset>
      <legend><?= htmlspecialchars(ucfirst($which) . ' (' . $date->format('D j M') . ')') ?></legend>
      <?php foreach ($kinds as $kind => $label):
          $existing = $overridesByDate[$which][$kind] ?? null;
          $prefix = "{$which}_{$kind}_";
          $prepAction = $kind === 'fill_your_boots' ? 'discharge' : 'charge';
          // Clears the 4 inputs client-side only — still needs "Save overrides" to actually
          // delete it (blank event fields = delete, see the POST handling above), so this
          // reuses that existing path rather than adding a second way to remove an override.
          $clearJs = sprintf(
              "document.getElementById('%s').value='';document.getElementById('%s').value='';document.getElementById('%s').value='';document.getElementById('%s').value='';",
              $prefix . 'event_start',
              $prefix . 'event_end',
              $prefix . 'prep_start',
              $prefix . 'prep_end',
          );
      ?>
        <fieldset>
          <legend><?= htmlspecialchars($label) ?> <button type="button" class="btn-clear" onclick="<?= htmlspecialchars($clearJs) ?>">Clear</button></legend>
          <label for="<?= $prefix ?>event_start">Event start</label>
          <input type="time" id="<?= $prefix ?>event_start" name="<?= $prefix ?>event_start" value="<?= htmlspecialchars($existing['event_start'] ?? '') ?>">
          <label for="<?= $prefix ?>event_end">Event end</label>
          <input type="time" id="<?= $prefix ?>event_end" name="<?= $prefix ?>event_end" value="<?= htmlspecialchars($existing['event_end'] ?? '') ?>">
          <label for="<?= $prefix ?>prep_start">Prepare from (optional — force <?= $prepAction ?>)</label>
          <input type="time" id="<?= $prefix ?>prep_start" name="<?= $prefix ?>prep_start" value="<?= htmlspecialchars($existing['prep_start'] ?? '') ?>">
          <label for="<?= $prefix ?>prep_end">Prepare until</label>
          <input type="time" id="<?= $prefix ?>prep_end" name="<?= $prefix ?>prep_end" value="<?= htmlspecialchars($existing['prep_end'] ?? '') ?>">
        </fieldset>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>
  <button type="submit">Save overrides</button>
</form>

<?php
renderFooter();
