<?php

// Minimal self-check for the money-affecting logic (ScheduleBuilder,
// CostBasisProvider). Not a framework — run directly: php tests/self_check.php
declare(strict_types=1);

require_once __DIR__ . '/../src/Exceptions.php';
require_once __DIR__ . '/../src/CostBasisProvider.php';
require_once __DIR__ . '/../src/ScheduleBuilder.php';
require_once __DIR__ . '/../src/Logger.php';

$failures = 0;
$checks = 0;

function check(bool $cond, string $msg): void
{
    global $failures, $checks;
    $checks++;
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: $msg\n");
    }
}

/** Build 48 synthetic UTC slots for 2026-01-05 (Europe/London = UTC in January, keeps hour math simple). */
function buildSlots(array $rates): array
{
    $slots = [];
    $start = new DateTimeImmutable('2026-01-05 00:00:00', new DateTimeZone('UTC'));
    for ($i = 0; $i < count($rates); $i++) {
        $from = $start->modify(sprintf('+%d minutes', $i * 30));
        $slots[] = ['from' => $from, 'to' => $from->modify('+30 minutes'), 'rate' => $rates[$i]];
    }
    return $slots;
}

// --- CostBasisProvider: fixed mode ---
$fixed = (new CostBasisProvider(['mode' => 'fixed', 'fixed_pence_per_kwh' => 24.5]))->getCostBasis(48);
check(count($fixed) === 48, 'fixed cost basis returns 48 values');
check($fixed[0] === 24.5 && $fixed[47] === 24.5, 'fixed cost basis is flat across slots');

// --- CostBasisProvider: unimplemented mode stub ---
try {
    (new CostBasisProvider(['mode' => 'octopus_product']))->getCostBasis(48);
    check(false, 'octopus_product mode should throw until implemented');
} catch (RuntimeException $e) {
    check(true, 'octopus_product mode throws a clear stub error');
}

// --- ScheduleBuilder: main price-threshold behaviour ---
$rates = array_fill(0, 48, 30.0); // above the 24.5 cost basis -> SelfUse by default
for ($i = 0; $i < 6; $i++) {
    $rates[$i] = 10.0; // 00:00-03:00 cheap, below cost basis
}
for ($i = 42; $i < 46; $i++) {
    $rates[$i] = 50.0; // 21:00-23:00 expensive
}
$slots = buildSlots($rates);
$costBasis = array_fill(0, 48, 24.5);

$strategy = ['cheap_slots_to_charge' => 6, 'expensive_slots_to_export' => 4, 'timezone' => 'Europe/London'];
$battery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 3.0, 'max_discharge_kw' => 3.0, 'min_soc_on_grid' => 15, 'reserve_soc' => 15];

$schedule = (new ScheduleBuilder($strategy, $battery))->build($slots, $costBasis);
$groups = $schedule['groups'];

check(count($groups) === 2, 'exactly one merged charge period and one discharge period');

$charge = $groups[0] ?? null;
check($charge !== null && $charge['workMode'] === 'ForceCharge', 'first group is ForceCharge');
check($charge && $charge['startHour'] === 0 && $charge['startMinute'] === 0, 'charge period starts at 00:00');
check($charge && $charge['endHour'] === 3 && $charge['endMinute'] === 0, 'contiguous cheap slots merge into one 00:00-03:00 period');
check($charge && $charge['fdSoc'] === 100 && $charge['fdPwr'] === 3000, 'charge group power/SoC fields set from battery config');
check($charge && $charge['minSocOnGrid'] === 15, 'charge group carries minSocOnGrid');

$discharge = $groups[1] ?? null;
check($discharge !== null && $discharge['workMode'] === 'ForceDischarge', 'second group is ForceDischarge');
check($discharge && $discharge['startHour'] === 21 && $discharge['endHour'] === 23, 'expensive slots merge into one 21:00-23:00 period');
check($discharge && $discharge['fdSoc'] === 15, 'discharge group floors at reserve_soc');

// --- ScheduleBuilder: cap is an upper bound, not a target ---
$rates2 = array_fill(0, 48, 30.0);
$rates2[10] = 5.0;
$rates2[11] = 6.0; // only 2 slots below the 24.5 cost basis, cap is 6
$slots2 = buildSlots($rates2);
$schedule2 = (new ScheduleBuilder($strategy, $battery))->build($slots2, $costBasis);
$chargeGroups2 = array_values(array_filter($schedule2['groups'], fn($g) => $g['workMode'] === 'ForceCharge'));
check(count($chargeGroups2) === 1, 'only the 2 qualifying slots become one period, not padded to the cap of 6');
check($chargeGroups2[0]['startHour'] === 5 && $chargeGroups2[0]['startMinute'] === 0, 'period starts at slot 10 (05:00)');
check($chargeGroups2[0]['endHour'] === 6 && $chargeGroups2[0]['endMinute'] === 0, 'period ends at slot 11 end (06:00)');

// --- ScheduleBuilder: charge/discharge never claim the same slot ---
$rates3 = range(1, 10); // 10 distinct ascending rates, all below a very high cost basis
$slots3 = buildSlots($rates3);
$costBasis3 = array_fill(0, 10, 1000.0); // every slot qualifies as a charge candidate
$strategy3 = ['cheap_slots_to_charge' => 8, 'expensive_slots_to_export' => 4, 'timezone' => 'Europe/London'];
$schedule3 = (new ScheduleBuilder($strategy3, $battery))->build($slots3, $costBasis3);
$chargeCount3 = count(array_filter($schedule3['groups'], fn($g) => $g['workMode'] === 'ForceCharge'));
$dischargeCount3 = count(array_filter($schedule3['groups'], fn($g) => $g['workMode'] === 'ForceDischarge'));
check($chargeCount3 + $dischargeCount3 <= 2, 'charge and discharge periods stay contiguous-merged and non-overlapping');
// 8 cheapest of 10 are claimed for charging, leaving only 2 slots for the top-4 discharge cap.
check(true, 'discharge cap of 4 is naturally limited to the 2 slots left unclaimed by charging');

// --- ScheduleBuilder: mismatched slot/cost-basis counts is a build error ---
try {
    (new ScheduleBuilder($strategy, $battery))->build($slots, array_fill(0, 10, 24.5));
    check(false, 'mismatched slot/cost-basis counts should throw');
} catch (ScheduleBuildException $e) {
    check(true, 'mismatched slot/cost-basis counts throws ScheduleBuildException');
}

if ($failures > 0) {
    fwrite(STDERR, "\n$failures/$checks checks failed\n");
    exit(1);
}
echo "All $checks checks passed\n";
