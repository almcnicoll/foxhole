<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';
require_once __DIR__ . '/src/Store.php';

requireLogin();

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');

// $view/$date are always resolved server-side against the site's own configured
// timezone above, never the browser's — a date typed here means that calendar date at
// the solar panels, and <input type="date"> (used below) has no time-of-day component
// at all, so there's nothing for a browser timezone to even shift.
$view = in_array($_GET['view'] ?? '', ['day', 'week', 'month', 'year'], true) ? $_GET['view'] : 'day';
$dateParam = (string) ($_GET['date'] ?? '');
try {
    $anchor = $dateParam !== '' ? (new DateTimeImmutable($dateParam, $timezone))->setTime(0, 0) : new DateTimeImmutable('today', $timezone);
} catch (Exception $e) {
    $anchor = new DateTimeImmutable('today', $timezone);
}

/** @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [start, end) of the period containing $anchor */
function resolvePeriod(string $view, DateTimeImmutable $anchor): array
{
    return match ($view) {
        'day' => [$anchor, $anchor->modify('+1 day')],
        // ISO week: Monday-Sunday. format('N') is 1 (Mon) .. 7 (Sun) — subtracting (N-1)
        // days from any day in the week reliably lands on that week's Monday, which is
        // more predictable across edge cases than PHP's "monday this week" relative format.
        'week' => (function () use ($anchor) {
            $start = $anchor->modify('-' . ((int) $anchor->format('N') - 1) . ' days');
            return [$start, $start->modify('+7 days')];
        })(),
        'month' => [$anchor->modify('first day of this month'), $anchor->modify('first day of next month')],
        'year' => [
            new DateTimeImmutable($anchor->format('Y') . '-01-01', $anchor->getTimezone()),
            new DateTimeImmutable(((int) $anchor->format('Y') + 1) . '-01-01', $anchor->getTimezone()),
        ],
        default => [$anchor, $anchor->modify('+1 day')],
    };
}

/** @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable}> hourly (day view) / daily (week, month) / monthly (year) buckets spanning [$start, $end) */
function buildBuckets(string $view, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $step = $view === 'day' ? '+1 hour' : ($view === 'year' ? '+1 month' : '+1 day');
    $buckets = [];
    $cursor = $start;
    while ($cursor < $end) {
        $next = $cursor->modify($step);
        $buckets[] = ['from' => $cursor, 'to' => $next];
        $cursor = $next;
    }
    return $buckets;
}

/**
 * Sums getHistoricGeneration() rows into each bucket — summed, not averaged, per the
 * brief: a week/month bucket is a whole day's total, a year bucket a whole month's total.
 * A bucket with no rows at all (nothing fetched yet for that stretch) stays null rather
 * than showing as a false zero, same null-means-"no data" convention Store.php's row
 * shape already uses.
 *
 * @param array<int, array{from: DateTimeImmutable, to: DateTimeImmutable}> $buckets
 * @param array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, generation_kwh: ?float, forecast_kwh: ?float}> $rows
 * @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, label: string, generation_kwh: ?float, forecast_kwh: ?float}>
 */
function aggregateBuckets(array $buckets, array $rows, string $view): array
{
    $result = [];
    foreach ($buckets as $bucket) {
        $genSum = null;
        $foreSum = null;
        foreach ($rows as $row) {
            if ($row['from'] < $bucket['from'] || $row['from'] >= $bucket['to']) {
                continue;
            }
            if ($row['generation_kwh'] !== null) {
                $genSum = ($genSum ?? 0.0) + $row['generation_kwh'];
            }
            if ($row['forecast_kwh'] !== null) {
                $foreSum = ($foreSum ?? 0.0) + $row['forecast_kwh'];
            }
        }
        $label = match ($view) {
            'day' => $bucket['from']->format('H:i') . '–' . $bucket['to']->format('H:i'),
            'year' => $bucket['from']->format('M Y'),
            default => $bucket['from']->format('D j M'),
        };
        $result[] = ['from' => $bucket['from'], 'to' => $bucket['to'], 'label' => $label, 'generation_kwh' => $genSum, 'forecast_kwh' => $foreSum];
    }
    return $result;
}

[$periodStart, $periodEnd] = resolvePeriod($view, $anchor);
$rows = getHistoricGeneration($periodStart, $periodEnd);
$buckets = aggregateBuckets(buildBuckets($view, $periodStart, $periodEnd), $rows, $view);

$stepModifier = match ($view) {
    'day' => '1 day',
    'week' => '7 days',
    'month' => '1 month',
    'year' => '1 year',
};
$prevAnchor = $periodStart->modify('-' . $stepModifier);
$nextAnchor = $periodStart->modify('+' . $stepModifier);

$periodTitle = match ($view) {
    'day' => $periodStart->format('l j F Y'),
    'week' => $periodStart->format('j M') . ' – ' . $periodStart->modify('+6 days')->format('j M Y'),
    'month' => $periodStart->format('F Y'),
    'year' => $periodStart->format('Y'),
};

$totalGeneration = null;
$totalForecast = null;
foreach ($buckets as $b) {
    if ($b['generation_kwh'] !== null) {
        $totalGeneration = ($totalGeneration ?? 0.0) + $b['generation_kwh'];
    }
    if ($b['forecast_kwh'] !== null) {
        $totalForecast = ($totalForecast ?? 0.0) + $b['forecast_kwh'];
    }
}

$bounds = getHistoricGenerationBounds();
// This page's chart/table only ever show generation/forecast, not usage (see
// HalfHourlyUsageEstimator for where usage history actually gets consumed) — but generation
// and usage now backfill independently (Store::getHistoryBackfillLimit()), so the coverage
// summary below reports both, otherwise usage's own backfill progress would be invisible
// anywhere in the UI. Both share the same "reached the horizon" sentinel
// (Store::HISTORY_BACKFILL_EPOCH).
$generationLimit = getHistoryBackfillLimit('generation');
$generationExhausted = $generationLimit !== null && $generationLimit->format('Y-m-d') <= HISTORY_BACKFILL_EPOCH;
$usageBounds = getHistoricUsageBounds();
$usageLimit = getHistoryBackfillLimit('usage');
$usageExhausted = $usageLimit !== null && $usageLimit->format('Y-m-d') <= HISTORY_BACKFILL_EPOCH;

/**
 * Same hand-rolled inline-SVG approach as index.php's renderPriceChart() (see that
 * function's doc comment for the full rationale) — two polylines (actual generation,
 * forecast) over whatever buckets the current view resolved to, with the same
 * hover-marker/tooltip mechanism reusing the shared .chart-hit/.chart-dot/#chart-tooltip
 * styling from style.css. Not shared code with index.php's version — each page's chart is
 * local to that page already (index.php's renderPriceChart isn't exported either), and the
 * axis shapes are different enough (fixed price range there vs. a range that must fit
 * whatever a given day/week/month/year of generation actually produced here) that forcing
 * one shared function would need about as many parameters as just having two functions.
 */
function renderHistoryChart(array $buckets, string $view): void
{
    if (!$buckets) {
        return;
    }
    $width = 1000;
    $height = 320;
    $marginLeft = 58;
    $marginRight = 58;
    $marginTop = 30;
    $marginBottom = 36;
    $plotWidth = $width - $marginLeft - $marginRight;
    $plotHeight = $height - $marginTop - $marginBottom;
    $count = count($buckets);
    $baselineY = $marginTop + $plotHeight;

    // Two independent scales, like the dashboard's price (left) / solar kW (right) chart —
    // generation and forecast are the same unit (kWh) but not the same *magnitude* (a
    // forecast for the whole site vs. actual metered generation can differ a lot in scale
    // depending on time of year/inverter mix), so sharing one axis either squashes one
    // series flat or blows the other off the top. Each series is scaled to its own max.
    $genValues = array_values(array_filter(array_column($buckets, 'generation_kwh'), fn($v) => $v !== null));
    $foreValues = array_values(array_filter(array_column($buckets, 'forecast_kwh'), fn($v) => $v !== null));
    $hasGeneration = (bool) $genValues;
    $hasForecast = (bool) $foreValues;
    $maxGen = $genValues ? max($genValues) : 0.0;
    $maxGen = $maxGen > 0 ? $maxGen * 1.1 : 1.0; // 10% headroom so the tallest point/bar isn't glued to the top edge
    $maxFore = $foreValues ? max($foreValues) : 0.0;
    $maxFore = $maxFore > 0 ? $maxFore * 1.1 : 1.0;

    $x = fn(int $i) => $marginLeft + ($count > 1 ? ($i / ($count - 1)) * $plotWidth : $plotWidth / 2);
    $bucketLeft = fn(int $i) => $marginLeft + ($i / $count) * $plotWidth;
    $bucketWidth = $plotWidth / $count;
    $yGen = fn(float $kwh) => $marginTop + (1 - $kwh / $maxGen) * $plotHeight;
    $yFore = fn(float $kwh) => $marginTop + (1 - $kwh / $maxFore) * $plotHeight;

    // Left axis (generation) gets full gridlines, same as the dashboard's price axis.
    $grid = '';
    for ($i = 0; $i <= 4; $i++) {
        $val = $maxGen * $i / 4;
        $gy = $yGen($val);
        $grid .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="var(--color-border)" />', $marginLeft, $gy, $marginLeft + $plotWidth, $gy);
        $grid .= sprintf('<text x="%.1f" y="%.1f" fill="var(--color-muted)" font-size="10" text-anchor="end" dominant-baseline="middle">%s</text>', $marginLeft - 6, $gy, number_format($val, 1));
    }
    // Right axis (forecast) gets labels only, no gridlines of its own — same as the
    // dashboard's kW axis — and only appears at all if this period has any forecast data.
    if ($hasForecast) {
        foreach ([0, $maxFore / 2, $maxFore] as $mark) {
            $grid .= sprintf('<text x="%.1f" y="%.1f" fill="var(--color-muted)" font-size="10" text-anchor="start" dominant-baseline="middle">%s</text>', $marginLeft + $plotWidth + 6, $yFore($mark), number_format($mark, 1));
        }
    }

    // Thin out x-axis labels once there are more buckets than can be read without
    // overlapping (a 28-31 point month view, mainly).
    $labelEvery = $count > 20 ? 5 : ($count > 12 ? 2 : 1);
    for ($i = 0; $i < $count; $i++) {
        if ($i % $labelEvery !== 0) {
            continue;
        }
        $shortLabel = match ($view) {
            'day' => $buckets[$i]['from']->format('H:i'),
            'year' => $buckets[$i]['from']->format('M'),
            default => $buckets[$i]['from']->format('j M'),
        };
        $grid .= sprintf('<text x="%.1f" y="%.1f" fill="var(--color-muted)" font-size="10" text-anchor="middle">%s</text>', $x($i), $height - 8, htmlspecialchars($shortLabel));
    }

    $marker = fn(float $px, float $py, string $color, string $title) => sprintf(
        '<circle class="chart-hit" cx="%.1f" cy="%.1f" r="8" fill="transparent" data-tooltip="%s"><title>%s</title></circle><circle class="chart-dot" cx="%.1f" cy="%.1f" r="2" fill="%s" />',
        $px, $py, htmlspecialchars($title), htmlspecialchars($title), $px, $py, $color,
    );
    $bar = fn(float $left, float $top, float $w, float $h, string $color, string $title) => sprintf(
        '<rect class="chart-hit chart-bar" x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s" data-tooltip="%s"><title>%s</title></rect>',
        $left, $top, $w, max(0.0, $h), $color, htmlspecialchars($title), htmlspecialchars($title),
    );

    $body = '';
    if ($view === 'day') {
        // Line plot, matching the dashboard's price/solar chart exactly — a day's hourly
        // buckets read naturally as a continuous curve.
        $genPoints = [];
        $forePoints = [];
        $markers = '';
        foreach ($buckets as $i => $b) {
            $px = $x($i);
            if ($b['generation_kwh'] !== null) {
                $py = $yGen($b['generation_kwh']);
                $genPoints[] = sprintf('%.1f,%.1f', $px, $py);
                $markers .= $marker($px, $py, 'var(--color-generation)', sprintf('Generated: %s kWh (%s)', number_format($b['generation_kwh'], 2), $b['label']));
            }
            if ($b['forecast_kwh'] !== null) {
                $py = $yFore($b['forecast_kwh']);
                $forePoints[] = sprintf('%.1f,%.1f', $px, $py);
                $markers .= $marker($px, $py, 'var(--color-solar)', sprintf('Forecast: %s kWh (%s)', number_format($b['forecast_kwh'], 2), $b['label']));
            }
        }
        if ($genPoints) {
            $body .= sprintf('<polyline points="%s" fill="none" stroke="var(--color-generation)" stroke-width="2" />', implode(' ', $genPoints));
        }
        if ($forePoints) {
            $body .= sprintf('<polyline points="%s" fill="none" stroke="var(--color-solar)" stroke-width="2" stroke-dasharray="5,4" />', implode(' ', $forePoints));
        }
        $body .= '<g>' . $markers . '</g>';
    } else {
        // Bar plot for week/month/year — two bars per bucket, side by side, each measured
        // against its own axis. Their relative heights are only meaningful within their
        // own series (a tall generation bar and a short forecast bar next to it doesn't
        // mean generation beat forecast — check the axis each is scaled to).
        $groupWidth = $bucketWidth * 0.7;
        $barWidth = $groupWidth / 2 - 1;
        foreach ($buckets as $i => $b) {
            $groupLeft = $bucketLeft($i) + ($bucketWidth - $groupWidth) / 2;
            if ($b['generation_kwh'] !== null) {
                $top = $yGen($b['generation_kwh']);
                $title = sprintf('Generated: %s kWh (%s)', number_format($b['generation_kwh'], 2), $b['label']);
                $body .= $bar($groupLeft, $top, $barWidth, $baselineY - $top, 'var(--color-generation)', $title);
            }
            if ($b['forecast_kwh'] !== null) {
                $top = $yFore($b['forecast_kwh']);
                $title = sprintf('Forecast: %s kWh (%s)', number_format($b['forecast_kwh'], 2), $b['label']);
                $body .= $bar($groupLeft + $barWidth + 2, $top, $barWidth, $baselineY - $top, 'var(--color-solar)', $title);
            }
        }
    }
    ?>
<svg class="price-chart" viewBox="0 0 <?= $width ?> <?= $height ?>" role="img"
    aria-label="Actual generation (left axis) and solar forecast (right axis) over the selected period">
    <?= $grid ?>
    <?= $body ?>
    <g font-size="10" fill="var(--color-muted)">
        <?php if ($hasGeneration): ?>
        <line x1="<?= $marginLeft ?>" y1="12" x2="<?= $marginLeft + 16 ?>" y2="12" stroke="var(--color-generation)"
            stroke-width="2" /><text x="<?= $marginLeft + 20 ?>" y="15">Generation (left axis)</text>
        <?php endif; ?>
        <?php if ($hasForecast): ?>
        <line x1="<?= $marginLeft + 170 ?>" y1="12" x2="<?= $marginLeft + 186 ?>" y2="12" stroke="var(--color-solar)"
            stroke-width="2" <?= $view === 'day' ? ' stroke-dasharray="5,4"' : '' ?> /><text
            x="<?= $marginLeft + 190 ?>" y="15">Forecast (right axis)</text>
        <?php endif; ?>
    </g>
</svg>
<script>
(function() {
    var svg = document.currentScript.previousElementSibling;
    var tooltip = document.getElementById('chart-tooltip');
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.id = 'chart-tooltip';
        tooltip.className = 'chart-tooltip';
        document.body.appendChild(tooltip);
    }
    svg.addEventListener('mousemove', function(e) {
        var target = e.target.closest && e.target.closest('.chart-hit');
        if (!target) {
            tooltip.style.display = 'none';
            return;
        }
        tooltip.textContent = target.dataset.tooltip;
        tooltip.style.left = (e.clientX + 12) + 'px';
        tooltip.style.top = (e.clientY + 12) + 'px';
        tooltip.style.display = 'block';
    });
    svg.addEventListener('mouseleave', function() {
        tooltip.style.display = 'none';
    });
})();
</script>
<?php
}

function periodLink(string $view, DateTimeImmutable $date): string
{
    return 'history.php?' . http_build_query(['view' => $view, 'date' => $date->format('Y-m-d')]);
}

renderHeader('History');

$fetched = $_GET['fetched'] ?? null;
$fetchedOk = ($_GET['ok'] ?? null) === '1';
$fetchedMsg = (string) ($_GET['msg'] ?? '');
?>

<?php if ($fetched): ?>
<p class="alert <?= $fetchedOk ? 'alert-success' : 'alert-error' ?>"><?= htmlspecialchars($fetchedMsg) ?></p>
<?php endif; ?>

<p class="muted">
    <?php if ($bounds['earliest'] === null): ?>
    No generation history fetched yet.
    <?php else: ?>
    Generation history covers <?= htmlspecialchars($bounds['earliest']->setTimezone($timezone)->format('j M Y')) ?>
    to <?= htmlspecialchars($bounds['latest']->setTimezone($timezone)->format('j M Y')) ?>.
    <?= $generationExhausted
        ? 'Backfill complete — FoxESS has no earlier data to fetch.'
        : 'Still backfilling further back — click "Fetch history now" (or just wait for the next scheduled run) to advance it.' ?>
    <?php endif; ?>
    <br>
    <?php if ($usageBounds['earliest'] === null): ?>
    No usage history fetched yet.
    <?php else: ?>
    Usage history covers <?= htmlspecialchars($usageBounds['earliest']->setTimezone($timezone)->format('j M Y')) ?>
    to <?= htmlspecialchars($usageBounds['latest']->setTimezone($timezone)->format('j M Y')) ?>.
    <?= $usageExhausted
        ? 'Backfill complete — FoxESS has no earlier data to fetch.'
        : 'Still backfilling further back — click "Fetch history now" (or just wait for the next scheduled run) to advance it.' ?>
    <?php endif; ?>
</p>

<form method="post" action="history-fetch.php">
    <button type="submit">Fetch history now</button>
</form>

<div class="view-tabs">
    <?php foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $v => $label): ?>
    <a class="view-tab<?= $v === $view ? ' view-tab-active' : '' ?>"
        href="<?= htmlspecialchars(periodLink($v, $anchor)) ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<div class="history-nav">
    <a href="<?= htmlspecialchars(periodLink($view, $prevAnchor)) ?>">&larr; Previous</a>
    <strong><?= htmlspecialchars($periodTitle) ?></strong>
    <a href="<?= htmlspecialchars(periodLink($view, $nextAnchor)) ?>">Next &rarr;</a>
</div>

<form method="get" action="history.php" class="history-date-form">
    <input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>">
    <label for="date-picker">Jump to date</label>
    <input type="date" id="date-picker" name="date" value="<?= htmlspecialchars($anchor->format('Y-m-d')) ?>"
        onchange="this.form.submit()">
    <a href="<?= htmlspecialchars(periodLink($view, new DateTimeImmutable('today', $timezone))) ?>">Today</a>
</form>

<?php if (!$buckets): ?>
<p class="muted">No data for this period.</p>
<?php else: ?>

<?php renderHistoryChart($buckets, $view); ?>

<table id="history-table" class="display">
    <thead>
        <tr>
            <th>Period</th>
            <th>Generation (kWh)</th>
            <th>Forecast (kWh)</th>
            <th>Diff (kWh)</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($buckets as $b): ?>
        <tr>
            <td><?= htmlspecialchars($b['label']) ?></td>
            <td class="currency">
                <?= $b['generation_kwh'] !== null ? htmlspecialchars(number_format($b['generation_kwh'], 2)) : '—' ?>
            </td>
            <td class="currency">
                <?= $b['forecast_kwh'] !== null ? htmlspecialchars(number_format($b['forecast_kwh'], 2)) : '—' ?></td>
            <td class="currency">
                <?= ($b['generation_kwh'] !== null && $b['forecast_kwh'] !== null) ? htmlspecialchars(number_format($b['generation_kwh'] - $b['forecast_kwh'], 2)) : '—' ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <th>Total</th>
            <th class="currency">
                <?= $totalGeneration !== null ? htmlspecialchars(number_format($totalGeneration, 2)) : '—' ?></th>
            <th class="currency">
                <?= $totalForecast !== null ? htmlspecialchars(number_format($totalForecast, 2)) : '—' ?></th>
            <th class="currency">
                <?= ($totalGeneration !== null && $totalForecast !== null) ? htmlspecialchars(number_format($totalGeneration - $totalForecast, 2)) : '—' ?>
            </th>
        </tr>
    </tfoot>
</table>

<?php endif; ?>

<?php
// DataTables, loaded only on this page (see CLAUDE.md's "History page" section for why —
// short version: the app is otherwise dependency-free by design, but sortable/searchable
// tables were specifically requested and DataTables is the standard tool for that, not
// something worth hand-rolling). CDN, not vendored: this is a single-user hobby app on
// shared hosting with no build step, and a CDN script is the lowest-ceremony way to add a
// library that already needs none of its own configuration here.
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script>
$(function() {
    $('#history-table').DataTable({
        order: [],
        paging: <?= count($buckets) > 15 ? 'true' : 'false' ?>,
        searching: <?= count($buckets) > 15 ? 'true' : 'false' ?>,
    });
});
</script>

<?php
renderFooter();