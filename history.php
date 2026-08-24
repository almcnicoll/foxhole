<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/HalfHourlyUsageEstimator.php';

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
 * @param array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, generation_kwh: ?float, forecast_kwh: ?float, usage_kwh: ?float}> $rows
 * @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, label: string, generation_kwh: ?float, forecast_kwh: ?float, usage_kwh: ?float}>
 */
function aggregateBuckets(array $buckets, array $rows, string $view): array
{
    $result = [];
    foreach ($buckets as $bucket) {
        $genSum = null;
        $foreSum = null;
        $usageSum = null;
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
            if ($row['usage_kwh'] !== null) {
                $usageSum = ($usageSum ?? 0.0) + $row['usage_kwh'];
            }
        }
        $label = match ($view) {
            'day' => $bucket['from']->format('H:i') . '–' . $bucket['to']->format('H:i'),
            'year' => $bucket['from']->format('M Y'),
            default => $bucket['from']->format('D j M'),
        };
        $result[] = ['from' => $bucket['from'], 'to' => $bucket['to'], 'label' => $label, 'generation_kwh' => $genSum, 'forecast_kwh' => $foreSum, 'usage_kwh' => $usageSum];
    }
    return $result;
}

/**
 * Projected usage per bucket, from HalfHourlyUsageEstimator — the same per-half-hour
 * estimate the forecast-weighted/modelling schedulers and the dashboard chart already use
 * (GitHub issue #9), not a second usage model invented just for this page. Unlike
 * generation/forecast above, this isn't a sum of stored rows — the estimator produces one
 * calendar date's 48 values at a time, so this walks every half hour the buckets span,
 * calling it once per date and folding each half hour into whichever bucket contains it.
 * Buckets and half-hours are both already in chronological order, so a single advancing
 * pointer (rather than rescanning every bucket per half hour) keeps this to one pass over
 * each — the naive nested-loop version is fine for a day/week/month view but noticeably
 * more work for a year view's ~17,500 half hours.
 *
 * @param array<int, array{from: DateTimeImmutable, to: DateTimeImmutable}> $buckets
 * @return array<int, ?float> aligned to $buckets — always non-null in practice, since the
 *         estimator has a flat fallback rather than ever refusing to answer, but kept
 *         nullable for symmetry with the actual-data sums above
 */
function aggregateProjectedUsage(array $buckets, array $historicUsageRows, DateTimeZone $timezone, float $summerKwhMonth, float $winterKwhMonth): array
{
    if (!$buckets) {
        return [];
    }
    $dayCursor = $buckets[0]['from']->setTimezone($timezone)->setTime(0, 0);
    $spanEnd = $buckets[count($buckets) - 1]['to']->setTimezone($timezone);

    $sums = array_fill(0, count($buckets), null);
    $bucketIndex = 0;
    while ($dayCursor < $spanEnd) {
        $halfHourlyKwh = HalfHourlyUsageEstimator::estimateHalfHourly($dayCursor, $timezone, $historicUsageRows, $summerKwhMonth, $winterKwhMonth);
        foreach ($halfHourlyKwh as $half => $kwh) {
            $slotStart = $dayCursor->modify('+' . ($half * 30) . ' minutes');
            while ($bucketIndex < count($buckets) && $slotStart >= $buckets[$bucketIndex]['to']) {
                $bucketIndex++;
            }
            if ($bucketIndex >= count($buckets)) {
                break 2;
            }
            if ($slotStart >= $buckets[$bucketIndex]['from']) {
                $sums[$bucketIndex] = ($sums[$bucketIndex] ?? 0.0) + $kwh;
            }
        }
        $dayCursor = $dayCursor->modify('+1 day');
    }
    return $sums;
}

[$periodStart, $periodEnd] = resolvePeriod($view, $anchor);
$rows = getHistoricGeneration($periodStart, $periodEnd);
$buckets = aggregateBuckets(buildBuckets($view, $periodStart, $periodEnd), $rows, $view);

// Projected usage (GitHub issue #9's dashboard feature, mirrored here) needs history well
// beyond just this page's own period — same "10 years back, harmless if most of it's
// empty" bound the dashboard chart and Schedulers.php already use for the same estimator.
$historicUsageRows = getHistoricGeneration((new DateTimeImmutable('-10 years', $timezone))->setTime(0, 0), $periodEnd);
$usageSummerKwhMonth = (float) getSetting('usage_summer_kwh_month', '300');
$usageWinterKwhMonth = (float) getSetting('usage_winter_kwh_month', '700');
$projectedUsageByBucket = aggregateProjectedUsage($buckets, $historicUsageRows, $timezone, $usageSummerKwhMonth, $usageWinterKwhMonth);
foreach ($buckets as $i => &$bucket) {
    $bucket['projected_usage_kwh'] = $projectedUsageByBucket[$i];
}
unset($bucket);

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
 *
 * GitHub issue #6: generation and forecast share a single axis, not one each. They were
 * originally scaled independently (the dashboard's price/solar-kW split was the model), but
 * unlike price vs. kW, generation and forecast are the *same unit* (kWh) and generally the
 * same ballpark — a forecast is trying to predict actual generation, so two separate scales
 * made a good forecast and a bad one look visually identical (both would fill their own
 * axis to the same height) and made comparing the two series by eye actively misleading.
 * Usage/projected usage (GitHub issue #9) share this same axis too, for the same reason —
 * all four series are kWh over the same period.
 *
 * Colour standard (see CLAUDE.md's "Chart colour standard"): yellow for anything solar
 * (generation and forecast both — they're the same underlying quantity, actual vs
 * predicted), blue for anything usage (actual and projected, matching the dashboard
 * exactly), solid for actual/observed, dashed for predicted. A line chart can express
 * solid-vs-dashed directly; a bar can't, so the predicted series' bars use reduced
 * fill-opacity instead — same colour family, still visually distinct from their solid
 * actual-value counterpart without contradicting "same colour" for the pair.
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

    // One shared scale for every series (GitHub issues #6 and #9) — all four are the same
    // unit and generally the same ballpark, so a single axis makes them directly, honestly
    // comparable by eye, which is the whole point of plotting them together.
    $genValues = array_values(array_filter(array_column($buckets, 'generation_kwh'), fn($v) => $v !== null));
    $foreValues = array_values(array_filter(array_column($buckets, 'forecast_kwh'), fn($v) => $v !== null));
    $usageValues = array_values(array_filter(array_column($buckets, 'usage_kwh'), fn($v) => $v !== null));
    $projectedUsageValues = array_values(array_filter(array_column($buckets, 'projected_usage_kwh'), fn($v) => $v !== null));
    $hasGeneration = (bool) $genValues;
    $hasForecast = (bool) $foreValues;
    $hasUsage = (bool) $usageValues;
    $hasProjectedUsage = (bool) $projectedUsageValues;
    $maxValue = max(array_merge($genValues, $foreValues, $usageValues, $projectedUsageValues, [0.0]));
    $maxValue = $maxValue > 0 ? $maxValue * 1.1 : 1.0; // 10% headroom so the tallest point/bar isn't glued to the top edge

    $x = fn(int $i) => $marginLeft + ($count > 1 ? ($i / ($count - 1)) * $plotWidth : $plotWidth / 2);
    $bucketLeft = fn(int $i) => $marginLeft + ($i / $count) * $plotWidth;
    $bucketWidth = $plotWidth / $count;
    $y = fn(float $kwh) => $marginTop + (1 - $kwh / $maxValue) * $plotHeight;

    $grid = '';
    for ($i = 0; $i <= 4; $i++) {
        $val = $maxValue * $i / 4;
        $gy = $y($val);
        $grid .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="var(--color-border)" />', $marginLeft, $gy, $marginLeft + $plotWidth, $gy);
        $grid .= sprintf('<text x="%.1f" y="%.1f" fill="var(--color-muted)" font-size="10" text-anchor="end" dominant-baseline="middle">%s</text>', $marginLeft - 6, $gy, number_format($val, 1));
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
    // $predicted only affects bars (reduced fill-opacity — see this function's doc comment
    // for why bars need a different actual-vs-predicted cue than the line plot's dashing).
    $bar = fn(float $left, float $top, float $w, float $h, string $color, string $title, bool $predicted) => sprintf(
        '<rect class="chart-hit chart-bar" x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="%s"%s data-tooltip="%s"><title>%s</title></rect>',
        $left, $top, $w, max(0.0, $h), $color, $predicted ? ' fill-opacity="0.55"' : '', htmlspecialchars($title), htmlspecialchars($title),
    );

    // Colour standard (CLAUDE.md): yellow for solar (generation actual, forecast
    // predicted), blue for usage (usage actual, projected usage predicted) — see this
    // function's doc comment. One definition here drives the line plot, the bar plot, and
    // the legend, so the three can't quietly drift apart on colour/verb/predicted-ness.
    $series = [
        'generation' => ['field' => 'generation_kwh', 'color' => 'var(--color-solar)', 'predicted' => false, 'verb' => 'Generated', 'legend' => 'Generation', 'has' => $hasGeneration],
        'forecast' => ['field' => 'forecast_kwh', 'color' => 'var(--color-solar)', 'predicted' => true, 'verb' => 'Forecast', 'legend' => 'Forecast', 'has' => $hasForecast],
        'usage' => ['field' => 'usage_kwh', 'color' => 'var(--color-usage)', 'predicted' => false, 'verb' => 'Usage', 'legend' => 'Usage', 'has' => $hasUsage],
        'projected' => ['field' => 'projected_usage_kwh', 'color' => 'var(--color-usage)', 'predicted' => true, 'verb' => 'Projected usage', 'legend' => 'Projected usage', 'has' => $hasProjectedUsage],
    ];
    $activeSeries = array_values(array_filter($series, fn($s) => $s['has']));

    $body = '';
    if ($view === 'day') {
        // Line plot, matching the dashboard's price/solar chart exactly — a day's hourly
        // buckets read naturally as a continuous curve.
        $lines = '';
        $markers = '';
        foreach ($activeSeries as $s) {
            $points = [];
            foreach ($buckets as $i => $b) {
                $val = $b[$s['field']];
                if ($val === null) {
                    continue;
                }
                $px = $x($i);
                $py = $y($val);
                $points[] = sprintf('%.1f,%.1f', $px, $py);
                $markers .= $marker($px, $py, $s['color'], sprintf('%s: %s kWh (%s)', $s['verb'], number_format($val, 2), $b['label']));
            }
            if ($points) {
                $lines .= sprintf(
                    '<polyline points="%s" fill="none" stroke="%s" stroke-width="2"%s />',
                    implode(' ', $points),
                    $s['color'],
                    $s['predicted'] ? ' stroke-dasharray="5,4"' : '',
                );
            }
        }
        $body = $lines . '<g>' . $markers . '</g>';
    } else {
        // Bar plot for week/month/year — one bar per active series per bucket, side by
        // side, all measured against the same shared axis so relative heights are directly
        // comparable. However many series actually have data this period share the group
        // width evenly, rather than always reserving space for four.
        $groupWidth = $bucketWidth * 0.7;
        $n = count($activeSeries);
        $barWidth = $n > 0 ? $groupWidth / $n - 1 : $groupWidth;
        foreach ($buckets as $i => $b) {
            $groupLeft = $bucketLeft($i) + ($bucketWidth - $groupWidth) / 2;
            foreach ($activeSeries as $j => $s) {
                $val = $b[$s['field']];
                if ($val === null) {
                    continue;
                }
                $top = $y($val);
                $title = sprintf('%s: %s kWh (%s)', $s['verb'], number_format($val, 2), $b['label']);
                $body .= $bar($groupLeft + $j * ($barWidth + 2), $top, $barWidth, $baselineY - $top, $s['color'], $title, $s['predicted']);
            }
        }
    }
    ?>
<svg class="price-chart" viewBox="0 0 <?= $width ?> <?= $height ?>" role="img"
    aria-label="Actual and forecast solar generation, and actual and projected usage, on the same axis, over the selected period">
    <?= $grid ?>
    <?= $body ?>
    <g font-size="10" fill="var(--color-muted)">
        <?php foreach ($activeSeries as $idx => $s): $legendX = $marginLeft + $idx * 110; ?>
        <line x1="<?= $legendX ?>" y1="12" x2="<?= $legendX + 16 ?>" y2="12" stroke="<?= $s['color'] ?>" stroke-width="2"
            <?= $s['predicted'] && $view === 'day' ? ' stroke-dasharray="5,4"' : '' ?>
            <?= $s['predicted'] && $view !== 'day' ? ' stroke-opacity="0.55"' : '' ?> /><text x="<?= $legendX + 20 ?>" y="15"><?= htmlspecialchars($s['legend']) ?></text>
        <?php endforeach; ?>
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
        paging: false, // always show the full table — even the largest view (a 28-31 row month) is a modest, entirely readable single page
        searching: <?= count($buckets) > 15 ? 'true' : 'false' ?>,
    });
});
</script>

<?php
renderFooter();