<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Runner.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$result = runScheduler(false);

header('Location: index.php?' . http_build_query([
    'ran' => '1',
    'ok' => $result['ok'] ? '1' : '0',
    'msg' => $result['message'],
]));
