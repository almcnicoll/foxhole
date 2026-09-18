<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Store.php';

requireLogin();

// GET, not POST — this is a read-only export, same trust level as api-log.php/history.php
// itself, no state changes to guard against a stray link/prefetch for.
$mode = ($_GET['mode'] ?? '') === 'calls' ? 'calls' : 'days';
if ($mode === 'calls') {
    $n = max(1, min(API_LOG_LEVEL_FILTER_MAX_ROWS, (int) ($_GET['calls'] ?? 100)));
    $entries = getApiLogEntries($n);
    $windowLabel = "last $n calls";
} else {
    $n = max(1, min(365, (int) ($_GET['days'] ?? 7)));
    $entries = getApiLogEntriesSince((new DateTimeImmutable('now'))->modify("-$n days"));
    $windowLabel = "last $n day" . ($n === 1 ? '' : 's');
}

/**
 * Request/response bodies are stored as JSON text (or null, once redacted past 7 days —
 * see saveApiLogEntry()) — decoded here so the export nests them as real JSON objects
 * rather than a JSON string within a string, which is what "bearing in mind the payload
 * of each call will be JSON" asks for: a tool like `jq` can query straight into a body
 * field without a second parse step. A body that isn't valid JSON (a transport failure's
 * cURL error text, or api.backend.octopus.energy's plain-HTML 403 page — see
 * OctopusFlexClient's own doc comment) is kept as the raw string instead of erroring.
 */
function apiLogDownloadBody(?string $body)
{
    if ($body === null) {
        return null;
    }
    $decoded = json_decode($body, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
}

$export = array_map(fn(array $e) => [
    'called_at' => $e['called_at']->format(DATE_ATOM),
    'endpoint' => $e['endpoint'],
    'status_code' => $e['status_code'],
    'request_body' => apiLogDownloadBody($e['request_body']),
    'response_body' => apiLogDownloadBody($e['response_body']),
], $entries);

$filename = sprintf('foxhole-api-log-%s-%s.json', $mode === 'calls' ? "{$n}calls" : "{$n}days", (new DateTimeImmutable('now'))->format('Ymd-His'));

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode([
    'exported_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
    'window' => $windowLabel,
    'count' => count($export),
    'calls' => $export,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
