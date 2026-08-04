<?php

declare(strict_types=1);

// Cron-only: $argv (used just below) doesn't exist outside the CLI SAPI. If
// the web server ever routes a request here — misconfiguration, a bot
// probing for it — refuse cleanly instead of a 500 (and instead of letting
// an unauthenticated request trigger a real push). For a web-triggered run,
// see run-now.php, which is login-gated instead of CLI-gated.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('run.php runs from cron only, not the web — use the "Run now" button in the dashboard instead.');
}

require_once __DIR__ . '/src/Runner.php';

$dryRun = in_array('--dry-run', $argv, true);
$result = runScheduler($dryRun);

if ($result['ok'] && $dryRun) {
    echo json_encode($result['schedule'], JSON_PRETTY_PRINT) . PHP_EOL;
} elseif (!$result['ok']) {
    fwrite(STDERR, $result['message'] . PHP_EOL);
}

exit($result['ok'] ? 0 : 1);
