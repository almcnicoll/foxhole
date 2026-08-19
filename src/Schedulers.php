<?php

require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/ScheduleBuilder.php';
require_once __DIR__ . '/IntelligentScheduleBuilder.php';
require_once __DIR__ . '/ModellingScheduleBuilder.php';
require_once __DIR__ . '/HalfHourlyUsageEstimator.php';

/**
 * Pluggable scheduler registry (GitHub issue #2). The source of truth for which
 * scheduling algorithms exist, their user-facing name/description (shown on the new
 * "Schedulers" page, schedulers.php), and how to invoke each one. Adding a future
 * algorithm means adding one entry here plus one branch in buildScheduleWithScheduler()
 * — Runner.php and schedulers.php both just iterate this array, neither hardcodes which
 * schedulers exist.
 *
 * Deliberately still a plain array + a small dispatch function, not a class-per-scheduler
 * interface: the two implementations already existed before this registry did
 * (ScheduleBuilder::build($importSlots, $exportSlots, $costBasis), IntelligentScheduleBuilder::build()
 * with two more parameters for solar/SoC), and forcing them behind one shared signature
 * would mean either widening ScheduleBuilder's to accept inputs it ignores or narrowing
 * IntelligentScheduleBuilder's to lose real ones — busywork with no actual benefit for
 * two implementations. Same "plain and explainable beats an abstraction that has to paper
 * over a real difference" reasoning CLAUDE.md gives for the schedulers themselves.
 *
 * Order here is display order on the Schedulers page.
 */
const SCHEDULER_DEFINITIONS = [
    'classic' => [
        'name' => 'Classic price-threshold model',
        'description' => 'Charges below your cost basis or the day\'s best export rate, and discharges at the '
            . 'export or import peak — a flat price-threshold heuristic with no solar forecast or live '
            . 'battery-state simulation.',
    ],
    'forecast_weighted_price_model' => [
        'name' => 'Forecast-weighted price model',
        'description' => 'Uses export price, import price and solar forecast to work out demand levels and buy '
            . 'up electricity when it’s below export price.',
    ],
    'modelling' => [
        'name' => 'Modelling scheduler',
        'description' => 'Solves for the exact lowest-cost charge/discharge sequence via dynamic programming '
            . 'over discretised battery SoC, using a half-hourly usage forecast sampled from historical data, '
            . 'solar forecast, and import/export price — not a heuristic, an optimiser.',
    ],
];

const DEFAULT_SCHEDULER_ID = 'forecast_weighted_price_model';

/**
 * @param ?string $override run.php's --classic/--intelligent flags take precedence for
 *        that run only, same as the old boolean $forceIntelligent parameter they used to
 *        set. Falls back to the stored setting, then (only if that setting was never
 *        written at all) the old `intelligent_scheduler_enabled` boolean toggle this
 *        registry replaced, then the default.
 */
function resolveSchedulerId(?string $override = null): string
{
    if ($override !== null && isset(SCHEDULER_DEFINITIONS[$override])) {
        return $override;
    }
    $stored = getSetting('scheduler_id');
    if ($stored !== null && isset(SCHEDULER_DEFINITIONS[$stored])) {
        return $stored;
    }
    $legacyToggle = getSetting('intelligent_scheduler_enabled');
    if ($stored === null && $legacyToggle !== null) {
        return $legacyToggle === '1' ? 'forecast_weighted_price_model' : 'classic';
    }
    return DEFAULT_SCHEDULER_ID;
}

/**
 * @param array $inputs importSlots/exportSlots/costBasis (every scheduler uses these);
 *        solarSlots/currentSocPercent/usageConfig are only read by
 *        forecast_weighted_price_model — harmless to pass for 'classic' too, it just
 *        ignores them, same as gathering them unconditionally would be more expensive
 *        for no benefit than a per-scheduler if in the caller (see Runner.php).
 * @return array{groups: array, explanations: string[], summary: string}
 */
function buildScheduleWithScheduler(string $schedulerId, array $strategyConfig, array $batteryConfig, array $inputs): array
{
    if ($schedulerId === 'forecast_weighted_price_model') {
        $builder = new IntelligentScheduleBuilder($strategyConfig, $batteryConfig, $inputs['usageConfig'] ?? []);
        return $builder->build(
            $inputs['importSlots'],
            $inputs['exportSlots'],
            $inputs['costBasis'],
            $inputs['solarSlots'] ?? null,
            $inputs['currentSocPercent'] ?? null,
        );
    }
    $builder = new ScheduleBuilder($strategyConfig, $batteryConfig);
    return $builder->build($inputs['importSlots'], $inputs['exportSlots'], $inputs['costBasis']);
}

/**
 * Runs the resolved scheduler once per calendar day present in $slotsByDate — the "per
 * calendar day" decision behind GitHub issue #4's multi-day scheduling: each day's plan
 * is exactly what buildScheduleWithScheduler() would already produce for that single day
 * in isolation, so config caps like cheap_slots_to_charge apply per day, completely
 * unchanged. `Runner.php`'s real pipeline and `schedulers.php`'s preview both call this
 * one function, rather than each independently looping and risking drift between them.
 *
 * For the forecast-weighted scheduler specifically, each day after the first carries
 * forward the *previous* day's projected `finalSocPercent` as its own starting SoC,
 * instead of every day independently assuming the real live reading — that reading is
 * only actually true for day one; day two's simulation should start from wherever day
 * one's own plan is projected to leave the battery.
 *
 * @param array<string, array{importSlots: array, exportSlots: ?array, costBasis: array}> $slotsByDate
 *        for_date (Y-m-d) => that day's inputs, in date order
 * @param array $forecastExtras only consulted for forecast_weighted_price_model:
 *        ['usageConfig' => array, 'solarSlots' => ?array, 'currentSocPercent' => ?float] —
 *        currentSocPercent seeds day one only.
 * @return array<string, array{groups: array, explanations: string[], summary: string}> for_date => schedule, same order as $slotsByDate
 */
function buildMultiDaySchedule(string $schedulerId, array $strategyConfig, array $batteryConfig, array $slotsByDate, array $forecastExtras = []): array
{
    $result = [];
    $currentSocPercent = $forecastExtras['currentSocPercent'] ?? null;
    foreach ($slotsByDate as $forDate => $dayInputs) {
        $inputs = $dayInputs;
        if ($schedulerId === 'forecast_weighted_price_model') {
            $inputs['usageConfig'] = $forecastExtras['usageConfig'] ?? [];
            $inputs['solarSlots'] = $forecastExtras['solarSlots'] ?? null;
            $inputs['currentSocPercent'] = $currentSocPercent;
        }
        $schedule = buildScheduleWithScheduler($schedulerId, $strategyConfig, $batteryConfig, $inputs);
        $result[$forDate] = $schedule;
        if ($schedulerId === 'forecast_weighted_price_model') {
            $currentSocPercent = $schedule['finalSocPercent'] ?? $currentSocPercent;
        }
    }
    return $result;
}

/**
 * The "Modelling scheduler" (GitHub issue #5) doesn't fit buildMultiDaySchedule()'s
 * per-calendar-day loop — its horizon is a rolling window from now, which may cross a
 * midnight boundary, and ModellingScheduleBuilder::build() runs once over the whole
 * window rather than once per day (see its own class doc comment for why this is a
 * deliberate departure from the "per calendar day" decision the other two schedulers
 * follow). This is its own parallel dispatch path: run the DP once, then split its
 * absolute-instant intervals back into the same per-date {groups, explanations, summary}
 * shape buildMultiDaySchedule() produces, so the rest of the pipeline (saveSchedule(),
 * display) doesn't need to know this scheduler works differently under the hood. Every
 * calendar date the window itself spans gets an entry — even one with zero force
 * activity — same as the other schedulers always producing a (possibly empty) plan for
 * every known day. The whole-window summary is attached to every date touched, since the
 * DP doesn't compute a separate per-date breakdown of it.
 *
 * @param array $importSlots the rolling-window slots (from Runner.php/schedulers.php's own
 *        window derivation — same bounds ScheduleBuilder::buildPushWindow() uses),
 *        possibly spanning more than one calendar date
 * @param float[] $halfHourlyUsageKwh aligned to $importSlots (see HalfHourlyUsageEstimator)
 * @return array<string, array{groups: array, explanations: string[], summary: string}> for_date => schedule
 */
function buildModellingSchedule(
    array $strategyConfig,
    array $batteryConfig,
    array $modellingConfig,
    array $importSlots,
    ?array $exportSlots,
    array $halfHourlyUsageKwh,
    ?array $solarSlots,
    ?float $currentSocPercent,
    DateTimeZone $timezone,
): array {
    $result = (new ModellingScheduleBuilder($strategyConfig, $batteryConfig, $modellingConfig))
        ->build($importSlots, $exportSlots, $halfHourlyUsageKwh, $solarSlots, $currentSocPercent);

    // Clip each of the DP's absolute intervals at calendar-date boundaries — one interval
    // can itself span midnight, so keying by its start date alone would silently drop the
    // portion that belongs to the next date.
    $intervalsByDate = [];
    foreach ($result['intervals'] as $interval) {
        $cursor = $interval['start'];
        while ($cursor < $interval['end']) {
            $dayKey = $cursor->setTimezone($timezone)->format('Y-m-d');
            $dayEnd = (new DateTimeImmutable($dayKey, $timezone))->modify('+1 day');
            $segmentEnd = min($interval['end'], $dayEnd);
            $intervalsByDate[$dayKey][] = [
                'start' => $cursor,
                'end' => $segmentEnd,
                'workMode' => $interval['workMode'],
                'explanation' => $interval['explanation'],
            ];
            $cursor = $segmentEnd;
        }
    }

    $touchedDates = [];
    foreach ($importSlots as $slot) {
        $touchedDates[$slot['from']->setTimezone($timezone)->format('Y-m-d')] = true;
    }

    $scheduleBuilder = new ScheduleBuilder($strategyConfig, $batteryConfig);
    $byDate = [];
    foreach (array_keys($touchedDates) as $forDate) {
        $converted = $scheduleBuilder->absoluteIntervalsToGroups($intervalsByDate[$forDate] ?? []);
        $byDate[$forDate] = ['groups' => $converted['groups'], 'explanations' => $converted['explanations'], 'summary' => $result['summary']];
    }
    ksort($byDate);
    return $byDate;
}

/**
 * Gathers the modelling scheduler's own rolling-window inputs and calls
 * buildModellingSchedule() — shared by Runner.php's runScheduler()/reapplyOverrides() and
 * schedulers.php's preview, so the window-bounds math (which must stay consistent with
 * ScheduleBuilder::buildPushWindow()'s own derivation, or the modelling scheduler would be
 * optimising over a different horizon than what actually ends up pushed) isn't duplicated
 * at each call site.
 *
 * @param array $knownSlots getPriceSlotsFrom()-shaped rows — every currently-known slot
 *        from local midnight today onward; this slices out just the rolling window itself
 *        (start of the current hour through 24h ahead or the end of known pricing,
 *        whichever is sooner)
 * @return array<string, array{groups: array, explanations: string[], summary: string}> for_date => schedule
 */
function buildModellingScheduleForRun(
    array $strategyConfig,
    array $batteryConfig,
    array $modellingConfig,
    array $knownSlots,
    DateTimeImmutable $now,
    DateTimeZone $timezone,
    ?array $solarSlots,
    ?float $currentSocPercent,
): array {
    $localNow = $now->setTimezone($timezone);
    $windowStart = $localNow->setTime((int) $localNow->format('G'), 0, 0);
    $windowEnd = $windowStart->modify('+24 hours');
    $knownDataEnd = getLatestPriceHorizon();
    if ($knownDataEnd !== null && $knownDataEnd < $windowEnd) {
        $windowEnd = $knownDataEnd;
    }

    $importSlots = [];
    $exportSlots = [];
    $exportComplete = true;
    foreach ($knownSlots as $slot) {
        if ($slot['from'] < $windowStart || $slot['from'] >= $windowEnd) {
            continue;
        }
        $importSlots[] = ['from' => $slot['from'], 'to' => $slot['to'], 'rate' => $slot['import_rate']];
        if ($slot['export_rate'] !== null) {
            $exportSlots[] = ['from' => $slot['from'], 'to' => $slot['to'], 'rate' => $slot['export_rate']];
        } else {
            $exportComplete = false;
        }
    }
    if (!$importSlots) {
        throw new ScheduleBuildException('No known price slots fall within the modelling scheduler\'s rolling window');
    }
    $exportSlots = $exportComplete && $exportSlots ? $exportSlots : null;

    // Broad enough to cover HalfHourlyUsageEstimator's own multi-year tier-1 search;
    // passing more history than actually exists is harmless, just an unused query range —
    // same "even years of hourly rows is trivial for SQLite/PHP to sum" precedent
    // getHistoricGeneration() is already documented with elsewhere.
    $historicUsageRows = getHistoricGeneration((new DateTimeImmutable('-10 years', $timezone))->setTime(0, 0), $now);
    $usageSummer = (float) getSetting('usage_summer_kwh_month', '300');
    $usageWinter = (float) getSetting('usage_winter_kwh_month', '700');

    // One HalfHourlyUsageEstimator call per calendar date the window touches (typically
    // just one, occasionally two if the window crosses midnight), each producing that
    // date's own 48-slot forecast — picking the matching half-hour-of-day value per slot.
    $usageForecastByDate = [];
    $halfHourlyUsageKwh = [];
    foreach ($importSlots as $slot) {
        $localFrom = $slot['from']->setTimezone($timezone);
        $forDate = $localFrom->format('Y-m-d');
        if (!isset($usageForecastByDate[$forDate])) {
            $usageForecastByDate[$forDate] = HalfHourlyUsageEstimator::estimateHalfHourly(
                new DateTimeImmutable($forDate, $timezone),
                $timezone,
                $historicUsageRows,
                $usageSummer,
                $usageWinter,
            );
        }
        $halfHourIndex = ((int) $localFrom->format('G')) * 2 + ((int) $localFrom->format('i') >= 30 ? 1 : 0);
        $halfHourlyUsageKwh[] = $usageForecastByDate[$forDate][$halfHourIndex] ?? 0.0;
    }

    return buildModellingSchedule($strategyConfig, $batteryConfig, $modellingConfig, $importSlots, $exportSlots, $halfHourlyUsageKwh, $solarSlots, $currentSocPercent, $timezone);
}
