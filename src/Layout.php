<?php

function renderHeader(string $title, bool $showNav = true): void
{
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($title) ?> — Foxhole</title>
<style>
  :root { color-scheme: light dark; }
  body { font-family: system-ui, sans-serif; max-width: 720px; margin: 2rem auto; padding: 0 1rem; line-height: 1.4; }
  nav { display: flex; gap: 1rem; margin-bottom: 1.5rem; font-size: 0.9rem; }
  nav a { text-decoration: none; }
  h1 { font-size: 1.3rem; }
  table { border-collapse: collapse; width: 100%; margin: 1rem 0; font-size: 0.9rem; }
  th, td { text-align: left; padding: 0.3rem 0.6rem; border-bottom: 1px solid #8883; }
  .badge { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 1rem; font-size: 0.8rem; }
  .badge-ForceCharge { background: #1a7f371a; color: #1a7f37; }
  .badge-ForceDischarge { background: #cf222e1a; color: #cf222e; }
  .badge-SelfUse { background: #6666661a; color: #666; }
  form { display: flex; flex-direction: column; gap: 0.8rem; max-width: 360px; }
  label { font-size: 0.85rem; font-weight: 600; }
  input, select, textarea { padding: 0.4rem; font-size: 1rem; font-family: inherit; }
  button { padding: 0.5rem; font-size: 1rem; cursor: pointer; }
  .error { color: #cf222e; }
  .notice { color: #1a7f37; }
  .muted { color: #888; font-size: 0.85rem; }
  fieldset { display: flex; flex-direction: column; gap: 0.6rem; border: 1px solid #8883; border-radius: 6px; padding: 0.8rem; }
</style>
</head>
<body>
<?php if ($showNav): ?>
<nav>
  <a href="index.php">Dashboard</a>
  <a href="settings.php">Settings</a>
  <a href="logout.php">Log out</a>
</nav>
<?php endif; ?>
<h1><?= htmlspecialchars($title) ?></h1>
    <?php
}

function renderFooter(): void
{
    ?>
</body>
</html>
    <?php
}
