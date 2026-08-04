<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (verifySystemPassword((string) ($_POST['password'] ?? ''))) {
        login();
        header('Location: index.php');
        exit;
    }
    $error = 'Incorrect password.';
}

renderHeader('Log in', showNav: false);
?>
<?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
<form method="post">
  <label for="password">System password</label>
  <input type="password" id="password" name="password" autofocus required>
  <button type="submit">Log in</button>
</form>
<?php
renderFooter();
