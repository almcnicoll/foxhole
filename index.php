<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Layout.php';
require_once __DIR__ . '/src/FoxessClient.php';

requireLogin();

$config = file_exists(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$timezone = new DateTimeZone($config['strategy']['timezone'] ?? 'Europe/London');
// Battery specs live in the settings table now (see settings.php's "Battery" section,
// Store::getBatteryConfig()) — config.php's old 'battery' array is only read as a
// migration fallback for whichever keys haven't been saved via settings.php yet.
$minSoc = (float) getBatteryConfig($config['battery'] ?? [])['min_soc_on_grid'];
$today = new DateTimeImmutable('today', $timezone);

// GitHub issue #4 ("Date-time-aware scheduling"): shows every currently-known day's
// prices and schedule, not just a single fetched batch — price_slots is a permanent,
// accumulating table now (see CLAUDE.md), so this can genuinely span into tomorrow once
// it's published, not just today.
$slots = getPriceSlotsFrom($today);
$solarForecast = getSetting('solar_enabled', '0') === '1' ? getLatestSolarForecast() : [];
$installedKwp = (float) getSetting('solar_kwp', '0');

/** @return array<string, array{for_date: ?string, pushed_at: ?DateTimeImmutable, groups: array, explanations: string[]}> for_date => schedule, one per known date */
function schedulesByDate(array $slots, DateTimeZone $timezone): array
{
    $dates = [];
    foreach ($slots as $slot) {
        $dates[$slot['from']->setTimezone($timezone)->format('Y-m-d')] = true;
    }
    $result = [];
    foreach (array_keys($dates) as $forDate) {
        $result[$forDate] = getScheduleForDate($forDate);
    }
    return $result;
}

$scheduleByDate = schedulesByDate($slots, $timezone);

/**
 * Every known date's groups, converted from hour/minute-of-day (ambiguous across
 * multiple days on its own) into real DateTimeImmutable instants, so slotWorkMode() can
 * compare a slot's actual timestamp instead of a minute-of-day value that means something
 * different on different calendar days.
 *
 * @return array<int, array{start: DateTimeImmutable, end: DateTimeImmutable, workMode: string}>
 */
function scheduleToAbsoluteIntervals(array $scheduleByDate, DateTimeZone $timezone): array
{
    $intervals = [];
    foreach ($scheduleByDate as $forDate => $schedule) {
        $dayStart = new DateTimeImmutable($forDate, $timezone);
        foreach ($schedule['groups'] as $g) {
            $endMinutes = $g['endHour'] * 60 + $g['endMinute'];
            if ($endMinutes === 0) {
                $endMinutes = 24 * 60; // endHour/endMinute of 0 means midnight, i.e. end of day
            }
            $intervals[] = [
                'start' => $dayStart->modify('+' . ($g['startHour'] * 60 + $g['startMinute']) . ' minutes'),
                'end' => $dayStart->modify("+$endMinutes minutes"),
                'workMode' => $g['workMode'],
            ];
        }
    }
    return $intervals;
}

$absoluteIntervals = scheduleToAbsoluteIntervals($scheduleByDate, $timezone);

// Live battery state, one call per configured inverter — best-effort, the rest of the
// dashboard is all local DB reads and shouldn't break if FoxESS is slow or unreachable.
// A reading of exactly 0% is omitted entirely, not shown as 0% or "unavailable" — a real
// battery never actually reads that low, so it means "no battery attached to this
// inverter" (see Runner.php), not something worth a row on the dashboard.
$apiKey = getSetting('foxess_api_key', '');
$deviceSns = array_values(array_filter(array_map('trim', explode("\n", getSetting('foxess_device_sns', '')))));
$batterySocs = []; // device serial => percent (0-100) or null if unavailable
if ($apiKey !== '' && $deviceSns) {
    $baseUrl = $config['foxess']['base_url'] ?? 'https://www.foxesscloud.com';
    foreach ($deviceSns as $sn) {
        try {
            $soc = (new FoxessClient($apiKey, $sn, $baseUrl))->getBatterySoc();
            if ($soc === 0.0) {
                continue;
            }
            $batterySocs[$sn] = $soc;
        } catch (FoxessPushException $e) {
            $batterySocs[$sn] = null;
        }
    }
}

/**
 * Takes a real instant rather than a minute-of-day (GitHub issue #4) — a plain
 * minute-of-day is ambiguous once the dashboard can show more than one calendar day at
 * once, since "14:00" means something different on two different dates.
 *
 * @param array<int, array{start: DateTimeImmutable, end: DateTimeImmutable, workMode: string}> $absoluteIntervals see scheduleToAbsoluteIntervals()
 */
function slotWorkMode(DateTimeImmutable $instant, array $absoluteIntervals): string
{
    foreach ($absoluteIntervals as $iv) {
        if ($instant >= $iv['start'] && $instant < $iv['end']) {
            return $iv['workMode'];
        }
    }
    return 'SelfUse';
}

/** @param array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, watt_hours: int, fetched_at: DateTimeImmutable}> $forecast */
function renderSolarForecast(array $forecast, DateTimeZone $timezone): void
{
    if (!$forecast) {
        return;
    }
    ?>
<h3>Solar forecast</h3>
<p class="muted">Estimated generation, fetched <?= htmlspecialchars($forecast[0]['fetched_at']->setTimezone($timezone)->format('D j M, H:i')) ?> — not yet used to shape the schedule.</p>
<?php
  // Two columns like the price table below — split by calendar day (today's remaining
  // buckets, then tomorrow's) rather than an hour cutoff, since that's the natural,
  // already-present split in solar's ~2-day forecast (unlike price data, which is always
  // exactly one day).
  $firstDate = $forecast[0]['from']->setTimezone($timezone)->format('Y-m-d');
  $todayBuckets = [];
  $laterBuckets = [];
  foreach ($forecast as $slot) {
      if ($slot['from']->setTimezone($timezone)->format('Y-m-d') === $firstDate) {
          $todayBuckets[] = $slot;
      } else {
          $laterBuckets[] = $slot;
      }
  }
?>
<div class="slot-columns">
    <?php renderSolarTable($todayBuckets, $timezone); ?>
    <?php renderSolarTable($laterBuckets, $timezone); ?>
</div>
<?php
}

function renderSolarTable(array $bucketsForColumn, DateTimeZone $timezone): void
{
    ?>
<table>
    <thead>
        <tr>
            <th>Period</th>
            <th>Est. generation (kWh)</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($bucketsForColumn as $slot):
          if ($slot['from'] == $slot['to']) {
              continue; // zero-width sunrise/sunset marker from SolarForecastClient, nothing to show
          }
          $localFrom = $slot['from']->setTimezone($timezone);
          $localTo = $slot['to']->setTimezone($timezone);
      ?>
        <tr>
            <td><?= htmlspecialchars($localFrom->format('D j M, H:i')) ?>–<?= htmlspecialchars($localTo->format('H:i')) ?></td>
            <td class="currency"><?= htmlspecialchars(number_format($slot['watt_hours'] / 1000, 2)) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php
}

/**
 * Full-width chart above the price/solar tables: import/export price (left axis, fixed
 * -20..50p/kWh) and solar forecast (right axis, fixed 0..installed kWp) over however much
 * of the current day and beyond is actually known (GitHub issue #4 — often more than 24h
 * once tomorrow's Agile rates publish), with a "now" marker and the schedule mode tinted
 * behind each half-hour — same colours as the data table's row/badge tints (var(--row-*),
 * see style.css) so the chart and table read as one system. A dashed vertical divider plus
 * a bold date label marks every midnight crossed, so a >24h span still reads as distinct
 * days rather than one long one. Hand-rolled inline SVG rather than a charting library:
 * SVG is a native browser feature, it can reference the page's own CSS custom properties
 * directly (dark mode "for free", same as every other themed element), and there's nothing
 * here — two axes, a handful of polylines, some background rects — that actually needs a
 * dependency. One small inline <script> at the end (this app's only JS) drives the point
 * tooltips — not the native SVG <title> a first version relied on, since that turned out
 * not to reliably show in real Chrome despite hit-testing/hover working correctly
 * (confirmed live) — likely because the hit target's fill is fully transparent.
 *
 * @param array $slots getPriceSlotsFrom()-shaped rows, any span — the chart's x-axis
 *        covers exactly [local midnight of the first slot's day, the last slot's end)
 * @param array $solarForecast getLatestSolarForecast()-shaped rows (any date range — filtered to the chart's own span below)
 * @param array $absoluteIntervals see scheduleToAbsoluteIntervals() — what slotWorkMode() expects
 */
function renderPriceChart(array $slots, array $solarForecast, array $absoluteIntervals, DateTimeZone $timezone, float $installedKwp): void
{
    if (!$slots) {
        return;
    }

    $width = 1000;
    $height = 320;
    $marginLeft = 46;
    $marginRight = 54;
    $marginTop = 30;
    $marginBottom = 24;
    $plotWidth = $width - $marginLeft - $marginRight;
    $plotHeight = $height - $marginTop - $marginBottom;
    $priceMin = -20.0;
    $priceMax = 50.0;
    $kwMax = $installedKwp > 0 ? $installedKwp : 0.0;

    // The x-axis spans whatever's actually known, not a fixed 24h — see the class doc
    // comment. Always starts at a local midnight so day boundaries land on whole-number
    // tick positions.
    $chartStart = $slots[0]['from']->setTimezone($timezone)->setTime(0, 0);
    $chartEnd = $slots[count($slots) - 1]['to']->setTimezone($timezone);
    $totalMinutes = max(1, ($chartEnd->getTimestamp() - $chartStart->getTimestamp()) / 60);

    $x = fn(DateTimeImmutable $t) => $marginLeft + (($t->getTimestamp() - $chartStart->getTimestamp()) / 60 / $totalMinutes) * $plotWidth;
    $yPrice = fn(float $p) => $marginTop + (1 - ($p - $priceMin) / ($priceMax - $priceMin)) * $plotHeight;
    $yKw = fn(float $kw) => $marginTop + (1 - ($kwMax > 0 ? $kw / $kwMax : 0)) * $plotHeight;

    // One background rect per half-hour slot we actually have data for (a partial day just
    // leaves a gap — see CLAUDE.md's "Partial-day data is normal" — rather than guessing).
    $bands = '';
    foreach ($slots as $slot) {
        $mode = slotWorkMode($slot['from'], $absoluteIntervals);
        $bands .= sprintf(
            '<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="var(--chart-band-%s)" />',
            $x($slot['from']->setTimezone($timezone)),
            $marginTop,
            $x($slot['to']->setTimezone($timezone)) - $x($slot['from']->setTimezone($timezone)),
            $plotHeight,
            htmlspecialchars($mode),
        );
    }

    // Left axis (price) gridlines/labels at fixed 10p increments across the fixed -20..50 range.
    $grid = '';
    for ($p = $priceMin; $p <= $priceMax + 0.01; $p += 10) {
        $gy = $yPrice($p);
        $grid .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="var(--color-border)" />', $marginLeft, $gy, $marginLeft + $plotWidth, $gy);
        $grid .= sprintf('<text x="%.1f" y="%.1f" fill="var(--color-muted)" font-size="10" text-anchor="end" dominant-baseline="middle">%dp</text>', $marginLeft - 6, $gy, (int) round($p));
    }
    // Right axis (kW) labels only — the price gridlines above already mark the horizontal
    // divisions; a second, differently-scaled set of gridlines for kW would just clutter.
    if ($kwMax > 0) {
        foreach ([0, $kwMax / 2, $kwMax] as $kwMark) {
            $grid .= sprintf('<text x="%.1f" y="%.1f" fill="var(--color-muted)" font-size="10" text-anchor="start" dominant-baseline="middle">%.1fkW</text>', $marginLeft + $plotWidth + 6, $yKw($kwMark), $kwMark);
        }
    }
    // Time-of-day labels every 3 hours across the whole span; a midnight crossing gets a
    // stronger dashed divider plus a bold date label instead of just "00:00", so a >24h
    // chart still reads as distinct days.
    $tick = $chartStart;
    while ($tick < $chartEnd) {
        $tx = $x($tick);
        if ($tick > $chartStart && $tick->format('H:i') === '00:00') {
            $grid .= sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="var(--color-primary)" stroke-dasharray="2,2" />', $tx, $marginTop, $tx, $marginTop + $plotHeight);
            $grid .= sprintf('<text x="%.1f" y="%.1f" fill="var(--color-primary-dark)" font-size="10" font-weight="600" text-anchor="middle">%s</text>', $tx, $height - 4, htmlspecialchars($tick->format('D j M')));
        } else {
            $grid .= sprintf('<text x="%.1f" y="%.1f" fill="var(--color-muted)" font-size="10" text-anchor="middle">%s</text>', $tx, $height - 4, $tick->format('H:i'));
        }
        $tick = $tick->modify('+3 hours');
    }

    // Each series gets a small hoverable marker per point alongside its polyline. Two
    // circles per point, not one: the visible dot (.chart-dot) stays small at rest so it
    // doesn't clutter the line, but a small dot is a poor mouse target — a larger invisible
    // one (.chart-hit) sits underneath to actually catch the hover, and grows the visible
    // dot via a CSS sibling selector (style.css) so growth is centred on the same point
    // rather than the hit area itself visibly resizing.
    //
    // The tooltip itself is a small custom one (script at the bottom of this function),
    // not the native SVG <title> a first version relied on — confirmed in real Chrome
    // (via Claude in Chrome) that :hover/pointer-events on .chart-hit fire correctly (the
    // dot visibly grows) but Chrome never shows the native title tooltip for it, most
    // likely because the hit circle's fill is fully transparent rather than a real (if
    // faint) colour. <title> is kept alongside data-tooltip anyway, harmless and still
    // gives assistive tech an accessible name even where the visual tooltip doesn't fire.
    $marker = fn(float $px, float $py, string $color, string $title) => sprintf(
        '<circle class="chart-hit" cx="%.1f" cy="%.1f" r="8" fill="transparent" data-tooltip="%s"><title>%s</title></circle><circle class="chart-dot" cx="%.1f" cy="%.1f" r="2" fill="%s" />',
        $px,
        $py,
        htmlspecialchars($title),
        htmlspecialchars($title),
        $px,
        $py,
        $color,
    );

    $importPoints = [];
    $exportPoints = [];
    $importMarkers = '';
    $exportMarkers = '';
    foreach ($slots as $slot) {
        $px = $x($slot['from']->setTimezone($timezone));
        $time = $slot['from']->setTimezone($timezone)->format('D H:i');
        $importY = $yPrice($slot['import_rate']);
        $importPoints[] = sprintf('%.1f,%.1f', $px, $importY);
        $importMarkers .= $marker($px, $importY, 'var(--color-error)', sprintf('Import: %sp/kWh at %s', number_format($slot['import_rate'], 2), $time));
        if ($slot['export_rate'] !== null) {
            $exportY = $yPrice($slot['export_rate']);
            $exportPoints[] = sprintf('%.1f,%.1f', $px, $exportY);
            $exportMarkers .= $marker($px, $exportY, 'var(--color-success)', sprintf('Export: %sp/kWh at %s', number_format($slot['export_rate'], 2), $time));
        }
    }

    // Solar forecast is stored across ~2 days (see SolarForecastClient) — filter to the
    // chart's own span (which itself may now cover more than one day, see above), and
    // plot each bucket at its own midpoint since buckets aren't a fixed half-hour grid
    // like price slots (hourly, plus odd sunrise/sunset ones).
    $solarPoints = [];
    $solarMarkers = '';
    if ($kwMax > 0 && $solarForecast) {
        foreach ($solarForecast as $bucket) {
            $durationSeconds = $bucket['to']->getTimestamp() - $bucket['from']->getTimestamp();
            if ($durationSeconds <= 0) {
                continue; // zero-width sunrise/sunset marker
            }
            $mid = (new DateTimeImmutable('@' . ($bucket['from']->getTimestamp() + intdiv($durationSeconds, 2))))->setTimezone($timezone);
            if ($mid < $chartStart || $mid >= $chartEnd) {
                continue;
            }
            $kw = min(($bucket['watt_hours'] / 1000) / ($durationSeconds / 3600), $kwMax);
            $px = $x($mid);
            $py = $yKw($kw);
            $solarPoints[] = sprintf('%.1f,%.1f', $px, $py);
            $solarMarkers .= $marker($px, $py, 'var(--color-solar)', sprintf('Solar: %skW at %s', number_format($kw, 2), $mid->format('D H:i')));
        }
    }

    // "Now" only makes sense while it actually falls within the charted span — always true
    // for the "today" portion, but not once now has moved past the last known slot (e.g.
    // only a partial day is known and it's later than that).
    $nowLine = '';
    $now = new DateTimeImmutable('now', $timezone);
    if ($now >= $chartStart && $now < $chartEnd) {
        $nowX = $x($now);
        $nowLine = sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="var(--color-primary)" stroke-width="1.5" stroke-dasharray="4,3" />', $nowX, $marginTop, $nowX, $marginTop + $plotHeight);
    }
    ?>
<svg class="price-chart" viewBox="0 0 <?= $width ?> <?= $height ?>" role="img" aria-label="Import and export price, and solar forecast, over the known period">
    <defs>
        <clipPath id="price-chart-plot"><rect x="<?= $marginLeft ?>" y="<?= $marginTop ?>" width="<?= $plotWidth ?>" height="<?= $plotHeight ?>" /></clipPath>
    </defs>
    <g clip-path="url(#price-chart-plot)">
        <?= $bands ?>
        <?= $nowLine ?>
        <polyline points="<?= implode(' ', $importPoints) ?>" fill="none" stroke="var(--color-error)" stroke-width="2" />
        <?php if ($exportPoints): ?><polyline points="<?= implode(' ', $exportPoints) ?>" fill="none" stroke="var(--color-success)" stroke-width="2" /><?php endif; ?>
        <?php if ($solarPoints): ?><polyline points="<?= implode(' ', $solarPoints) ?>" fill="none" stroke="var(--color-solar)" stroke-width="2" /><?php endif; ?>
    </g>
    <?= $grid ?>
    <?php /* Markers sit outside the clipped group, deliberately — the first/last point of
    every series lands exactly on the clip boundary, and clip-path silently eats half their
    hit-circle there (confirmed live: elementFromPoint missed it), breaking hover for
    exactly those points. */ ?>
    <g><?= $importMarkers . $exportMarkers . $solarMarkers ?></g>
    <g font-size="10" fill="var(--color-muted)">
        <line x1="<?= $marginLeft ?>" y1="12" x2="<?= $marginLeft + 16 ?>" y2="12" stroke="var(--color-error)" stroke-width="2" /><text x="<?= $marginLeft + 20 ?>" y="15">Import price</text>
        <line x1="<?= $marginLeft + 110 ?>" y1="12" x2="<?= $marginLeft + 126 ?>" y2="12" stroke="var(--color-success)" stroke-width="2" /><text x="<?= $marginLeft + 130 ?>" y="15">Export price</text>
        <?php if ($kwMax > 0): ?><line x1="<?= $marginLeft + 220 ?>" y1="12" x2="<?= $marginLeft + 236 ?>" y2="12" stroke="var(--color-solar)" stroke-width="2" /><text x="<?= $marginLeft + 240 ?>" y="15">Solar forecast</text><?php endif; ?>
    </g>
</svg>
<script>
(function () {
    var svg = document.currentScript.previousElementSibling;
    var tooltip = document.getElementById('chart-tooltip');
    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.id = 'chart-tooltip';
        tooltip.className = 'chart-tooltip';
        document.body.appendChild(tooltip);
    }
    svg.addEventListener('mousemove', function (e) {
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
    svg.addEventListener('mouseleave', function () {
        tooltip.style.display = 'none';
    });
})();
</script>
<?php
}

function renderSlotTable(array $slotsForColumn, DateTimeZone $timezone, array $absoluteIntervals): void
{
    ?>
<table>
    <thead>
        <tr>
            <th>Time</th>
            <th>Import (p/kWh)</th>
            <th>Export (p/kWh)</th>
            <th>Mode</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($slotsForColumn as $slot):
          $localFrom = $slot['from']->setTimezone($timezone);
          $localTo = $slot['to']->setTimezone($timezone);
          $mode = slotWorkMode($slot['from'], $absoluteIntervals);
      ?>
        <tr class="row-<?= htmlspecialchars($mode) ?>">
            <td><?= htmlspecialchars($localFrom->format('H:i')) ?>–<?= htmlspecialchars($localTo->format('H:i')) ?></td>
            <td class="currency"><?= htmlspecialchars(number_format($slot['import_rate'], 2)) ?></td>
            <td class="currency">
                <?= $slot['export_rate'] !== null ? htmlspecialchars(number_format($slot['export_rate'], 2)) : '—' ?>
            </td>
            <td><span class="badge badge-<?= htmlspecialchars($mode) ?>"><?= htmlspecialchars($mode) ?></span></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php
}

/**
 * @param array<string, ?float> $batterySocs device serial => percent, or null if unavailable
 * @param float $minSoc config's battery.min_soc_on_grid — the bottom of the "red" band;
 *        100% is always the top of "green". The range between is split into equal thirds.
 */
function renderBatteryStatus(array $batterySocs, float $minSoc): string
{
    if (!$batterySocs) {
        return '';
    }
    $multiple = count($batterySocs) > 1;
    $i = 0;
    $items = '';
    foreach ($batterySocs as $soc) {
        $i++;
        $label = htmlspecialchars($multiple ? "Battery $i" : 'Battery');
        if ($soc === null) {
            $items .= "<div class=\"battery-item\"><span class=\"battery-label\">$label:</span><span class=\"muted\">unavailable</span></div>";
        } else {
            $pct = (int) round($soc);
            $third = max(0.001, (100 - $minSoc) / 3); // avoid div-by-zero if min_soc_on_grid is ever set to 100
            $band = $soc <= $minSoc + $third ? 'red' : ($soc <= $minSoc + 2 * $third ? 'amber' : 'green');
            $items .= "<div class=\"battery-item\"><span class=\"battery-label\">$label</span><progress class=\"soc-$band\" value=\"$pct\" max=\"100\"></progress><span>$pct%</span></div>";
        }
    }
    return "<div class=\"battery-status\">$items</div>";
}

renderHeader('Dashboard', headerExtra: renderBatteryStatus($batterySocs, $minSoc));

$ran = $_GET['ran'] ?? null;
$ranOk = ($_GET['ok'] ?? null) === '1';
$ranMsg = (string) ($_GET['msg'] ?? '');
// "Warning" isn't a distinct field Runner returns — a successful no-op run (nothing had
// changed, so nothing was pushed) reads as informational rather than a full success.
$ranClass = !$ranOk ? 'alert-error' : ((str_contains($ranMsg, 'unchanged') || str_contains($ranMsg, 'pending')) ? 'alert-warning' : 'alert-success');
?>

<?php if ($ran): ?>
<p class="alert <?= $ranClass ?>"><?= htmlspecialchars($ranMsg) ?></p>
<?php endif; ?>

<?php if (!$slots): ?>
<p class="muted">No rates fetched yet — run.php hasn't completed a successful fetch.</p>
<form method="post" action="run-now.php">
    <div class="full-width text-center">
        <button type="submit">Run now</button>
    </div>
</form>
<?php else: ?>
<p class="muted">
    Rates last fetched <?= htmlspecialchars(getLatestPriceFetchedAt()->setTimezone($timezone)->format('D j M, H:i')) ?>.
    <?php foreach ($scheduleByDate as $forDate => $daySchedule): ?>
        <?php if ($daySchedule['pushed_at']): ?>
    Schedule for <?= htmlspecialchars($forDate) ?> pushed
    <?= htmlspecialchars($daySchedule['pushed_at']->setTimezone($timezone)->format('D j M, H:i')) ?>.
        <?php else: ?>
    No schedule pushed yet for <?= htmlspecialchars($forDate) ?>.
        <?php endif; ?>
    <?php endforeach; ?>
</p>

<?php if (array_filter(array_column($scheduleByDate, 'explanations'))): ?>
<h3>Energy plan</h3>
<?php foreach ($scheduleByDate as $forDate => $daySchedule): if ($daySchedule['explanations']): ?>
<h4><?= htmlspecialchars((new DateTimeImmutable($forDate, $timezone))->format('D j M')) ?></h4>
<?php $daySummary = getScheduleSummary($forDate); ?>
<?php if ($daySummary): ?><p class="muted"><?= htmlspecialchars($daySummary) ?></p><?php endif; ?>
<ul>
    <?php foreach ($daySchedule['explanations'] as $explanation): ?>
    <li><?= htmlspecialchars((string) $explanation) ?></li>
    <?php endforeach; ?>
</ul>
<?php endif; endforeach; ?>
<?php endif; ?>

<form method="post" action="run-now.php">
    <button type="submit">Run now</button>
</form>

<?php renderPriceChart($slots, $solarForecast, $absoluteIntervals, $timezone, $installedKwp); ?>

<?php foreach ($scheduleByDate as $forDate => $daySchedule): ?>
<h4><?= htmlspecialchars((new DateTimeImmutable($forDate, $timezone))->format('D j M Y')) ?></h4>
<?php
  $daySlots = array_values(array_filter($slots, fn($s) => $s['from']->setTimezone($timezone)->format('Y-m-d') === $forDate));
  $leftSlots = [];
  $rightSlots = [];
  foreach ($daySlots as $slot) {
      if ((int) $slot['from']->setTimezone($timezone)->format('G') < 12) {
          $leftSlots[] = $slot;
      } else {
          $rightSlots[] = $slot;
      }
  }
  ?>
<div class="slot-columns">
    <?php renderSlotTable($leftSlots, $timezone, $absoluteIntervals); ?>
    <?php renderSlotTable($rightSlots, $timezone, $absoluteIntervals); ?>
</div>
<?php endforeach; ?>

<?php renderSolarForecast($solarForecast, $timezone); ?>
<?php endif; ?>

<?php
renderFooter();