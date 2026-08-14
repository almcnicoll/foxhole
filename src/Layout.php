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
<?php
// Cache-busted via mtime, not a bare href — without this, a browser (or an intermediate
// cache) holding an older cached copy of style.css keeps using it indefinitely, since
// nothing about the URL ever changes across deploys. Confirmed live: a stale cache from
// before --color-generation existed left the history chart's generation bars rendering as
// plain black (the browser's fallback for an unresolved CSS var()) while --color-solar,
// present in that same older stylesheet, kept working — every deploy that touches
// style.css needs this to actually reach browsers with an already-cached copy.
$cssVersion = @filemtime(__DIR__ . '/../assets/style.css') ?: '1';
?>
<link rel="stylesheet" href="assets/style.css?v=<?= $cssVersion ?>">
</head>
<body>
<?php if ($showNav): ?>
<nav>
  <a href="index.php">Dashboard</a>
  <a href="override.php">Override</a>
  <a href="history.php">History</a>
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
