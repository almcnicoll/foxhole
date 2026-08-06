<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';

requireLogin();

$errors = [];
$notice = null;

$priceKinds = ['import' => ['api', '0'], 'export' => ['fixed', '12']];

// Independent of the main form below — its own button, so saving other settings can
// never accidentally rotate the token, and rotating it doesn't require re-filling the
// rest of the form. Redirects back (POST-redirect-GET, same as run-now.php) so a page
// refresh doesn't re-submit and generate a second new token.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['regenerate_cron_token'])) {
    setSetting('cron_token', bin2hex(random_bytes(24)));
    header('Location: settings.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = trim((string) ($_POST['api_key'] ?? ''));
    $deviceSnsRaw = (string) ($_POST['device_sns'] ?? '');
    $deviceSns = array_values(array_filter(array_map('trim', explode("\n", $deviceSnsRaw))));
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    $priceModes = [];
    $priceFixed = [];
    foreach ($priceKinds as $kind => [$defaultMode, $defaultFixed]) {
        $mode = ($_POST["{$kind}_price_mode"] ?? $defaultMode) === 'fixed' ? 'fixed' : 'api';
        $fixed = trim((string) ($_POST["{$kind}_price_fixed_pence"] ?? ''));
        $priceModes[$kind] = $mode;
        $priceFixed[$kind] = $fixed;
        if ($mode === 'fixed' && (!is_numeric($fixed) || (float) $fixed < 0)) {
            $errors[] = ucfirst($kind) . ' fixed price must be a non-negative number.';
        }
    }

    if ($apiKey === '') {
        $errors[] = 'API key is required.';
    }
    if (!$deviceSns) {
        $errors[] = 'At least one device serial number is required.';
    }
    if ($newPassword !== '' || $confirmPassword !== '') {
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }
    }

    $solarEnabled = isset($_POST['solar_enabled']);
    $solarFields = ['latitude', 'longitude', 'declination', 'azimuth', 'kwp'];
    $solarValues = [];
    foreach ($solarFields as $field) {
        $solarValues[$field] = trim((string) ($_POST["solar_{$field}"] ?? ''));
    }
    if ($solarEnabled) {
        foreach ($solarFields as $field) {
            if (!is_numeric($solarValues[$field])) {
                $errors[] = 'Solar ' . $field . ' must be a number.';
            }
        }
    }

    $intelligentSchedulerEnabled = isset($_POST['intelligent_scheduler_enabled']);

    if (!$errors) {
        setSetting('foxess_api_key', $apiKey);
        setSetting('foxess_device_sns', implode("\n", $deviceSns));
        foreach ($priceKinds as $kind => [$defaultMode, $defaultFixed]) {
            setSetting("{$kind}_price_mode", $priceModes[$kind]);
            if ($priceModes[$kind] === 'fixed') {
                setSetting("{$kind}_price_fixed_pence", (string) (float) $priceFixed[$kind]);
            }
        }
        setSetting('solar_enabled', $solarEnabled ? '1' : '0');
        foreach ($solarFields as $field) {
            if ($solarValues[$field] !== '') {
                setSetting("solar_{$field}", (string) (float) $solarValues[$field]);
            }
        }
        setSetting('intelligent_scheduler_enabled', $intelligentSchedulerEnabled ? '1' : '0');
        if ($newPassword !== '') {
            setSystemPassword($newPassword);
        }
        $notice = 'Settings saved.';
    }
} else {
    $apiKey = getSetting('foxess_api_key', '');
    // foxess_device_sn (singular) is the pre-multi-inverter key — fall back to it once,
    // purely so an existing single value shows up as a starting point instead of a blank
    // box the first time this page loads after the upgrade.
    $deviceSnsRaw = getSetting('foxess_device_sns') ?? getSetting('foxess_device_sn', '');
    $priceModes = [];
    $priceFixed = [];
    foreach ($priceKinds as $kind => [$defaultMode, $defaultFixed]) {
        $priceModes[$kind] = getSetting("{$kind}_price_mode", $defaultMode);
        $priceFixed[$kind] = getSetting("{$kind}_price_fixed_pence", $defaultFixed);
    }
    $solarEnabled = getSetting('solar_enabled', '0') === '1';
    $solarFields = ['latitude', 'longitude', 'declination', 'azimuth', 'kwp'];
    $solarValues = [];
    foreach ($solarFields as $field) {
        $solarValues[$field] = getSetting("solar_{$field}", '');
    }
    // Default on — see CLAUDE.md's ScheduleBuilder extension point note.
    $intelligentSchedulerEnabled = getSetting('intelligent_scheduler_enabled', '1') === '1';
}

// Generated on first view rather than requiring an explicit setup step — same
// zero-friction pattern as the rest of this app's "on by default" settings. Read
// unconditionally (not just on GET) since the form below always needs a value to
// display, including when the main save above just ran or hit a validation error.
$cronToken = getSetting('cron_token');
if ($cronToken === null) {
    $cronToken = bin2hex(random_bytes(24));
    setSetting('cron_token', $cronToken);
}

renderHeader('Settings');
?>

<?php foreach ($errors as $error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endforeach; ?>
<?php if ($notice): ?><p class="notice"><?= htmlspecialchars($notice) ?></p><?php endif; ?>

<form method="post">
    <fieldset>
        <legend>FoxESS API</legend>
        <label for="api_key">API key</label>
        <input type="text" id="api_key" name="api_key" value="<?= htmlspecialchars($apiKey) ?>" required>
        <label for="device_sns">Device serial numbers (one per line — the same schedule is pushed to each)</label>
        <textarea id="device_sns" name="device_sns" rows="3" required><?= htmlspecialchars($deviceSnsRaw) ?></textarea>
    </fieldset>

    <fieldset>
        <legend>Energy prices</legend>
        <p class="muted">"Fixed price" ignores Octopus and uses the value below for every half-hour slot.</p>
        <?php foreach (['import' => 'Import (purchase)', 'export' => 'Export (sale)'] as $kind => $label): ?>
        <label for="<?= $kind ?>_price_mode"><?= htmlspecialchars($label) ?> price source</label>
        <select id="<?= $kind ?>_price_mode" name="<?= $kind ?>_price_mode">
            <option value="api" <?= $priceModes[$kind] === 'api' ? 'selected' : '' ?>>Octopus Agile (API)</option>
            <option value="fixed" <?= $priceModes[$kind] === 'fixed' ? 'selected' : '' ?>>Fixed price</option>
        </select>
        <label for="<?= $kind ?>_price_fixed_pence">Fixed <?= $kind ?> price (p/kWh)</label>
        <input type="number" step="0.01" min="0" id="<?= $kind ?>_price_fixed_pence"
            name="<?= $kind ?>_price_fixed_pence" value="<?= htmlspecialchars($priceFixed[$kind]) ?>">
        <?php endforeach; ?>
    </fieldset>

    <fieldset>
        <legend>Scheduler</legend>
        <label for="intelligent_scheduler_enabled">
            <input type="checkbox" id="intelligent_scheduler_enabled" name="intelligent_scheduler_enabled"
                <?= $intelligentSchedulerEnabled ? 'checked' : '' ?>>
            Use the intelligent scheduler
        </label>
        <p class="muted">Simulates battery charge through the day using the solar forecast below, an estimated usage
            profile, and import/export price, instead of a flat price-threshold rule. Uncheck to fall back to the
            simpler price-only scheduler. <code>run.php</code> can also override this per-run with
            <code>--classic</code> or <code>--intelligent</code>.
        </p>
    </fieldset>

    <fieldset>
        <legend>Solar forecast</legend>
        <p class="muted">Retrieved from <a href="https://forecast.solar" target="_blank"
                rel="noopener">Forecast.Solar</a> (free, no API key) and shown on the dashboard. Feeds the intelligent
            scheduler above when enabled.</p>
        <label for="solar_enabled">
            <input type="checkbox" id="solar_enabled" name="solar_enabled" <?= $solarEnabled ? 'checked' : '' ?>>
            Enabled
        </label>
        <label for="solar_latitude">Latitude</label>
        <input type="number" step="any" id="solar_latitude" name="solar_latitude"
            value="<?= htmlspecialchars($solarValues['latitude']) ?>">
        <label for="solar_longitude">Longitude</label>
        <input type="number" step="any" id="solar_longitude" name="solar_longitude"
            value="<?= htmlspecialchars($solarValues['longitude']) ?>">
        <label for="solar_declination">Panel angle / declination (degrees from horizontal, 0-90)</label>
        <input type="number" step="any" min="0" max="90" id="solar_declination" name="solar_declination"
            value="<?= htmlspecialchars($solarValues['declination']) ?>">
        <label for="solar_azimuth">Azimuth (degrees from south, -180 to 180)</label>
        <input type="number" step="any" min="-180" max="180" id="solar_azimuth" name="solar_azimuth"
            value="<?= htmlspecialchars($solarValues['azimuth']) ?>">
        <label for="solar_kwp">Installed capacity (kWp)</label>
        <input type="number" step="any" min="0" id="solar_kwp" name="solar_kwp"
            value="<?= htmlspecialchars($solarValues['kwp']) ?>">
    </fieldset>

    <fieldset>
        <legend>System password</legend>
        <p class="muted">Leave both blank to keep the current password.</p>
        <label for="new_password">New password</label>
        <input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password">
        <label for="confirm_password">Confirm new password</label>
        <input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password">
    </fieldset>

    <button type="submit">Save</button>
</form>

<form method="post">
    <fieldset>
        <legend>Cron trigger</legend>
        <p class="muted">For hosts where cron can't invoke the PHP CLI — point your host's scheduler at
            <code class="code-block">cron.php?token=&lt;below&gt;</code> instead of running <code>run.php</code>
            directly,
            e.g. <code class="code-block">wget -O- "https://your-domain/cron.php?token=&hellip;"</code>
            Anyone
            with this token can trigger
            a real push — while they can't control the logic or change the parameters,
            you should keep it private, and regenerate it if it ever leaks.
        </p>
        <label for="cron_token">Token</label>
        <input type="text" id="cron_token" readonly value="<?= htmlspecialchars($cronToken) ?>" onclick="this.select()">
        <button type="submit" name="regenerate_cron_token" value="1">Regenerate</button>
    </fieldset>
</form>

<?php
renderFooter();