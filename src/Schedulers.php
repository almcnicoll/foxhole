<?php

require_once __DIR__ . '/ScheduleBuilder.php';
require_once __DIR__ . '/IntelligentScheduleBuilder.php';

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
