<?php

/** $headerExtra is raw HTML (caller escapes any dynamic text itself), rendered top-right of the title. */
function renderHeader(string $title, bool $showNav = true, string $headerExtra = ''): void
{
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> — Foxhole</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if ($showNav): ?>
<nav>
  <a href="index.php">Dashboard</a>
  <a href="override.php">Override</a>
  <a href="settings.php">Settings</a>
  <a href="logout.php">Log out</a>
</nav>
<?php endif; ?>
<div class="page-header">
  <h1><?= htmlspecialchars($title) ?></h1>
  <?= $headerExtra ?>
</div>
    <?php
}

function renderFooter(): void
{
    ?>
</body>
</html>
    <?php
}
