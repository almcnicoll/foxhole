<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';

requireLogin();

$errors = [];
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $apiKey = trim((string) ($_POST['api_key'] ?? ''));
    $deviceSn = trim((string) ($_POST['device_sn'] ?? ''));
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($apiKey === '') {
        $errors[] = 'API key is required.';
    }
    if ($deviceSn === '') {
        $errors[] = 'Device serial number is required.';
    }
    if ($newPassword !== '' || $confirmPassword !== '') {
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'New password and confirmation do not match.';
        } elseif (strlen($newPassword) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        }
    }

    if (!$errors) {
        setSetting('foxess_api_key', $apiKey);
        setSetting('foxess_device_sn', $deviceSn);
        if ($newPassword !== '') {
            setSystemPassword($newPassword);
        }
        $notice = 'Settings saved.';
    }
} else {
    $apiKey = getSetting('foxess_api_key', '');
    $deviceSn = getSetting('foxess_device_sn', '');
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
    <label for="device_sn">Device serial number</label>
    <input type="text" id="device_sn" name="device_sn" value="<?= htmlspecialchars($deviceSn) ?>" required>
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

<?php
renderFooter();
