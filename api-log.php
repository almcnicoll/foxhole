<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';
require_once __DIR__ . '/src/Store.php';

requireLogin();

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');

const API_LOG_PAGE_SIZE = 50;

$totalEntries = countApiLogEntries();
$totalPages = max(1, (int) ceil($totalEntries / API_LOG_PAGE_SIZE));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$entries = getApiLogEntries(API_LOG_PAGE_SIZE, ($page - 1) * API_LOG_PAGE_SIZE);

/**
 * Every FoxESS call either succeeds outright (HTTP 200, errno 0), fails at the network
 * level (no status code at all), or comes back HTTP 200 with a non-zero `errno` — FoxESS
 * wraps logical/business errors inside a 200 response rather than a non-2xx status, so
 * colouring by HTTP status alone would show green for most real failures. "Device
 * offline" (errno 41935) is downgraded to a warning rather than an error, same as
 * Runner.php's isOfflineFailure() treats it elsewhere — it's routine for a battery-less
 * inverter overnight, not a genuine problem.
 *
 * Once a body is redacted (see Store::saveApiLogEntry()'s 7-day retention rule), errno is
 * no longer recoverable — this falls back to the coarser HTTP-status-only judgement for
 * those entries, which is an accepted trade-off of that rule, not a bug.
 */
function apiLogLevel(?int $statusCode, ?string $responseBody): string
{
    if ($statusCode === null || $statusCode !== 200) {
        return 'error';
    }
    if ($responseBody === null) {
        return 'success';
    }
    $decoded = json_decode($responseBody, true);
    $errno = is_array($decoded) ? ($decoded['errno'] ?? 0) : 0;
    if ($errno === 0) {
        return 'success';
    }
    return str_contains((string) ($decoded['msg'] ?? ''), 'Device offline') ? 'warning' : 'error';
}

function apiLogStatusLabel(?int $statusCode): string
{
    return $statusCode === null ? 'no response' : (string) $statusCode;
}

/** Pretty-prints a stored JSON body for readability; leaves non-JSON text (e.g. a cURL error) as-is. */
function apiLogFormatBody(?string $body): ?string
{
    if ($body === null) {
        return null;
    }
    $decoded = json_decode($body, true);
    return $decoded !== null ? json_encode($decoded, JSON_PRETTY_PRINT) : $body;
}

renderHeader('API log');
?>

<p class="muted">
    Every request this app sends to the FoxESS API, most recent first — endpoint, what was sent, and what came
    back. Request/response bodies older than 7 days are automatically cleared to keep the log small; the call
    itself (time, endpoint, status) is kept indefinitely.
</p>

<?php if (!$entries): ?>
<p class="muted">No API calls logged yet.</p>
<?php else: ?>

<?php foreach ($entries as $entry): $level = apiLogLevel($entry['status_code'], $entry['response_body']); ?>
<details class="api-log-entry">
    <summary class="api-log-summary">
        <span><?= htmlspecialchars($entry['called_at']->setTimezone($timezone)->format('D j M, H:i:s')) ?></span>
        <span class="api-log-endpoint"><?= htmlspecialchars($entry['endpoint']) ?></span>
        <span class="badge badge-<?= htmlspecialchars($level) ?>"><?= htmlspecialchars(apiLogStatusLabel($entry['status_code'])) ?></span>
    </summary>
    <div class="api-log-body">
        <?php if ($entry['request_body'] === null && $entry['response_body'] === null): ?>
        <p class="muted">Request/response bodies for this call have been cleared (older than 7 days).</p>
        <?php else: ?>
        <p class="muted">Request body</p>
        <pre><?= htmlspecialchars(apiLogFormatBody($entry['request_body']) ?? '(none)') ?></pre>
        <p class="muted">Response body</p>
        <pre><?= htmlspecialchars(apiLogFormatBody($entry['response_body']) ?? '(none)') ?></pre>
        <?php endif; ?>
    </div>
</details>
<?php endforeach; ?>

<div class="history-nav">
    <?php if ($page > 1): ?>
    <a href="?page=<?= $page - 1 ?>">&larr; Newer</a>
    <?php else: ?>
    <span></span>
    <?php endif; ?>
    <strong>Page <?= $page ?> of <?= $totalPages ?> (<?= $totalEntries ?> calls logged)</strong>
    <?php if ($page < $totalPages): ?>
    <a href="?page=<?= $page + 1 ?>">Older &rarr;</a>
    <?php else: ?>
    <span></span>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php
renderFooter();
