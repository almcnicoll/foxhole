<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Runner.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: history.php');
    exit;
}

// Manual equivalent of the generation-history fetch runScheduler() already does on every
// real run (see Runner.php/HistoryFetcher.php) — same function, same login gate/POST-only
// guard as run-now.php, just without the rest of the scheduling pipeline around it. Mainly
// useful for nudging the backward backfill along faster than once/day, by clicking
// repeatedly — each call only walks HISTORY_BACKWARD_BACKFILL_MAX_DAYS_PER_CALL further back.
$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$logger = new Logger(__DIR__ . '/logs/scheduler.log');
$result = fetchGenerationHistory($config, $logger);

header('Location: history.php?' . http_build_query([
    'fetched' => '1',
    'ok' => $result['ok'] ? '1' : '0',
    'msg' => $result['message'],
]));
