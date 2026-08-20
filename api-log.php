<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';
require_once __DIR__ . '/src/Store.php';

requireLogin();

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');

const API_LOG_PAGE_SIZE = 50;
const API_LOG_LEVELS = ['success', 'warning', 'error'];

// GitHub issue #8: two independent, combinable filters. Status is a real stored column, so
// it's pushed into SQL (getApiLogEntries()/countApiLogEntries()'s new optional params) —
// 'none' is a sentinel for "no response at all" (status_code IS NULL, a transport-level
// failure), distinct from any real HTTP status. Level (error/warning/success) is derived at
// render time by apiLogLevel() below, not a stored column, so it can't be pushed into SQL
// without duplicating that function's logic — see getAllApiLogEntriesForLevelFilter()'s own
// doc comment for why that case fetches everything matching the status filter and paginates
// in PHP instead.
$statusParam = (string) ($_GET['status'] ?? '');
$noResponseOnly = $statusParam === 'none';
$statusCodeFilter = (!$noResponseOnly && $statusParam !== '' && ctype_digit($statusParam)) ? (int) $statusParam : null;
$levelFilter = in_array($_GET['level'] ?? '', API_LOG_LEVELS, true) ? (string) $_GET['level'] : null;

if ($levelFilter !== null) {
    $levelFiltered = array_values(array_filter(
        getAllApiLogEntriesForLevelFilter($statusCodeFilter, $noResponseOnly),
        fn($e) => apiLogLevel($e['status_code'], $e['response_body']) === $levelFilter,
    ));
    $totalEntries = count($levelFiltered);
    $totalPages = max(1, (int) ceil($totalEntries / API_LOG_PAGE_SIZE));
    $page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
    $entries = array_slice($levelFiltered, ($page - 1) * API_LOG_PAGE_SIZE, API_LOG_PAGE_SIZE);
} else {
    $totalEntries = countApiLogEntries($statusCodeFilter, $noResponseOnly);
    $totalPages = max(1, (int) ceil($totalEntries / API_LOG_PAGE_SIZE));
    $page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
    $entries = getApiLogEntries(API_LOG_PAGE_SIZE, ($page - 1) * API_LOG_PAGE_SIZE, $statusCodeFilter, $noResponseOnly);
}

$anyFilterActive = $statusCodeFilter !== null || $noResponseOnly || $levelFilter !== null;
$hasAnyLoggedAtAll = $anyFilterActive ? countApiLogEntries() > 0 : $totalEntries > 0;
$distinctStatuses = getDistinctApiLogStatusCodes();
$hasNoResponseEntries = hasApiLogNoResponseEntries();

function apiLogStatusLabel(?int $statusCode): string
{
    return $statusCode === null ? 'no response' : (string) $statusCode;
}

/** Newer/Older pagination links need to carry whatever status/level filter is currently active, or paging would silently drop it. */
function apiLogPageLink(int $page, string $statusParam, ?string $levelFilter): string
{
    return '?' . http_build_query(array_filter([
        'page' => $page,
        'status' => $statusParam,
        'level' => $levelFilter,
    ], fn($v) => $v !== null && $v !== ''));
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

<?php if ($hasAnyLoggedAtAll): ?>
<form method="get" class="api-log-filters">
    <label for="filter-status">Status</label>
    <select id="filter-status" name="status" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($distinctStatuses as $code): ?>
        <option value="<?= $code ?>" <?= $statusCodeFilter === $code ? 'selected' : '' ?>><?= $code ?></option>
        <?php endforeach; ?>
        <?php if ($hasNoResponseEntries): ?>
        <option value="none" <?= $noResponseOnly ? 'selected' : '' ?>>No response</option>
        <?php endif; ?>
    </select>
    <label for="filter-level">Level</label>
    <select id="filter-level" name="level" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach (API_LOG_LEVELS as $levelOption): ?>
        <option value="<?= $levelOption ?>" <?= $levelFilter === $levelOption ? 'selected' : '' ?>><?= ucfirst($levelOption) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($anyFilterActive): ?>
    <a href="api-log.php">Clear filters</a>
    <?php endif; ?>
</form>
<?php endif; ?>

<?php if (!$hasAnyLoggedAtAll): ?>
<p class="muted">No API calls logged yet.</p>
<?php elseif (!$entries): ?>
<p class="muted">No API calls match this filter.</p>
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
    <a href="<?= htmlspecialchars(apiLogPageLink($page - 1, $statusParam, $levelFilter)) ?>">&larr; Newer</a>
    <?php else: ?>
    <span></span>
    <?php endif; ?>
    <strong>Page <?= $page ?> of <?= $totalPages ?> (<?= $totalEntries ?> calls<?= $anyFilterActive ? ' matching this filter' : ' logged' ?>)</strong>
    <?php if ($page < $totalPages): ?>
    <a href="<?= htmlspecialchars(apiLogPageLink($page + 1, $statusParam, $levelFilter)) ?>">Older &rarr;</a>
    <?php else: ?>
    <span></span>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php
renderFooter();
