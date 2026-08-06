<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Runner.php';

// Web-triggered equivalent of run.php, for hosts where cron can't invoke the PHP
// CLI. Gated by a random per-install secret token (settings.php) instead of a
// session login, since a scripted cron client can't do interactive login — the
// token itself is the authentication, so this is GET (wget-friendly), unlike
// run-now.php's POST-only guard (which exists specifically because *that* endpoint
// has no secret of its own, just a session).
$token = getSetting('cron_token', '');
if ($token === '' || !hash_equals($token, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden');
}

$result = runScheduler(false);
http_response_code($result['ok'] ? 200 : 500);
echo $result['message'] . PHP_EOL;
