<?php
// EXPERIMENTAL, not committed — self-check for IntelligentScheduleBuilder only.
// Same check()-style pattern as self_check.php (no assert(), see CLAUDE.md), run
// standalone: php tests/intelligent_check.php

require __DIR__ . '/../src/Exceptions.php';
require __DIR__ . '/../src/IntelligentScheduleBuilder.php';

$checks = 0;
$failures = 0;
function check(bool $cond, string $label): void
{
    global $checks, $failures;
    $checks++;
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

$tz = new DateTimeZone('Europe/London');
$strategy = ['cheap_slots_to_charge' => 6, 'expensive_slots_to_export' => 4, 'timezone' => 'Europe/London'];
$battery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 4.0, 'max_discharge_kw' => 4.0, 'min_soc_on_grid' => 20, 'reserve_soc' => 20];

function makeSlots(array $rates, DateTimeZone $tz, string $startDate): array
{
    $slots = [];
    $t = new DateTimeImmutable($startDate . ' 00:00', $tz);
    foreach ($rates as $rate) {
        $slots[] = ['from' => $t, 'to' => $t->modify('+30 minutes'), 'rate' => $rate];
        $t = $t->modify('+30 minutes');
    }
    return $slots;
}

// --- 48 flat-ish import rates with a cheap early-morning trough and one peak at 18:00 ---
$importRates = array_fill(0, 48, 20.0);
for ($i = 4; $i <= 9; $i++) { $importRates[$i] = 10.0; } // 02:00-05:00 cheap
$importRates[36] = 40.0; // 18:00 peak
$importSlots = makeSlots($importRates, $tz, '2026-06-01');
$costBasis = array_fill(0, 48, 24.5);

// --- Sunny day: plenty of solar, no shortfall expected ---
$sunnySolar = [];
$t = new DateTimeImmutable('2026-06-01 06:00', $tz);
for ($h = 6; $h < 20; $h++) {
    $sunnySolar[] = ['from' => $t, 'to' => $t->modify('+1 hour'), 'watt_hours' => 1500];
    $t = $t->modify('+1 hour');
}

$builder = new IntelligentScheduleBuilder($strategy, $battery, ['avg_daily_kwh' => 8.0]);
$sunny = $builder->build($importSlots, null, $costBasis, $sunnySolar, 50.0);
check(
    !array_filter($sunny['groups'], fn($g) => $g['workMode'] === 'ForceCharge'),
    'plenty of solar + moderate starting SoC means no grid force-charge is needed: got ' . json_encode(array_column($sunny['groups'], 'workMode')),
);

// --- No solar at all, battery starts low: should force-charge in the cheap trough, capped by real need ---
$dark = $builder->build($importSlots, null, $costBasis, null, 15.0); // 15% of 10kWh = 1.5kWh, well below full
$chargeGroups = array_filter($dark['groups'], fn($g) => $g['workMode'] === 'ForceCharge');
check(count($chargeGroups) > 0, 'no solar + low starting SoC forces some grid charging in the cheap window');
foreach ($chargeGroups as $g) {
    check($g['startHour'] >= 2 && $g['startHour'] < 5, 'forced charge lands in the 02:00-05:00 cheap window, not elsewhere: got startHour=' . $g['startHour']);
}

// --- Battery already full: no charging needed even though rates are cheap (real energy bound, not just price) ---
$full = $builder->build($importSlots, null, $costBasis, null, 100.0);
check(
    !array_filter($full['groups'], fn($g) => $g['workMode'] === 'ForceCharge'),
    'a battery already at 100% is not force-charged just because a slot is cheap',
);

// --- Arbitrage: even a full battery should still charge if import is cheaper than the best export rate ---
$exportRates = array_fill(0, 48, 12.0);
$exportRates[10] = 30.0; // one very high export slot makes early cheap import slots arbitrage-worthy
$exportSlots = makeSlots($exportRates, $tz, '2026-06-01');
$arb = $builder->build($importSlots, $exportSlots, $costBasis, null, 100.0);
check(
    (bool) array_filter($arb['groups'], fn($g) => $g['workMode'] === 'ForceCharge'),
    'a full battery still charges on arbitrage (cheap import vs. a much higher export rate later)',
);

// --- Discharge never drains the projected SoC below the reserve floor ---
// No export slots (no arbitrage) and a cost basis below every import rate (no cost-basis
// charging either) — isolates "nothing gets charged, so nothing should get discharged".
$noChargeBasis = array_fill(0, 48, 5.0);
$lowSoc = $builder->build($importSlots, null, $noChargeBasis, null, 22.0); // barely above 20% reserve
check(
    !array_filter($lowSoc['groups'], fn($g) => $g['workMode'] === 'ForceCharge'),
    'sanity check: nothing charges in this scenario (cost basis below every import rate)',
);
$dischargeGroups = array_filter($lowSoc['groups'], fn($g) => $g['workMode'] === 'ForceDischarge');
check(count($dischargeGroups) === 0, 'a battery barely above reserve with nothing charged gets no discharge slots forced: got ' . count($dischargeGroups));

// --- High starting SoC with a variable export rate: discharge should include the export peak ---
$highSoc = $builder->build($importSlots, $exportSlots, $costBasis, null, 90.0);
$dischargeGroups2 = array_values(array_filter($highSoc['groups'], fn($g) => $g['workMode'] === 'ForceDischarge'));
check(count($dischargeGroups2) > 0, 'a battery at 90% with a variable export rate gets some discharge slots');
$coversExportPeak = (bool) array_filter($dischargeGroups2, fn($g) => $g['startHour'] === 5 && $g['startMinute'] === 0);
check($coversExportPeak, 'the 05:00 export-peak slot (30p vs. a flat 12p elsewhere) is among the discharge groups: got ' . json_encode(array_map(fn($g) => "{$g['startHour']}:{$g['startMinute']}", $dischargeGroups2)));

// --- Explanations/groups stay in the same order and count ---
check(count($sunny['groups']) === count($sunny['explanations']), 'groups and explanations arrays stay the same length');

if ($failures > 0) {
    fwrite(STDERR, "\n$failures/$checks checks failed\n");
    exit(1);
}
echo "All $checks checks passed\n";
