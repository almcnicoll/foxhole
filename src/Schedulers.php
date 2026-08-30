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
 * @param ?array $forcedActionsByIndex aligned to $importSlots — see
 *        ModellingScheduleBuilder::build()'s own doc comment; buildModellingScheduleForRun()
 *        is what actually populates this from active overrides via
 *        buildForcedActionsFromOverrides()
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
    ?array $forcedActionsByIndex = null,
): array {
    $result = (new ModellingScheduleBuilder($strategyConfig, $batteryConfig, $modellingConfig))
        ->build($importSlots, $exportSlots, $halfHourlyUsageKwh, $solarSlots, $currentSocPercent, $forcedActionsByIndex);

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
 * Translates active Fill-your-boots/Power-down overrides (Store::getOverridesForDate) into a
 * per-slot compulsory action aligned to $importSlots, so ModellingScheduleBuilder::build()
 * can treat them as hard constraints the DP optimises *around* rather than something painted
 * onto its output afterward (the way ScheduleBuilder::applyOverrides() still works for the
 * other two schedulers — see that method and this function's own use in
 * buildModellingScheduleForRun()). Feeding the override in up front, not after, is what lets
 * the DP's own SoC trajectory correctly account for the override's own charge/discharge
 * before deciding what to do with every other slot — a post-hoc overlay can't do that, since
 * by the time it runs the DP has already committed to a (now-partly-wrong) plan for the rest
 * of the horizon built without knowing the override was coming.
 *
 * Same mode mapping ScheduleBuilder::overrideModesFor() defines, applied here to absolute
 * instants instead of that method's minute-of-day integers, since this needs to work across
 * however many calendar dates the rolling window actually touches. A slot is forced whenever
 * an override window overlaps it *at all*, not only when fully contained — the DP's own
 * half-hour granularity is coarser than an override's free-form <input type="time">
 * boundaries, so a slot straddling an override's edge is still treated as compulsory for its
 * whole half hour. The exact minute-level boundary, and the correct override-specific
 * explanation text (this function has no reason to invent its own — the DP would just narrate
 * a forced slot as if it were freely chosen, which is honestly wrong for it), are both still
 * supplied afterward by the existing post-hoc ScheduleBuilder::applyOverrides() pass
 * (Runner.php) — the two are complementary, not redundant: this function gets the
 * *optimisation* right, that pass gets the *precision and narration* right. Later overrides
 * win on any overlap, the same precedent getOverridesForDate()'s `ORDER BY kind` +
 * applyOverrides()'s sequential subtractInterval() already established.
 *
 * @return array<int, ?string> same length/order as $importSlots — null (free choice) or one
 *         of 'ForceCharge'/'ForceDischarge'/'SelfUse'
 */
function buildForcedActionsFromOverrides(array $importSlots, DateTimeZone $timezone): array
{
    $windowsByDate = [];
    $forced = array_fill(0, count($importSlots), null);
    foreach ($importSlots as $i => $slot) {
        $forDate = $slot['from']->setTimezone($timezone)->format('Y-m-d');
        if (!isset($windowsByDate[$forDate])) {
            $windows = [];
            foreach (getOverridesForDate($forDate) as $override) {
                ['eventMode' => $eventMode, 'prepMode' => $prepMode] = ScheduleBuilder::overrideModesFor($override['kind']);
                if ($override['prep_start'] !== null && $override['prep_end'] !== null) {
                    $windows[] = [$forDate, $timezone, $override['prep_start'], $override['prep_end'], $prepMode];
                }
                $windows[] = [$forDate, $timezone, $override['event_start'], $override['event_end'], $eventMode];
            }
            $windowsByDate[$forDate] = array_values(array_filter(array_map(
                fn($w) => overrideWindowInstants(...$w),
                $windows,
            )));
        }
        foreach ($windowsByDate[$forDate] as $window) {
            if ($slot['from'] < $window['end'] && $slot['to'] > $window['start']) {
                $forced[$i] = $window['mode'];
            }
        }
    }
    return $forced;
}

/** @return ?array{start: DateTimeImmutable, end: DateTimeImmutable, mode: string} null for an invalid/empty window (ignored, same rule ScheduleBuilder::applyOverrides() uses) */
function overrideWindowInstants(string $forDate, DateTimeZone $timezone, string $startStr, string $endStr, string $mode): ?array
{
    $start = new DateTimeImmutable("$forDate $startStr", $timezone);
    $end = new DateTimeImmutable("$forDate $endStr", $timezone);
    return $end > $start ? ['start' => $start, 'end' => $end, 'mode' => $mode] : null;
}

/**
 * The modelling scheduler's own optimisation horizon end — as far ahead as the data
 * actually allows, per the user's explicit ask, rather than a fixed 24h: the DP can only
 * make a good decision about *whether* to hold charge through an expensive period if that
 * period is actually inside the horizon it can see. A DP that always stopped looking 24h
 * out would cheerfully sell everything right at that boundary even when the very next
 * slot outside it is the most expensive of the day — exactly the bug this was written to
 * fix (confirmed live: a horizon ending at 17:00 sold down to the configured floor in the
 * slot right before it, immediately ahead of a 40p+ spike it had no visibility into).
 *
 * Bounded by whichever of price or solar-forecast data runs out first — predicted usage is
 * never the limiting factor, since HalfHourlyUsageEstimator always has an answer (real
 * history, or its own flat fallback — see that class's doc comment) regardless of how far
 * ahead it's asked for. Solar only constrains this when forecast data actually exists;
 * with it disabled/unavailable ($solarSlots null or empty), only the price horizon applies
 * — solar being optional everywhere else in this scheduler (it degrades to no-solar
 * behaviour rather than refusing to run) would make it strange for its mere absence to
 * also refuse to plan ahead on price alone.
 *
 * This is deliberately a separate, usually-longer horizon than what actually reaches
 * FoxESS: the schedule format has no date field, only recurring hour/minute-of-day, so
 * ScheduleBuilder::buildPushWindow() (called downstream on this function's output,
 * regardless of which scheduler produced it) still independently caps the actual push at
 * 24h — a hard constraint of that format, not a business choice this function makes. The
 * DP seeing further than what gets pushed is the whole point: its choices *within* the
 * pushable window come out correctly informed by what it knows is coming right after.
 *
 * @param ?array $solarSlots SolarForecastClient-shaped periods (['from','to','watt_hours']), or null/empty if unavailable
 * @return ?DateTimeImmutable null if there's no known price data to plan from at all
 */
function modellingWindowEnd(?DateTimeImmutable $priceHorizon, ?array $solarSlots): ?DateTimeImmutable
{
    if ($priceHorizon === null) {
        return null;
    }
    if (!$solarSlots) {
        return $priceHorizon;
    }
    $solarHorizon = max(array_column($solarSlots, 'to'));
    return min($priceHorizon, $solarHorizon);
}

/**
 * Gathers the modelling scheduler's own rolling-window inputs and calls
 * buildModellingSchedule() — shared by Runner.php's runScheduler()/reapplyOverrides() and
 * schedulers.php's preview, so the window-bounds math isn't duplicated at each call site.
 *
 * @param array $knownSlots getPriceSlotsFrom()-shaped rows — every currently-known slot
 *        from local midnight today onward; this slices out just the rolling window itself
 *        (start of the current hour through as far as price/solar data allows — see
 *        modellingWindowEnd())
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
    $windowEnd = modellingWindowEnd(getLatestPriceHorizon(), $solarSlots);
    if ($windowEnd === null) {
        throw new ScheduleBuildException('No known price slots to plan the modelling scheduler\'s window from');
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

    $forcedActionsByIndex = buildForcedActionsFromOverrides($importSlots, $timezone);

    return buildModellingSchedule($strategyConfig, $batteryConfig, $modellingConfig, $importSlots, $exportSlots, $halfHourlyUsageKwh, $solarSlots, $currentSocPercent, $timezone, $forcedActionsByIndex);
}
