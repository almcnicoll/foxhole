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

<?php /* Collapsed by default — it's reference text, and on a phone it pushed the form off-screen. */ ?>
<details class="accordion">
  <summary><h3>Instructions</h3></summary>
  <p class="muted">
    Octopus sometimes runs "Fill your boots" (use more power) or "Power down" (use less) events.
    Set the event window here and, optionally, a period beforehand to prepare the battery for it —
    the "Discharge" times (Fill your boots) or "Charge" times (Power down) force the battery that
    way first. Entering a start time fills in an end an hour later if the end is still blank; change
    it if the window is a different length.
    Overrides only apply to the date they're set for and don't carry over to the next day.
  </p>
</details>

<form method="post" class="override-form">
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
          <div class="time-range">
            <div class="time-field">
              <label for="<?= $prefix ?>event_start">Event start</label>
              <input type="time" id="<?= $prefix ?>event_start" name="<?= $prefix ?>event_start" value="<?= htmlspecialchars($existing['event_start'] ?? '') ?>" data-autofill-end="<?= $prefix ?>event_end">
            </div>
            <div class="time-field">
              <label for="<?= $prefix ?>event_end">Event end</label>
              <input type="time" id="<?= $prefix ?>event_end" name="<?= $prefix ?>event_end" value="<?= htmlspecialchars($existing['event_end'] ?? '') ?>">
            </div>
          </div>
          <div class="time-range">
            <div class="time-field">
              <label for="<?= $prefix ?>prep_start" title="Optional — forces a <?= $prepAction ?> beforehand"><?= ucfirst($prepAction) ?> from</label>
              <input type="time" id="<?= $prefix ?>prep_start" name="<?= $prefix ?>prep_start" value="<?= htmlspecialchars($existing['prep_start'] ?? '') ?>" data-autofill-end="<?= $prefix ?>prep_end">
            </div>
            <div class="time-field">
              <label for="<?= $prefix ?>prep_end"><?= ucfirst($prepAction) ?> until</label>
              <input type="time" id="<?= $prefix ?>prep_end" name="<?= $prefix ?>prep_end" value="<?= htmlspecialchars($existing['prep_end'] ?? '') ?>">
            </div>
          </div>
        </fieldset>
      <?php endforeach; ?>
    </fieldset>
  <?php endforeach; ?>
  <button type="submit">Save overrides</button>
</form>

<script>
// Entering a start time fills in an end an hour later — for all eight start/end pairs
// (event + prepare, x 2 kinds, x 2 dates), each wired by its start input's data-autofill-end.
// Only ever fills an end that's blank, or one this script itself filled and the user hasn't
// since touched (so retyping the start's minutes mid-entry doesn't leave a stale end behind);
// a manually-entered or already-saved end is never overwritten. Clamped to 23:59, since an
// override window can't span midnight.
document.querySelectorAll('input[data-autofill-end]').forEach(function (start) {
    var end = document.getElementById(start.dataset.autofillEnd);
    var autoFilled = false;
    end.addEventListener('input', function () { autoFilled = false; });
    function fill() {
        if (start.value === '' || (end.value !== '' && !autoFilled)) {
            return;
        }
        var parts = start.value.split(':');
        var minutes = Math.min(parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10) + 60, 23 * 60 + 59);
        end.value = ('0' + Math.floor(minutes / 60)).slice(-2) + ':' + ('0' + (minutes % 60)).slice(-2);
        autoFilled = true;
    }
    start.addEventListener('input', fill);
    start.addEventListener('change', fill);
});
</script>

<?php
renderFooter();
