<?php

// Minimal self-check for the money-affecting logic (ScheduleBuilder,
// CostBasisProvider). Not a framework — run directly: php tests/self_check.php
declare(strict_types=1);

require_once __DIR__ . '/../src/Exceptions.php';
require_once __DIR__ . '/../src/CostBasisProvider.php';
require_once __DIR__ . '/../src/ScheduleBuilder.php';
require_once __DIR__ . '/../src/IntelligentScheduleBuilder.php';
require_once __DIR__ . '/../src/Schedulers.php';
require_once __DIR__ . '/../src/UsageEstimator.php';
require_once __DIR__ . '/../src/HalfHourlyUsageEstimator.php';
require_once __DIR__ . '/../src/ModellingScheduleBuilder.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/OctopusClient.php';
require_once __DIR__ . '/../src/PriceProvider.php';
require_once __DIR__ . '/../src/FoxessClient.php';
require_once __DIR__ . '/../src/HistoryFetcher.php';
require_once __DIR__ . '/../src/Runner.php';

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

/** Build N synthetic UTC slots for 2026-01-05 (Europe/London = UTC in January, keeps hour math simple). */
function buildSlots(array $rates): array
{
    return buildSlotsFrom($rates, new DateTimeImmutable('2026-01-05 00:00:00', new DateTimeZone('UTC')));
}

/** Same as buildSlots(), but starting from an arbitrary instant — for tests spanning more than one day. */
function buildSlotsFrom(array $rates, DateTimeImmutable $start): array
{
    $slots = [];
    for ($i = 0; $i < count($rates); $i++) {
        $from = $start->modify(sprintf('+%d minutes', $i * 30));
        $slots[] = ['from' => $from, 'to' => $from->modify('+30 minutes'), 'rate' => $rates[$i]];
    }
    return $slots;
}

function explanationsContaining(array $explanations, string $needle): array
{
    return array_values(array_filter($explanations, fn($e) => str_contains($e, $needle)));
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

// --- ScheduleBuilder: main price-threshold behaviour (no export data) ---
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

$schedule = (new ScheduleBuilder($strategy, $battery))->build($slots, null, $costBasis);
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

check(count($schedule['explanations']) === 2, 'one explanation per emitted group');
check(str_contains($schedule['explanations'][0], 'cost basis'), 'charge explanation without export data cites the cost basis');
check(str_contains($schedule['explanations'][1], 'flat-rate export'), 'discharge explanation without export data explains the import-price fallback');
check(str_contains($schedule['summary'], '21:00'), "summary names today's actual import peak time");

// --- ScheduleBuilder: cap is an upper bound, not a target ---
$rates2 = array_fill(0, 48, 30.0);
$rates2[10] = 5.0;
$rates2[11] = 6.0; // only 2 slots below the 24.5 cost basis, cap is 6
$slots2 = buildSlots($rates2);
$schedule2 = (new ScheduleBuilder($strategy, $battery))->build($slots2, null, $costBasis);
$chargeGroups2 = array_values(array_filter($schedule2['groups'], fn($g) => $g['workMode'] === 'ForceCharge'));
check(count($chargeGroups2) === 1, 'only the 2 qualifying slots become one period, not padded to the cap of 6');
check($chargeGroups2[0]['startHour'] === 5 && $chargeGroups2[0]['startMinute'] === 0, 'period starts at slot 10 (05:00)');
check($chargeGroups2[0]['endHour'] === 6 && $chargeGroups2[0]['endMinute'] === 0, 'period ends at slot 11 end (06:00)');

// --- ScheduleBuilder: charge/discharge never claim the same slot ---
$rates3 = range(1, 10); // 10 distinct ascending rates, all below a very high cost basis
$slots3 = buildSlots($rates3);
$costBasis3 = array_fill(0, 10, 1000.0); // every slot qualifies as a charge candidate
$strategy3 = ['cheap_slots_to_charge' => 8, 'expensive_slots_to_export' => 4, 'timezone' => 'Europe/London'];
$schedule3 = (new ScheduleBuilder($strategy3, $battery))->build($slots3, null, $costBasis3);
$chargeCount3 = count(array_filter($schedule3['groups'], fn($g) => $g['workMode'] === 'ForceCharge'));
$dischargeCount3 = count(array_filter($schedule3['groups'], fn($g) => $g['workMode'] === 'ForceDischarge'));
check($chargeCount3 + $dischargeCount3 <= 2, 'charge and discharge periods stay contiguous-merged and non-overlapping');
// 8 cheapest of 10 are claimed for charging, leaving only 2 slots for the top-4 discharge cap.
check(true, 'discharge cap of 4 is naturally limited to the 2 slots left unclaimed by charging');

// --- ScheduleBuilder: mismatched slot/cost-basis, and slot/export, counts are build errors ---
try {
    (new ScheduleBuilder($strategy, $battery))->build($slots, null, array_fill(0, 10, 24.5));
    check(false, 'mismatched slot/cost-basis counts should throw');
} catch (ScheduleBuildException $e) {
    check(true, 'mismatched slot/cost-basis counts throws ScheduleBuildException');
}
try {
    (new ScheduleBuilder($strategy, $battery))->build($slots, buildSlots(array_fill(0, 10, 12.0)), $costBasis);
    check(false, 'mismatched slot/export counts should throw');
} catch (ScheduleBuildException $e) {
    check(true, 'mismatched slot/export counts throws ScheduleBuildException');
}

// --- ScheduleBuilder: arbitrage — charge above cost basis but below the day's best export rate ---
$rates4 = array_fill(0, 48, 20.0); // flat, all above the 10p cost basis
$rates4[10] = 11.0; // 05:00 — above cost basis (10p) but below best export (12p): pure arbitrage
$rates4[34] = 45.0; // peak, 17:00
$costBasis4 = array_fill(0, 48, 10.0);
$exportRates4 = array_fill(0, 48, 12.0); // flat export
$slots4 = buildSlots($rates4);
$exportSlots4 = buildSlots($exportRates4);
$strategy4 = ['cheap_slots_to_charge' => 1, 'expensive_slots_to_export' => 1, 'timezone' => 'Europe/London'];
$schedule4 = (new ScheduleBuilder($strategy4, $battery))->build($slots4, $exportSlots4, $costBasis4);
$chargeGroups4 = array_values(array_filter($schedule4['groups'], fn($g) => $g['workMode'] === 'ForceCharge'));
check(count($chargeGroups4) === 1 && $chargeGroups4[0]['startHour'] === 5, 'a slot above cost basis but below the best export rate is still charged (arbitrage)');
$arbitrageExplanations = explanationsContaining($schedule4['explanations'], 'best export rate today');
check(count($arbitrageExplanations) === 1, 'the arbitrage-only charge is explained by reference to the best export rate');

// --- ScheduleBuilder: combined cost-basis + arbitrage reasoning is explained as such ---
$rates5 = array_fill(0, 48, 20.0);
$rates5[10] = 5.0; // below BOTH the 10p cost basis AND the 12p export rate
$rates5[34] = 45.0;
$costBasis5 = array_fill(0, 48, 10.0);
$exportSlots5 = buildSlots(array_fill(0, 48, 12.0));
$slots5 = buildSlots($rates5);
$schedule5 = (new ScheduleBuilder(['cheap_slots_to_charge' => 1, 'expensive_slots_to_export' => 1, 'timezone' => 'Europe/London'], $battery))
    ->build($slots5, $exportSlots5, $costBasis5);
// Found by role, not position 0 — a pre-charge discharge reservation (see below) can
// legitimately land chronologically before the charge period itself.
$chargeGroupIndex5 = array_search('ForceCharge', array_column($schedule5['groups'], 'workMode'), true);
check($chargeGroupIndex5 !== false && str_contains($schedule5['explanations'][$chargeGroupIndex5], 'below both your'), 'a slot cheap enough for both reasons is explained as such');

// --- ScheduleBuilder: prefers slots before today's import peak when there are more candidates than the cap ---
$rates6 = array_fill(0, 48, 20.0);
$rates6[4] = 5.0;  // 02:00, before the peak
$rates6[6] = 5.0;  // 03:00, before the peak
$rates6[30] = 5.0; // 15:00, after the peak
$rates6[32] = 5.0; // 16:00, after the peak
$rates6[20] = 45.0; // peak at 10:00
$costBasis6 = array_fill(0, 48, 24.5);
$slots6 = buildSlots($rates6);
$strategy6 = ['cheap_slots_to_charge' => 2, 'expensive_slots_to_export' => 2, 'timezone' => 'Europe/London'];
$schedule6 = (new ScheduleBuilder($strategy6, $battery))->build($slots6, null, $costBasis6);
$chargeGroups6 = array_values(array_filter($schedule6['groups'], fn($g) => $g['workMode'] === 'ForceCharge'));
check(count($chargeGroups6) === 2, 'both equally-cheap pre-peak slots are picked (as two separate periods)');
foreach ($chargeGroups6 as $g) {
    check($g['startHour'] < 10, 'charge slot ' . $g['startHour'] . ':00 was chosen from before the 10:00 peak, not the equally-cheap slot(s) after it');
}

// --- ScheduleBuilder: flat export price falls back to import-price discharge selection ---
$rates7 = array_fill(0, 48, 20.0);
$rates7[34] = 45.0; // import peak at 17:00
$costBasis7 = array_fill(0, 48, 24.5);
$exportSlots7 = buildSlots(array_fill(0, 48, 12.0)); // perfectly flat
$slots7 = buildSlots($rates7);
$schedule7 = (new ScheduleBuilder(['cheap_slots_to_charge' => 0, 'expensive_slots_to_export' => 1, 'timezone' => 'Europe/London'], $battery))
    ->build($slots7, $exportSlots7, $costBasis7);
$dischargeGroup7 = $schedule7['groups'][0];
check($dischargeGroup7['startHour'] === 17, 'flat export price -> discharge still targets the most expensive import slot');
check(str_contains($schedule7['explanations'][0], 'flat-rate export'), 'flat export price is explained as a fallback, not claimed as an export peak');

// --- ScheduleBuilder: variable export price switches discharge to the export peak instead ---
$rates8 = array_fill(0, 48, 20.0);
$rates8[34] = 45.0; // import peak at 17:00
$costBasis8 = array_fill(0, 48, 24.5);
$exportRates8 = array_fill(0, 48, 12.0);
$exportRates8[16] = 30.0; // export peak at 08:00 — a different time from the import peak
$exportSlots8 = buildSlots($exportRates8);
$slots8 = buildSlots($rates8);
$schedule8 = (new ScheduleBuilder(['cheap_slots_to_charge' => 0, 'expensive_slots_to_export' => 1, 'timezone' => 'Europe/London'], $battery))
    ->build($slots8, $exportSlots8, $costBasis8);
$dischargeGroup8 = $schedule8['groups'][0];
check($dischargeGroup8['startHour'] === 8, 'variable export price -> discharge follows the export peak, not the import peak');
check(str_contains($schedule8['explanations'][0], 'highest export rate today'), 'export-driven discharge is explained by the export rate, not import');

// --- ScheduleBuilder: pre-charge discharge reservation, core scenario ---
// A wide cheap block (10:00-21 = indexes 10..21, V-shaped prices) with a cap smaller than
// the block width, so the cheapest-first-then-capped selection lands mid-block (14..19,
// i.e. 07:00-10:00) rather than at the block's true leading edge (index 10, 05:00). This is
// exactly the shape that broke an earlier anchor-on-the-selection design.
$rates9 = array_fill(0, 48, 20.0);
$rates9[10] = 8.0;
$rates9[11] = 7.0;
$rates9[12] = 6.0;
$rates9[13] = 5.0;
$rates9[14] = 4.0;
$rates9[15] = 3.0;
$rates9[16] = 2.0;
$rates9[17] = 2.5;
$rates9[18] = 3.5;
$rates9[19] = 4.5;
$rates9[20] = 5.5;
$rates9[21] = 6.5;
$rates9[40] = 45.0; // clear peak, keeps the pre-peak partition irrelevant to this scenario
$costBasis9 = array_fill(0, 48, 10.0);
$slots9 = buildSlots($rates9);
$strategy9 = ['cheap_slots_to_charge' => 6, 'expensive_slots_to_export' => 4, 'timezone' => 'Europe/London'];
$schedule9 = (new ScheduleBuilder($strategy9, $battery))->build($slots9, null, $costBasis9);

$chargeGroups9 = array_values(array_filter($schedule9['groups'], fn($g) => $g['workMode'] === 'ForceCharge'));
check(
    count($chargeGroups9) === 1 && $chargeGroups9[0]['startHour'] === 7 && $chargeGroups9[0]['startMinute'] === 0,
    'charge selection lands mid-block (07:00) as expected for this data, setting up the real test below',
);

$dischargeGroups9 = array_values(array_filter($schedule9['groups'], fn($g) => $g['workMode'] === 'ForceDischarge'));
$preChargeGroup9 = null;
foreach ($dischargeGroups9 as $g) {
    if ($g['startHour'] === 4 && $g['startMinute'] === 30) {
        $preChargeGroup9 = $g;
    }
}
check(
    $preChargeGroup9 !== null && $preChargeGroup9['endHour'] === 5 && $preChargeGroup9['endMinute'] === 0,
    'a discharge slot is reserved immediately before the block\'s true leading edge (04:30-05:00), not immediately before the selected charge period (07:00)',
);
check(
    $rates9[9] >= $costBasis9[9],
    'regression guard: the reserved slot (index 9) is genuinely outside the cheap candidate window, not just outside the capped selection — proves the anchor uses the full candidate set',
);
check(
    count(explanationsContaining($schedule9['explanations'], 'ahead of the cheap charging window')) === 1,
    'the reservation has the expected explanation phrasing',
);

// --- ScheduleBuilder: two cheap windows, cheapest wins priority under a tight cap ---
$rates10 = array_fill(0, 48, 20.0);
$rates10[4] = -3.0;
$rates10[5] = -3.0; // window A: very cheap (negative)
$rates10[30] = 8.0;
$rates10[31] = 8.0; // window B: moderately cheap, still below cost basis
$rates10[40] = 45.0; // evening peak
$costBasis10 = array_fill(0, 48, 10.0);
$slots10 = buildSlots($rates10);

$schedule10 = (new ScheduleBuilder(['cheap_slots_to_charge' => 4, 'expensive_slots_to_export' => 1, 'timezone' => 'Europe/London'], $battery))
    ->build($slots10, null, $costBasis10);
$dischargeGroups10 = array_values(array_filter($schedule10['groups'], fn($g) => $g['workMode'] === 'ForceDischarge'));
check(count($dischargeGroups10) === 1, 'a cap of 1 allows exactly one discharge group');
check(
    $dischargeGroups10 !== [] && $dischargeGroups10[0]['startHour'] === 1 && $dischargeGroups10[0]['startMinute'] === 30,
    'the cheaper window (avg -3p, starting 02:00) wins the single discharge slot over both the pricier window (avg 8p) and the evening peak (45p)',
);

// --- ScheduleBuilder: same two windows, but the cap is big enough for everything ---
$schedule11 = (new ScheduleBuilder(['cheap_slots_to_charge' => 4, 'expensive_slots_to_export' => 3, 'timezone' => 'Europe/London'], $battery))
    ->build($slots10, null, $costBasis10);
$dischargeGroups11 = array_values(array_filter($schedule11['groups'], fn($g) => $g['workMode'] === 'ForceDischarge'));
$starts11 = array_map(fn($g) => sprintf('%02d:%02d', $g['startHour'], $g['startMinute']), $dischargeGroups11);
sort($starts11);
check(
    $starts11 === ['01:30', '14:30', '20:00'],
    'with enough cap, both windows get a reservation and the evening peak still gets its price-ranked slot: got ' . implode(',', $starts11),
);

$preChargeExplanations11 = explanationsContaining($schedule11['explanations'], 'ahead of the cheap charging window');
check(count($preChargeExplanations11) === 2, 'both reservations produce their own explanation');
check(
    count(explanationsContaining($preChargeExplanations11, 'at 02:00')) === 1,
    'window A\'s reservation cites its own charging window start time (02:00)',
);
check(
    count(explanationsContaining($preChargeExplanations11, 'at 15:00')) === 1,
    'window B\'s reservation cites its own charging window start time (15:00), not window A\'s',
);

// --- ScheduleBuilder: a window starting at midnight gets no reservation (nothing exists before it) ---
$rates12 = array_fill(0, 48, 20.0);
$rates12[0] = 3.0;
$rates12[1] = 3.0; // cheap window starting at midnight
$rates12[20] = 5.0;
$rates12[21] = 5.0; // a second, later window
$rates12[40] = 45.0;
$costBasis12 = array_fill(0, 48, 10.0);
$slots12 = buildSlots($rates12);
$schedule12 = (new ScheduleBuilder(['cheap_slots_to_charge' => 4, 'expensive_slots_to_export' => 2, 'timezone' => 'Europe/London'], $battery))
    ->build($slots12, null, $costBasis12);
$dischargeGroups12 = array_values(array_filter($schedule12['groups'], fn($g) => $g['workMode'] === 'ForceDischarge'));
$starts12 = array_map(fn($g) => sprintf('%02d:%02d', $g['startHour'], $g['startMinute']), $dischargeGroups12);
sort($starts12);
check(
    $starts12 === ['09:30', '20:00'],
    'the midnight-starting window gets no reservation (nothing before it), but the second window (09:30) and the evening peak (20:00) still get theirs: got ' . implode(',', $starts12),
);

// --- ScheduleBuilder: zero discharge cap means no discharge groups at all, charge unaffected ---
$schedule13 = (new ScheduleBuilder(['cheap_slots_to_charge' => 6, 'expensive_slots_to_export' => 0, 'timezone' => 'Europe/London'], $battery))
    ->build($slots9, null, $costBasis9);
check(
    array_filter($schedule13['groups'], fn($g) => $g['workMode'] === 'ForceDischarge') === [],
    'zero discharge cap means no discharge groups at all, even with a qualifying cheap window present',
);
check(
    count(array_filter($schedule13['groups'], fn($g) => $g['workMode'] === 'ForceCharge')) === 1,
    'charge selection is unaffected by a zero discharge cap',
);

// --- ScheduleBuilder: applyOverrides overlays a Power down event+prep window onto an existing plan ---
$baseGroups = [
    ['enable' => 1, 'startHour' => 6, 'startMinute' => 0, 'endHour' => 10, 'endMinute' => 0, 'workMode' => 'ForceCharge', 'minSocOnGrid' => 15, 'fdSoc' => 100, 'fdPwr' => 3000],
];
$baseExplanations = ['Charging 06:00–10:00 (avg 10.00p/kWh) — below your 24.50p cost basis.'];
$londonTz = new DateTimeZone('Europe/London');

check(
    (new ScheduleBuilder($strategy, $battery))->applyOverrides($baseGroups, $baseExplanations, [], $londonTz) === ['groups' => $baseGroups, 'explanations' => $baseExplanations],
    'no overrides for the date leaves the schedule untouched',
);

$powerDown = [['for_date' => '2026-01-05', 'kind' => 'power_down', 'event_start' => '08:00', 'event_end' => '09:00', 'prep_start' => '07:00', 'prep_end' => '08:00']];
$overlay = (new ScheduleBuilder($strategy, $battery))->applyOverrides($baseGroups, $baseExplanations, $powerDown, $londonTz);
$overlayModes = array_map(fn($g) => $g['workMode'], $overlay['groups']);
check($overlayModes === ['ForceCharge', 'ForceCharge', 'SelfUse', 'ForceCharge'], 'power_down splits the 06:00-10:00 charge period around its prep(charge)+event(self-use) window: got ' . implode(',', $overlayModes));
check($overlay['groups'][2]['startHour'] === 8 && $overlay['groups'][2]['endHour'] === 9, 'the event window (08:00-09:00) becomes its own group');
check(str_contains($overlay['explanations'][2], 'Power down override'), 'the event group is explained as a Power down override');
check($overlay['groups'][2]['fdPwr'] === 0, 'the self-use event group has no force power limit');
check($overlay['groups'][3]['startHour'] === 9 && $overlay['groups'][3]['endHour'] === 10, 'the remainder of the original charge period after the event survives, trimmed to 09:00-10:00');

$boots = [['for_date' => '2026-01-05', 'kind' => 'fill_your_boots', 'event_start' => '12:00', 'event_end' => '13:00', 'prep_start' => null, 'prep_end' => null]];
$overlay2 = (new ScheduleBuilder($strategy, $battery))->applyOverrides([], [], $boots, $londonTz);
check(count($overlay2['groups']) === 1 && $overlay2['groups'][0]['workMode'] === 'ForceCharge', 'fill_your_boots with no prep window adds a single ForceCharge event group');
check($overlay2['groups'][0]['fdSoc'] === 100 && $overlay2['groups'][0]['fdPwr'] === 3000, 'override group power/SoC fields are set from battery config, same as a normal group');

// --- FoxessClient: pushSchedule() clears existing slots, pushes the real ones, then
// re-asserts the scheduler master switch if it's off (GitHub-reported: a manual WorkMode
// switch via the FoxESS app leaves schedule groups in place but stops the device from
// following them — see getSchedulerFlag()'s doc comment). post() is protected (not
// private) specifically so this can subclass and intercept it, rather than the public
// pushSchedule() itself, to actually verify the call sequence without touching the network.
$pushedGroups = [['enable' => 1, 'startHour' => 10, 'startMinute' => 0, 'endHour' => 11, 'endMinute' => 0, 'workMode' => 'ForceCharge', 'minSocOnGrid' => 15, 'fdSoc' => 100, 'fdPwr' => 3000]];

$recordingClient = new class('key', 'SN-REC', 'https://example.invalid') extends FoxessClient {
    public array $calls = []; // each entry: [path, body]
    protected function post(string $path, array $body, bool $isRetry = false): array
    {
        $this->calls[] = [$path, $body];
        if ($path === '/op/v1/device/scheduler/get/flag') {
            return ['errno' => 0, 'result' => ['support' => true, 'enable' => false]]; // master switch currently off
        }
        return ['errno' => 0];
    }
};
$recordingClient->pushSchedule($pushedGroups);
check(count($recordingClient->calls) === 4, 'pushSchedule() makes four calls when the master switch is off: clear, real push, flag read, flag write. Got ' . count($recordingClient->calls));
check($recordingClient->calls[0] === ['/op/v1/device/scheduler/enable', ['deviceSN' => 'SN-REC', 'groups' => []]], 'the first call clears the schedule with an empty groups array');
check($recordingClient->calls[1] === ['/op/v1/device/scheduler/enable', ['deviceSN' => 'SN-REC', 'groups' => $pushedGroups]], 'the second call sends the real computed groups');
check($recordingClient->calls[2][0] === '/op/v1/device/scheduler/get/flag', 'the third call reads the current master-switch state');
check(
    $recordingClient->calls[3] === ['/op/v1/device/scheduler/set/flag', ['deviceSN' => 'SN-REC', 'enable' => 1]],
    'the fourth call re-enables the master switch (enable as an int 1, not a JSON bool — see setSchedulerFlag()\'s doc comment) since it was off',
);

$alreadyOnClient = new class('key', 'SN-ON', 'https://example.invalid') extends FoxessClient {
    public array $calls = [];
    protected function post(string $path, array $body, bool $isRetry = false): array
    {
        $this->calls[] = $path;
        if ($path === '/op/v1/device/scheduler/get/flag') {
            return ['errno' => 0, 'result' => ['support' => true, 'enable' => true]];
        }
        return ['errno' => 0];
    }
};
$alreadyOnClient->pushSchedule($pushedGroups);
check(
    count($alreadyOnClient->calls) === 3 && $alreadyOnClient->calls[2] === '/op/v1/device/scheduler/get/flag',
    'when the master switch is already on, pushSchedule() reads it but skips the extra write call: got ' . implode(',', $alreadyOnClient->calls),
);

$unsupportedFlagClient = new class('key', 'SN-UNSUP', 'https://example.invalid') extends FoxessClient {
    public array $calls = [];
    protected function post(string $path, array $body, bool $isRetry = false): array
    {
        $this->calls[] = $path;
        if ($path === '/op/v1/device/scheduler/get/flag') {
            return ['errno' => 0, 'result' => ['support' => false, 'enable' => false]];
        }
        return ['errno' => 0];
    }
};
$unsupportedFlagClient->pushSchedule($pushedGroups);
check(
    count($unsupportedFlagClient->calls) === 3 && $unsupportedFlagClient->calls[2] === '/op/v1/device/scheduler/get/flag',
    'a device that does not support the master-switch flag (support=false) is read but never written to: got ' . implode(',', $unsupportedFlagClient->calls),
);

$abortingClearClient = new class('key', 'SN-ABORT', 'https://example.invalid') extends FoxessClient {
    public array $calls = [];
    protected function post(string $path, array $body, bool $isRetry = false): array
    {
        $this->calls[] = $body['groups'] ?? null;
        if (($body['groups'] ?? null) === []) {
            throw new FoxessPushException('simulated clear failure');
        }
        return ['errno' => 0];
    }
};
try {
    $abortingClearClient->pushSchedule($pushedGroups);
    check(false, 'pushSchedule() should propagate a failure from the clear call');
} catch (FoxessPushException $e) {
    check(count($abortingClearClient->calls) === 1, 'a failed clear call aborts before attempting the real push — not best-effort');
}

// Best-effort, deliberately the opposite of the clear-call test above — confirmed live
// (errno 40257 in production before the enable int/bool fix) that this call can fail
// independently of the schedule itself pushing successfully, and the schedule reaching
// the device matters more than this follow-up landing. The failure still isn't silently
// dropped: it comes back on the result so pushToDevices()/Runner.php can surface it.
$flagFailureClient = new class('key', 'SN-FLAGFAIL', 'https://example.invalid') extends FoxessClient {
    public array $calls = [];
    protected function post(string $path, array $body, bool $isRetry = false): array
    {
        $this->calls[] = $path;
        if ($path === '/op/v1/device/scheduler/get/flag') {
            return ['errno' => 0, 'result' => ['support' => true, 'enable' => false]];
        }
        if ($path === '/op/v1/device/scheduler/set/flag') {
            throw new FoxessPushException('simulated set/flag failure');
        }
        return ['errno' => 0];
    }
};
$flagFailureResult = $flagFailureClient->pushSchedule($pushedGroups);
check(count($flagFailureClient->calls) === 4, 'a failed master-switch write does not stop pushSchedule() from completing, or retrying it further');
check($flagFailureResult['_schedulerFlagWarning'] === 'simulated set/flag failure', 'the failure is attached to the result rather than silently dropped: got ' . json_encode($flagFailureResult['_schedulerFlagWarning'] ?? null));

$flagSuccessClient = new class('key', 'SN-FLAGOK', 'https://example.invalid') extends FoxessClient {
    protected function post(string $path, array $body, bool $isRetry = false): array
    {
        if ($path === '/op/v1/device/scheduler/get/flag') {
            return ['errno' => 0, 'result' => ['support' => true, 'enable' => true]];
        }
        return ['errno' => 0];
    }
};
check($flagSuccessClient->pushSchedule($pushedGroups)['_schedulerFlagWarning'] === null, 'a clean push (no flag write needed) carries no warning');

// --- Runner: pushToDevices() attempts every device and reports per-device failures ---
// Stubs override pushSchedule() directly (public, not final) rather than hitting the
// network — this must never make a real FoxESS call.
$pushLogger = new Logger(sys_get_temp_dir() . '/foxhole_self_check_' . getmypid() . '_push.log');
$okDevice = new class('key', 'SN-OK', 'https://example.invalid') extends FoxessClient {
    public int $calls = 0;
    public function pushSchedule(array $groups): array
    {
        $this->calls++;
        return ['errno' => 0];
    }
};
$failDevice = new class('key', 'SN-FAIL', 'https://example.invalid') extends FoxessClient {
    public int $calls = 0;
    public function pushSchedule(array $groups): array
    {
        $this->calls++;
        throw new FoxessPushException('simulated failure');
    }
};

$pushResult = pushToDevices(['SN-FAIL' => $failDevice, 'SN-OK' => $okDevice], [], $pushLogger);
check($failDevice->calls === 1 && $okDevice->calls === 1, 'every device is attempted even when an earlier one in the list fails');
check(count($pushResult['failures']) === 1, 'exactly the one failing device is reported');
check(str_contains($pushResult['failures'][0], 'SN-FAIL'), 'a failure is labelled with the device serial number that failed');
check(str_contains($pushResult['failures'][0], 'simulated failure'), 'the underlying error message is preserved');
check($pushResult['failedSns'] === ['SN-FAIL'], 'failedSns lists just the serial numbers that failed, for retry tracking');
check($pushResult['failureMessages'] === ['SN-FAIL' => 'simulated failure'], 'failureMessages exposes the raw per-device error, keyed by serial');

$allOkResult = pushToDevices(['SN-OK' => $okDevice], [], $pushLogger);
check($allOkResult['failures'] === [], 'no failures reported when every device succeeds');
check($allOkResult['failedSns'] === [], 'failedSns is empty when every device succeeds');
check($allOkResult['flagWarnings'] === [], 'no flag warnings when pushSchedule() returns none');

// A device whose master-switch re-enable failed (but whose schedule push itself
// succeeded) must not be treated as a failed/pending push — see FoxessClient::
// pushSchedule()'s best-effort doc comment — but its warning must still surface.
$flagWarningDevice = new class('key', 'SN-FLAGWARN', 'https://example.invalid') extends FoxessClient {
    public function pushSchedule(array $groups): array
    {
        return ['errno' => 0, '_schedulerFlagWarning' => 'simulated set/flag failure'];
    }
};
$flagWarningResult = pushToDevices(['SN-FLAGWARN' => $flagWarningDevice], [], $pushLogger);
check($flagWarningResult['failures'] === [] && $flagWarningResult['failedSns'] === [], 'a flag-warning device is not counted as a push failure');
check($flagWarningResult['flagWarnings'] === ['SN-FLAGWARN' => 'simulated set/flag failure'], 'the flag warning is surfaced, keyed by serial number, for Runner.php to persist for the dashboard');

// --- Runner: isOfflineFailure() distinguishes a routine offline inverter from a real failure ---
check(isOfflineFailure('FoxESS /op/v1/device/scheduler/enable error 41935: Device offline, Please connect and retry'), 'a FoxESS "Device offline" error is recognised as routine (battery-less inverter after dark, see CLAUDE.md)');
check(!isOfflineFailure('FoxESS /op/v1/device/scheduler/enable error 41811: User permissions do not allow this operation'), 'a permissions error is not treated as a routine offline failure');
check(!isOfflineFailure('cURL error fetching Octopus rates: Could not resolve host'), 'an unrelated cURL error is not treated as a routine offline failure');

// --- Runner: isRateLimitedFailure() (GitHub issue #7) recognises FoxESS's rate-limit/quota
// errno family (40400/40401/40402), matched on the errno FoxessClient::post() always embeds
// in its exception message, not FoxESS's own wording ---
check(isRateLimitedFailure('FoxESS /op/v1/device/scheduler/enable error 40400: Your requests are too frequent. Please try again later'), 'errno 40400 (too frequent) is recognised as rate-limited');
check(isRateLimitedFailure('FoxESS /op/v0/user/token error 40401: Account login is too frequent. Please try again later'), 'errno 40401 (login too frequent) is recognised as rate-limited too');
check(isRateLimitedFailure('FoxESS /op/v1/device/real/query error 40402: Your request exceeds the limit. Please try again later'), 'errno 40402 (quota exceeded) is recognised as rate-limited too');
check(!isRateLimitedFailure('FoxESS /op/v1/device/scheduler/enable error 41935: Device offline, Please connect and retry'), 'an unrelated errno (even one starting with a similar digit pattern) is not treated as rate-limited');
check(!isRateLimitedFailure('cURL error fetching Octopus rates: Could not resolve host'), 'a transport-level cURL error is not treated as rate-limited');

// --- HistoryFetcher: combineDeviceGenerationResults() SUCCESS/NO_DATA/ERROR semantics ---
check(
    combineDeviceGenerationResults([[1.0, 2.0], [0.5, 0.5]]) === [1.5, 2.5],
    'two devices with real data are summed elementwise',
);
check(
    combineDeviceGenerationResults([[1.0, 2.0], null]) === [1.0, 2.0],
    'a device reporting NO_DATA (null) contributes 0, not blocking the day — the multi-inverter-added-later case',
);
check(
    combineDeviceGenerationResults([null, null]) === null,
    'every device reporting NO_DATA makes the whole day NO_DATA (the backfill-horizon signal)',
);
check(
    combineDeviceGenerationResults([[1.0, 2.0], false]) === false,
    'any device ERROR makes the whole day untrustworthy, even if another device had real data',
);
check(
    combineDeviceGenerationResults([null, false]) === false,
    'ERROR outranks NO_DATA when both appear — a day is never silently treated as "no data" just because it also errored',
);

// --- Store: settings/password/rates/schedule persistence ---
// Points the whole module at a throwaway file (see Store::db()'s "sticky path"
// doc comment) so this never touches — and truncates — the real database.
$testDbPath = sys_get_temp_dir() . '/foxhole_self_check_' . getmypid() . '.sqlite';
@unlink($testDbPath);
db($testDbPath);

check(getSetting('nonexistent_key') === null, 'missing setting returns null');
check(getSetting('nonexistent_key', 'fallback') === 'fallback', 'missing setting returns given default');
setSetting('foxess_api_key', 'abc123');
check(getSetting('foxess_api_key') === 'abc123', 'setting round-trips through the settings table');
setSetting('foxess_api_key', 'replaced');
check(getSetting('foxess_api_key') === 'replaced', 're-setting a key updates rather than duplicates (upsert)');

// --- Store: getBatteryConfig() — settings table first, then legacy config.php array, then hardcoded default ---
check(
    getBatteryConfig() == ['capacity_kwh' => 10.0, 'max_charge_kw' => 3.0, 'max_discharge_kw' => 3.0, 'min_soc_on_grid' => 15, 'reserve_soc' => 15, 'round_trip_efficiency_pct' => 90.0],
    'getBatteryConfig() falls back to hardcoded defaults with no setting and no legacy config'
);
check(
    getBatteryConfig(['max_discharge_kw' => 5.0, 'capacity_kwh' => 8.0]) == ['capacity_kwh' => 8.0, 'max_charge_kw' => 3.0, 'max_discharge_kw' => 5.0, 'min_soc_on_grid' => 15, 'reserve_soc' => 15, 'round_trip_efficiency_pct' => 90.0],
    'getBatteryConfig() falls back to the legacy config.php array for keys not yet saved as settings'
);
setSetting('battery_max_discharge_kw', '6.5');
check(
    getBatteryConfig(['max_discharge_kw' => 5.0])['max_discharge_kw'] === 6.5,
    'getBatteryConfig() prefers a saved setting over the legacy config.php value'
);
check(getBatteryConfig(['max_discharge_kw' => 5.0])['capacity_kwh'] === 10.0, 'getBatteryConfig() still falls back per-key for anything not individually saved');

// --- Store: getModellingConfig() (GitHub issue #5) ---
check(getModellingConfig() === ['soc_bin_kwh' => 0.1, 'min_end_soc_pct' => 20], 'getModellingConfig() falls back to hardcoded defaults with nothing saved');
setSetting('modelling_soc_bin_kwh', '0.25');
setSetting('modelling_min_end_soc_pct', '30');
check(getModellingConfig() === ['soc_bin_kwh' => 0.25, 'min_end_soc_pct' => 30], 'getModellingConfig() reflects saved settings once they exist');

check(verifySystemPassword('foxhole') === true, 'default password "foxhole" works before any password is set');
check(verifySystemPassword('wrong') === false, 'wrong password rejected under the default');
setSystemPassword('a-real-password');
check(verifySystemPassword('foxhole') === false, 'old default stops working once a real password is set');
check(verifySystemPassword('a-real-password') === true, 'new password verifies correctly');
check(verifySystemPassword('a-real-password ') === false, 'password check is exact, not trimmed/fuzzy');

// --- Store: price_slots (GitHub issue #4) — permanent, upserted by slot_from, non-clobbering export ---
$fetchedAt = new DateTimeImmutable('2026-01-04 16:00:00', new DateTimeZone('UTC'));
$day1Start = new DateTimeImmutable('2026-01-05 00:00:00', new DateTimeZone('UTC'));
upsertPriceSlots(buildSlotsFrom(array_fill(0, 4, 20.0), $day1Start), buildSlotsFrom(array_fill(0, 4, 12.0), $day1Start), $fetchedAt);
$storedSlots = getPriceSlotsFrom($day1Start);
check(count($storedSlots) === 4, 'upserted price slots round-trip at the right count');
check($storedSlots[0]['import_rate'] === 20.0, 'import rate value round-trips');
check($storedSlots[0]['export_rate'] === 12.0, 'export rate value round-trips');
check($storedSlots[0]['fetched_at']->format(DATE_ATOM) === $fetchedAt->format(DATE_ATOM), 'fetched_at round-trips');

// Re-upserting the same day's slots with no export data must NOT erase the export rates
// just stored — a run that couldn't resolve export prices should never clobber an
// already-known one for the same slot.
$laterFetch = $fetchedAt->modify('+1 hour');
upsertPriceSlots(buildSlotsFrom(array_fill(0, 4, 21.0), $day1Start), null, $laterFetch);
$afterNullExport = getPriceSlotsFrom($day1Start);
check(count($afterNullExport) === 4, 're-upserting the same slots updates in place, not appends');
check($afterNullExport[0]['import_rate'] === 21.0, 'import rate is overwritten by a later fetch');
check($afterNullExport[0]['export_rate'] === 12.0, 'a null export batch does not clobber a previously-known export rate');
check($afterNullExport[0]['fetched_at']->format(DATE_ATOM) === $laterFetch->format(DATE_ATOM), 'fetched_at reflects the later upsert');

// A second calendar day's slots, upserted separately — getPriceSlotsFrom() should be able
// to select just one day, or both, by its $from cutoff.
$day2Start = $day1Start->modify('+1 day');
upsertPriceSlots(buildSlotsFrom(array_fill(0, 4, 30.0), $day2Start), null, $fetchedAt);
check(count(getPriceSlotsFrom($day1Start)) === 8, 'getPriceSlotsFrom() spans both known days when asked from day 1');
check(count(getPriceSlotsFrom($day2Start)) === 4, 'getPriceSlotsFrom() returns only day 2 when asked from day 2');
check(getPriceSlotsFrom($day2Start)[0]['import_rate'] === 30.0, 'day 2 slots round-trip independently of day 1');

check(getLatestPriceFetchedAt()->format(DATE_ATOM) === $laterFetch->format(DATE_ATOM), 'getLatestPriceFetchedAt() reflects the most recent upsert across all slots');
check(getLatestPriceHorizon()->format(DATE_ATOM) === $day2Start->modify('+2 hours')->format(DATE_ATOM), 'getLatestPriceHorizon() is the latest slot_to across every known slot');

// Regression test: confirmed live (BST, +01:00) that comparing $from against stored
// slot_from as plain TEXT — rather than normalising both to the same UTC offset first —
// silently drops a slot whose UTC-stored calendar date is "earlier" than $from's own
// offset-shifted calendar date, even when they're the exact same instant. Reproduced here
// with a slot stored at 23:00 UTC and queried via that identical instant reformatted
// through a +01:00 zone, which pushes its date string to the *next* calendar day
// ("...T00:00:00+01:00") — a fixed offset, not a real DST transition, so the test doesn't
// depend on which calendar date happens to be in DST. Deliberately placed after the
// getLatestPriceHorizon()/getLatestPriceFetchedAt() checks above, not before — this slot's
// timestamp would otherwise become the new latest and break those assertions.
$boundaryInstant = new DateTimeImmutable('2026-02-01 23:00:00', new DateTimeZone('UTC'));
upsertPriceSlots(buildSlotsFrom([99.0], $boundaryInstant), null, $fetchedAt);
$queryFrom = $boundaryInstant->setTimezone(new DateTimeZone('+01:00')); // same instant, formats as 2026-02-02T00:00:00+01:00
$foundBoundarySlot = array_filter(getPriceSlotsFrom($queryFrom), fn($s) => $s['import_rate'] === 99.0);
check(
    count($foundBoundarySlot) === 1,
    'getPriceSlotsFrom() finds a slot exactly at $from even when $from\'s own UTC offset pushes its date string past the stored (UTC) date string for the identical instant',
);

// --- Store: schedule_summaries (one day-level summary per date, replacing the old global setting) ---
check(getScheduleSummary('2026-01-05') === null, 'no summary for a date that has none');
upsertScheduleSummary('2026-01-05', 'First summary.');
check(getScheduleSummary('2026-01-05') === 'First summary.', 'summary round-trips through the table');
upsertScheduleSummary('2026-01-05', 'Replaced summary.');
check(getScheduleSummary('2026-01-05') === 'Replaced summary.', 're-saving the same date upserts rather than duplicating');
upsertScheduleSummary('2020-01-01', 'Old summary.');
pruneOldSchedules('2026-01-01');
check(getScheduleSummary('2020-01-01') === null, 'pruneOldSchedules() also prunes schedule_summaries older than the cutoff');
check(getScheduleSummary('2026-01-05') === 'Replaced summary.', 'pruneOldSchedules() leaves current/future summaries alone');

check(getOverridesForDate('2026-01-05') === [], 'no overrides for a date that has none');
saveOverride('2026-01-05', 'power_down', '08:00', '09:00', '07:00', '08:00');
$storedOverride = getOverridesForDate('2026-01-05');
check(count($storedOverride) === 1 && $storedOverride[0]['event_start'] === '08:00' && $storedOverride[0]['prep_start'] === '07:00', 'override round-trips through save/get');
saveOverride('2026-01-05', 'power_down', '10:00', '11:00', null, null);
$storedOverride2 = getOverridesForDate('2026-01-05');
check(count($storedOverride2) === 1 && $storedOverride2[0]['event_start'] === '10:00' && $storedOverride2[0]['prep_start'] === null, 're-saving the same (date, kind) upserts rather than duplicating, and can clear prep back to null');
deleteOverride('2026-01-05', 'power_down');
check(getOverridesForDate('2026-01-05') === [], 'delete removes the override');
saveOverride('2020-01-01', 'fill_your_boots', '08:00', '09:00', null, null);
saveOverride('2026-01-05', 'fill_your_boots', '08:00', '09:00', null, null);
pruneOldOverrides('2026-01-01');
check(getOverridesForDate('2020-01-01') === [], 'pruneOldOverrides removes dates before the given cutoff');
check(count(getOverridesForDate('2026-01-05')) === 1, 'pruneOldOverrides leaves current/future dates alone');

// --- Store: api_log (GitHub issue #3) — round-trip, ordering, and the 7-day body redaction rule ---
check(countApiLogEntries() === 0, 'no api_log rows before anything is saved');
$logNow = new DateTimeImmutable('2026-01-10 12:00:00', new DateTimeZone('UTC'));
saveApiLogEntry('/op/v1/device/scheduler/enable', '{"deviceSN":"ABC"}', 200, '{"errno":0}', $logNow);
$entries = getApiLogEntries(10);
check(count($entries) === 1, 'saved api_log entry round-trips');
check($entries[0]['endpoint'] === '/op/v1/device/scheduler/enable' && $entries[0]['status_code'] === 200, 'endpoint and status_code round-trip');
check($entries[0]['request_body'] === '{"deviceSN":"ABC"}' && $entries[0]['response_body'] === '{"errno":0}', 'request/response bodies round-trip for a recent entry');

// This second entry is timed to land just under 7 days before the third save below —
// i.e. close to *that* call's "now", not close to the first entry's — so it's the one
// that should still be within the retention window once the third entry triggers a prune.
$logSecondEntryAt = $logNow->modify('+8 days')->modify('-1 hour');
saveApiLogEntry('/op/v1/device/real/query', null, null, 'cURL error: timed out', $logSecondEntryAt);
$entriesDesc = getApiLogEntries(10);
check($entriesDesc[0]['endpoint'] === '/op/v1/device/real/query', 'getApiLogEntries() returns most-recent-first');
check($entriesDesc[0]['status_code'] === null && $entriesDesc[0]['response_body'] === 'cURL error: timed out', 'a transport failure logs a null status_code with the cURL error as the body');
check(countApiLogEntries() === 2, 'countApiLogEntries() reflects both saved rows');

// A third save, 8 days after the first entry, should redact (not delete) that first
// entry's bodies — it's now older than the 7-day retention window — while leaving the
// second (1 hour earlier, still within the window) untouched.
saveApiLogEntry('/op/v1/device/scheduler/get', '{}', 200, '{"errno":0}', $logNow->modify('+8 days'));
check(countApiLogEntries() === 3, 'redaction nulls bodies, it does not delete the row');
$byEndpoint = [];
foreach (getApiLogEntries(10) as $e) {
    $byEndpoint[$e['endpoint']] = $e;
}
check(
    $byEndpoint['/op/v1/device/scheduler/enable']['request_body'] === null && $byEndpoint['/op/v1/device/scheduler/enable']['response_body'] === null,
    'an entry older than 7 days has its request/response bodies redacted to null',
);
check($byEndpoint['/op/v1/device/scheduler/enable']['status_code'] === 200, 'status_code survives redaction — only the bodies are cleared');
check(
    $byEndpoint['/op/v1/device/real/query']['response_body'] === 'cURL error: timed out',
    'an entry within the 7-day window keeps its bodies intact',
);

check(count(getApiLogEntries(1)) === 1, 'getApiLogEntries() respects its $limit argument');
check(count(getApiLogEntries(10, 1)) === 2, 'getApiLogEntries() respects its $offset argument');

// --- Store: api_log status/level filtering (GitHub issue #8) — api-log.php's filter dropdowns.
// At this point the throwaway DB has 3 rows: two status=200 (one redacted-to-null body, one
// fresh with errno 0 — both read as 'success'), one status=null (a transport failure, 'error').
check(getDistinctApiLogStatusCodes() === [200], 'only the real, distinct status codes actually present populate the dropdown — no guessed/hardcoded list: got ' . json_encode(getDistinctApiLogStatusCodes()));
check(hasApiLogNoResponseEntries(), 'the transport-failure row is detected for the "No response" filter option');
check(countApiLogEntries(200) === 2, 'countApiLogEntries() with a status filter counts only matching rows');
check(countApiLogEntries(null, true) === 1, 'countApiLogEntries() with noResponseOnly counts only status_code IS NULL rows');
check(count(getApiLogEntries(10, 0, 200)) === 2, 'getApiLogEntries() with a status filter returns only matching rows');
check(count(getApiLogEntries(10, 0, null, true)) === 1, 'getApiLogEntries() with noResponseOnly returns only the transport-failure row');
check(getApiLogEntries(10, 0, null, true)[0]['endpoint'] === '/op/v1/device/real/query', 'the noResponseOnly-filtered row is the right one');

// A fourth row (a "Device offline" business error, errno 41935) gives full coverage of all
// three levels — apiLogLevel() downgrades this one to 'warning', not 'error'.
saveApiLogEntry('/op/v1/device/scheduler/enable', '{}', 200, '{"errno":41935,"msg":"Device offline, Please connect and retry"}', $logNow->modify('+9 days'));
$levelCounts = ['success' => 0, 'warning' => 0, 'error' => 0];
foreach (getAllApiLogEntriesForLevelFilter(null, false) as $e) {
    $levelCounts[apiLogLevel($e['status_code'], $e['response_body'])]++;
}
check($levelCounts === ['success' => 2, 'warning' => 1, 'error' => 1], 'getAllApiLogEntriesForLevelFilter() plus apiLogLevel() together classify all 4 rows correctly, the same combination api-log.php uses for its level filter: got ' . json_encode($levelCounts));
$warningOnly = array_values(array_filter(getAllApiLogEntriesForLevelFilter(null, false), fn($e) => apiLogLevel($e['status_code'], $e['response_body']) === 'warning'));
check(count($warningOnly) === 1 && str_contains($warningOnly[0]['response_body'], '41935'), 'the level-filtered result is genuinely the "Device offline" row, not just the right count');
$errorWithStatusFilter = array_values(array_filter(getAllApiLogEntriesForLevelFilter(200, false), fn($e) => apiLogLevel($e['status_code'], $e['response_body']) === 'error'));
check($errorWithStatusFilter === [], 'status and level filters combine (AND, not OR) — filtering to status=200 excludes the transport-failure row even though it would otherwise be the "error" level match');

// --- Store: wasRecentlyRateLimited() (GitHub issue #7) — surfaces a rate-limit/quota errno
// from the API log so HistoryFetcher can report it even though its own loops only ever
// needed success/no-data/error to decide whether to keep going, not why ---
$rateLimitWindowStart = $logNow->modify('+9 days');
check(!wasRecentlyRateLimited($rateLimitWindowStart), 'no rate-limited calls logged yet within the window');
saveApiLogEntry('/op/v1/device/scheduler/enable', '{}', 200, '{"errno":40400,"msg":"Your requests are too frequent. Please try again later"}', $rateLimitWindowStart->modify('+1 minute'));
check(wasRecentlyRateLimited($rateLimitWindowStart), 'a 40400 errno logged after the window start is detected');
check(!wasRecentlyRateLimited($rateLimitWindowStart->modify('+2 minutes')), 'the same call is not "recent" relative to a window starting after it happened');
saveApiLogEntry('/op/v1/device/real/query', '{}', 200, '{"errno":0}', $rateLimitWindowStart->modify('+3 minutes'));
check(wasRecentlyRateLimited($rateLimitWindowStart), 'a later plain success call does not mask an earlier rate-limit hit still within the window');
saveApiLogEntry('/op/v1/device/scheduler/get', '{}', 200, '{"errno":41935,"msg":"Device offline, Please connect and retry"}', $rateLimitWindowStart->modify('+4 minutes'));
check(wasRecentlyRateLimited($rateLimitWindowStart), 'an unrelated errno logged even later still does not mask the earlier rate-limit hit');
check(!wasRecentlyRateLimited($rateLimitWindowStart->modify('+5 minutes')), 'a window starting after every logged call finds nothing, even though rate-limiting did happen earlier');

// --- PriceProvider: fixed mode (default + override), api mode without a configured product/tariff ---
$priceLogger = new Logger(sys_get_temp_dir() . '/foxhole_self_check_' . getmypid() . '.log');
$priceProvider = new PriceProvider(new OctopusClient($priceLogger), ['product_code' => null, 'tariff_code' => null]);
$aDay = new DateTimeImmutable('2026-01-05', new DateTimeZone('Europe/London'));

$exportDefault = $priceProvider->resolveExport($aDay);
check(count($exportDefault) === 48, 'export resolves 48 slots by default (fixed mode)');
check($exportDefault[0]['rate'] === 12.0, 'export defaults to 12p/kWh fixed when nothing is configured');
check($exportDefault[0]['from']->format('H:i') === '00:00', 'fixed-mode slots start at local midnight');
check($exportDefault[47]['to']->format('H:i') === '00:00', 'fixed-mode slots cover a full day, last slot ends at midnight');

setSetting('export_price_mode', 'fixed');
setSetting('export_price_fixed_pence', '7.5');
check($priceProvider->resolveExport($aDay)[0]['rate'] === 7.5, 'export fixed price is overrideable via settings');

try {
    $priceProvider->resolveImport($aDay);
    check(false, "import mode 'api' with no product/tariff code configured should throw");
} catch (OctopusFetchException $e) {
    check(true, "import mode 'api' with no product/tariff code configured throws a clear error, not a crash");
}

setSetting('import_price_mode', 'fixed');
setSetting('import_price_fixed_pence', '20');
$importFixed = $priceProvider->resolveImport($aDay);
check(count($importFixed) === 48 && $importFixed[0]['rate'] === 20.0, 'import can be switched to fixed mode too, bypassing Octopus entirely');

$pushedAt = new DateTimeImmutable('2026-01-04 17:00:00', new DateTimeZone('UTC'));
saveSchedule('2026-01-05', $groups, $schedule['explanations'], $pushedAt);
$storedSchedule = getScheduleForDate('2026-01-05');
check($storedSchedule['groups'] == $groups, 'saved schedule groups round-trip identically (used for run.php\'s no-op diff)');
check($storedSchedule['for_date'] === '2026-01-05', 'for_date round-trips');
check($storedSchedule['explanations'] === $schedule['explanations'], 'saved explanations round-trip in the same order as their groups');

// A second date's schedule doesn't wipe out the first — this is what lets a run
// computing tomorrow's plan splice against today's still-stored one.
saveSchedule('2026-01-06', $groups, $schedule['explanations'], $pushedAt);
check(getScheduleForDate('2026-01-05')['groups'] == $groups, 'saving a schedule for a new date leaves an earlier date\'s schedule alone');
pruneOldSchedules('2026-01-06');
check(getScheduleForDate('2026-01-05')['pushed_at'] === null, 'pruneOldSchedules removes dates before the given cutoff');
check(getScheduleForDate('2026-01-06')['pushed_at'] !== null, 'pruneOldSchedules leaves current/future dates alone');

// --- Store: solar forecast round-trips, and each fetch replaces the previous one whole ---
$londonTzForSolar = new DateTimeZone('Europe/London');
$solarSlots = [
    ['from' => new DateTimeImmutable('2026-01-05 06:00', $londonTzForSolar), 'to' => new DateTimeImmutable('2026-01-05 07:00', $londonTzForSolar), 'watt_hours' => 300],
    ['from' => new DateTimeImmutable('2026-01-05 07:00', $londonTzForSolar), 'to' => new DateTimeImmutable('2026-01-05 08:00', $londonTzForSolar), 'watt_hours' => 900],
];
saveSolarForecast($solarSlots, $pushedAt);
$storedSolar = getLatestSolarForecast();
check(count($storedSolar) === 2 && $storedSolar[1]['watt_hours'] === 900, 'saved solar forecast slots round-trip');
saveSolarForecast([$solarSlots[0]], $pushedAt);
check(count(getLatestSolarForecast()) === 1, 'a new solar forecast fetch replaces the previous one whole, like rate_slots');

// --- Store: historic_generation — generation and forecast upsert independently, never appending ---
check(getHistoricGenerationBounds() === ['earliest' => null, 'latest' => null], 'empty historic_generation reports no bounds');
$hour0 = new DateTimeImmutable('2026-01-05 06:00', $londonTzForSolar);
$hour1 = new DateTimeImmutable('2026-01-05 07:00', $londonTzForSolar);
upsertHistoricGeneration($hour0, $hour0->modify('+1 hour'), 1.5, $pushedAt);
upsertHistoricGeneration($hour1, $hour1->modify('+1 hour'), 2.5, $pushedAt);
$genRows = getHistoricGeneration($hour0, $hour1->modify('+1 hour'));
check(count($genRows) === 2, 'two upserted hours round-trip as two rows');
check($genRows[0]['generation_kwh'] === 1.5 && $genRows[0]['forecast_kwh'] === null, 'a generation-only row has null forecast_kwh, not 0');

upsertHistoricForecast($hour0, $hour0->modify('+1 hour'), 1.25, $pushedAt);
$genRows2 = getHistoricGeneration($hour0, $hour1->modify('+1 hour'));
check($genRows2[0]['generation_kwh'] === 1.5 && $genRows2[0]['forecast_kwh'] === 1.25, 'writing a forecast for an hour that already has generation updates forecast_kwh without touching generation_kwh');

$hour2 = new DateTimeImmutable('2026-01-05 08:00', $londonTzForSolar);
upsertHistoricForecast($hour2, $hour2->modify('+1 hour'), 3.0, $pushedAt);
$genRows3 = getHistoricGeneration($hour0, $hour2->modify('+1 hour'));
check(count($genRows3) === 3, 'a forecast-only hour (no generation yet) still creates its own row');
check($genRows3[2]['generation_kwh'] === null && $genRows3[2]['forecast_kwh'] === 3.0, 'the forecast-only row has null generation_kwh, not 0');

$boundsAfter = getHistoricGenerationBounds();
check(
    $boundsAfter['earliest']->getTimestamp() === $hour0->getTimestamp() && $boundsAfter['latest']->getTimestamp() === $hour1->getTimestamp(),
    'bounds only consider rows with a real (non-null) generation reading — the later forecast-only hour is excluded from "latest"',
);

$hour3 = new DateTimeImmutable('2026-01-05 09:00', $londonTzForSolar);
upsertHistoricUsage($hour3, $hour3->modify('+1 hour'), 0.8, $pushedAt);
$usageRows = getHistoricGeneration($hour3, $hour3->modify('+1 hour'));
check($usageRows[0]['usage_kwh'] === 0.8 && $usageRows[0]['generation_kwh'] === null, 'usage_kwh upserts independently, same non-clobbering pattern as forecast_kwh (GitHub issue #5)');
check(
    getHistoricUsageBounds()['earliest']->getTimestamp() === $hour3->getTimestamp() && getHistoricUsageBounds()['latest']->getTimestamp() === $hour3->getTimestamp(),
    'getHistoricUsageBounds() mirrors getHistoricGenerationBounds() but for usage_kwh — only the usage row counts, not the earlier generation-only ones',
);

// --- Store: per-variable history backfill limits (independent generation/usage backfill) ---
check(getHistoryBackfillLimit('generation') === null, 'no generation backfill limit set yet, and no legacy setting to fall back to');
check(getHistoryBackfillLimit('usage') === null, 'no usage backfill limit set yet either');
setHistoryBackfillLimit('generation', new DateTimeImmutable('2024-03-15'));
check(getHistoryBackfillLimit('generation')->format('Y-m-d') === '2024-03-15', 'a set generation backfill limit round-trips');
check(getHistoryBackfillLimit('usage') === null, 'setting generation\'s limit leaves usage\'s independent and untouched');
setHistoryBackfillLimit('usage', new DateTimeImmutable(HISTORY_BACKFILL_EPOCH));
check(getHistoryBackfillLimit('usage')->format('Y-m-d') === HISTORY_BACKFILL_EPOCH, 'the epoch sentinel round-trips like any other date');

// A pre-existing install that already exhausted the old, single, generation-only setting
// must read as exhausted under the new per-variable scheme too — mapped straight to the
// epoch sentinel, not the old setting's own recorded horizon day (see
// getHistoryBackfillLimit()'s doc comment for why the exact day isn't preserved). Run in a
// separate throwaway DB (same isolation pattern the usage_kwh ALTER TABLE migration test
// above uses) so writing the legacy setting here can't leak into any other test's state.
$legacyDbPath = sys_get_temp_dir() . '/foxhole_self_check_' . getmypid() . '_legacy_backfill.sqlite';
@unlink($legacyDbPath);
db($legacyDbPath);
setSetting('history_backfill_exhausted_before', '2023-01-01');
$migratedLimit = getHistoryBackfillLimit('generation');
check($migratedLimit !== null && $migratedLimit->format('Y-m-d') === HISTORY_BACKFILL_EPOCH, 'the old history_backfill_exhausted_before setting falls back to the epoch sentinel for generation specifically, not its own recorded day (2023-01-01)');
check(getHistoryBackfillLimit('usage') === null, 'the old generation-only setting has no bearing on usage\'s own limit');
@unlink($legacyDbPath);
db($testDbPath); // switch back to the main throwaway DB for everything below

// --- HistoryFetcher: backfillHistoryBackward() — independent generation/usage backfill
// (user-requested), exercised with a scripted FoxessClient so the tricky "skip an
// already-covered variable until the walk reaches its own frontier, shared call budget,
// each variable stops independently on its own error/exhaustion" logic can be verified
// without a live FoxESS connection. Throws for any (day, variable) pair not explicitly
// scripted, so an assertion failure here usually means the code under test made an
// unexpected call, not just a wrong one.
class ScriptedHistoryFoxessClient extends FoxessClient
{
    public array $generationCalls = [];
    public array $usageCalls = [];

    /** @param array<string, array|null|'ERROR'> $generationByDate 'Y-m-d' => hourly kWh array, null (NO_DATA), or 'ERROR' */
    public function __construct(private readonly array $generationByDate, private readonly array $usageByDate)
    {
        parent::__construct('key', 'SN-HIST-TEST', 'https://example.invalid');
    }

    public function getGenerationReport(int $year, int $month, int $day): ?array
    {
        $key = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $this->generationCalls[] = $key;
        if (!array_key_exists($key, $this->generationByDate)) {
            throw new FoxessPushException("unscripted generation call for $key — test expected this day to be skipped");
        }
        $v = $this->generationByDate[$key];
        if ($v === 'ERROR') {
            throw new FoxessPushException('simulated transient error');
        }
        return $v;
    }

    public function getUsageReport(int $year, int $month, int $day): ?array
    {
        $key = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $this->usageCalls[] = $key;
        if (!array_key_exists($key, $this->usageByDate)) {
            throw new FoxessPushException("unscripted usage call for $key — test expected this day to be skipped");
        }
        $v = $this->usageByDate[$key];
        if ($v === 'ERROR') {
            throw new FoxessPushException('simulated transient error');
        }
        return $v;
    }
}

$histTz = new DateTimeZone('Europe/London');
$histToday = new DateTimeImmutable('2026-02-15', $histTz);
$histLogger = new Logger(sys_get_temp_dir() . '/foxhole_self_check_history.log');

// Both already exhausted: must return immediately, making zero API calls at all.
setHistoryBackfillLimit('generation', new DateTimeImmutable(HISTORY_BACKFILL_EPOCH));
setHistoryBackfillLimit('usage', new DateTimeImmutable(HISTORY_BACKFILL_EPOCH));
$bothDoneClient = new ScriptedHistoryFoxessClient([], []); // throws on any call
check(
    backfillHistoryBackward(['SN' => $bothDoneClient], $histToday, $histTz, $histLogger) === 0,
    'both variables already exhausted (epoch sentinel) short-circuits to zero days stored',
);
check($bothDoneClient->generationCalls === [] && $bothDoneClient->usageCalls === [], 'and makes no API calls at all — the exhausted state alone is enough to know there is nothing left to do');

// The core scenario this feature exists for: generation already exhausted (the common case,
// since generation backfill existed long before usage tracking did), usage still has real
// backfilling left to do. Generation must never be called at all.
setHistoryBackfillLimit('generation', new DateTimeImmutable(HISTORY_BACKFILL_EPOCH));
setHistoryBackfillLimit('usage', new DateTimeImmutable('2026-02-10'));
$usageOnlyClient = new ScriptedHistoryFoxessClient([], [
    '2026-02-09' => array_fill(0, 24, 0.3),
    '2026-02-08' => array_fill(0, 24, 0.3),
    '2026-02-07' => array_fill(0, 24, 0.3),
    '2026-02-06' => null, // usage's own horizon
]);
$usageOnlyStored = backfillHistoryBackward(['SN' => $usageOnlyClient], $histToday, $histTz, $histLogger);
check($usageOnlyStored === 3, "3 usage days stored (Feb 7-9), generation untouched: got $usageOnlyStored");
check($usageOnlyClient->generationCalls === [], 'generation is never called once its own limit is the epoch sentinel — the whole point of tracking it independently');
check($usageOnlyClient->usageCalls === ['2026-02-09', '2026-02-08', '2026-02-07', '2026-02-06'], 'usage walks back day by day and stops the call after the one that discovers its own horizon: got ' . json_encode($usageOnlyClient->usageCalls));
check(getHistoryBackfillLimit('generation')->format('Y-m-d') === HISTORY_BACKFILL_EPOCH, 'generation\'s limit is untouched by a call where it was never active');
check(getHistoryBackfillLimit('usage')->format('Y-m-d') === HISTORY_BACKFILL_EPOCH, 'usage\'s limit is now the epoch sentinel too, having just discovered its own horizon');
$usageStoredRow = getHistoricGeneration(new DateTimeImmutable('2026-02-07', $histTz), new DateTimeImmutable('2026-02-10', $histTz));
check(count($usageStoredRow) === 3 * 24 && $usageStoredRow[0]['generation_kwh'] === null, 'the stored usage-only days have real usage_kwh but null generation_kwh — no NULL was upserted over a generation column that was simply never touched');

// Skip-until-frontier: generation is further back (more advanced) than usage, so days
// between the two limits must only ever call usage — generation only starts being called
// once the walk actually reaches its own, earlier frontier. A day in the "usage only" range
// (2026-01-03) is pre-seeded with an existing generation_kwh value to prove the independent
// upsert never overwrites it with NULL just because this call didn't fetch generation for it.
setHistoryBackfillLimit('generation', new DateTimeImmutable('2026-01-01'));
setHistoryBackfillLimit('usage', new DateTimeImmutable('2026-01-05'));
upsertHistoricGeneration(new DateTimeImmutable('2026-01-03 06:00', $histTz), new DateTimeImmutable('2026-01-03 07:00', $histTz), 9.9, $pushedAt);
$frontierClient = new ScriptedHistoryFoxessClient(
    ['2025-12-31' => null], // generation's own horizon, reached only once the walk gets there
    [
        '2026-01-04' => array_fill(0, 24, 0.2),
        '2026-01-03' => array_fill(0, 24, 0.2),
        '2026-01-02' => array_fill(0, 24, 0.2),
        '2026-01-01' => array_fill(0, 24, 0.2),
        '2025-12-31' => null, // usage's own horizon, reached at the same day here
    ],
);
backfillHistoryBackward(['SN' => $frontierClient], $histToday, $histTz, $histLogger);
check($frontierClient->generationCalls === ['2025-12-31'], 'generation is skipped for every day still within its already-confirmed range (down to and including 2026-01-01) and only actually called once the walk passes it: got ' . json_encode($frontierClient->generationCalls));
check($frontierClient->usageCalls === ['2026-01-04', '2026-01-03', '2026-01-02', '2026-01-01', '2025-12-31'], 'usage is called for every day it still needs, including the ones generation already has: got ' . json_encode($frontierClient->usageCalls));
$preSeededRow = getHistoricGeneration(new DateTimeImmutable('2026-01-03 06:00', $histTz), new DateTimeImmutable('2026-01-03 07:00', $histTz));
check(count($preSeededRow) === 1 && $preSeededRow[0]['generation_kwh'] === 9.9 && $preSeededRow[0]['usage_kwh'] === 0.2, 'backfilling usage for a day generation already covers preserves the pre-existing generation_kwh — the independent-upsert requirement');
check(getHistoryBackfillLimit('generation')->format('Y-m-d') === HISTORY_BACKFILL_EPOCH, 'generation reached its own horizon once the walk finally passed its frontier');
check(getHistoryBackfillLimit('usage')->format('Y-m-d') === HISTORY_BACKFILL_EPOCH, 'usage reached its own horizon on the same day here');

// A transient error (not genuine exhaustion) on one variable must stop only that variable
// for this call, without recording it as exhausted — the failed day is retried next time,
// same as the pre-existing single-variable behaviour this replaces.
setHistoryBackfillLimit('generation', new DateTimeImmutable('2026-01-10'));
setHistoryBackfillLimit('usage', new DateTimeImmutable('2026-01-10'));
$errorClient = new ScriptedHistoryFoxessClient(
    ['2026-01-09' => array_fill(0, 24, 0.1), '2026-01-08' => array_fill(0, 24, 0.1), '2026-01-07' => null],
    ['2026-01-09' => 'ERROR'],
);
backfillHistoryBackward(['SN' => $errorClient], $histToday, $histTz, $histLogger);
check($errorClient->usageCalls === ['2026-01-09'], 'usage stops immediately after its transient error, never trying an earlier day this call');
check(getHistoryBackfillLimit('usage')->format('Y-m-d') === '2026-01-10', 'usage\'s limit is unchanged by a transient error — the failed day was never confirmed, so the frontier does not advance past it');
check($errorClient->generationCalls === ['2026-01-09', '2026-01-08', '2026-01-07'], 'generation is entirely unaffected by usage\'s error and keeps walking back on its own, reaching its own horizon at 2026-01-07');
check(getHistoryBackfillLimit('generation')->format('Y-m-d') === HISTORY_BACKFILL_EPOCH, 'generation\'s limit reaches the epoch sentinel independently, regardless of usage\'s unrelated failure');

// --- Store: historic_generation.usage_kwh is added via a real ALTER TABLE on an existing
// install, preserving pre-existing rows — this table is real history, so this must never
// be a drop-and-recreate like the disposable tables elsewhere use for schema changes.
$migrationDbPath = sys_get_temp_dir() . '/foxhole_self_check_' . getmypid() . '_migration.sqlite';
@unlink($migrationDbPath);
$oldSchemaPdo = new PDO('sqlite:' . $migrationDbPath);
$oldSchemaPdo->exec('CREATE TABLE historic_generation (
    slot_from TEXT PRIMARY KEY,
    slot_to TEXT NOT NULL,
    generation_kwh REAL,
    forecast_kwh REAL,
    updated_at TEXT NOT NULL
)');
$oldSchemaPdo->exec("INSERT INTO historic_generation (slot_from, slot_to, generation_kwh, forecast_kwh, updated_at)
    VALUES ('2025-01-01T06:00:00+00:00', '2025-01-01T07:00:00+00:00', 4.2, NULL, '2025-01-01T08:00:00+00:00')");
$oldSchemaPdo = null; // close before Store's db() opens its own connection to the same file

db($migrationDbPath); // triggers the guarded ALTER TABLE ... ADD COLUMN usage_kwh
$migratedRows = getHistoricGeneration(new DateTimeImmutable('2025-01-01', new DateTimeZone('UTC')), new DateTimeImmutable('2025-01-02', new DateTimeZone('UTC')));
check(count($migratedRows) === 1 && $migratedRows[0]['generation_kwh'] === 4.2, 'ALTER TABLE ADD COLUMN usage_kwh preserves a pre-existing row from before the migration');
check($migratedRows[0]['usage_kwh'] === null, 'the pre-existing row has null usage_kwh after migrating, not 0 or an error');
upsertHistoricUsage(new DateTimeImmutable('2025-01-01 06:00', new DateTimeZone('UTC')), new DateTimeImmutable('2025-01-01 07:00', new DateTimeZone('UTC')), 1.1, $pushedAt);
$afterMigrationUpsert = getHistoricGeneration(new DateTimeImmutable('2025-01-01', new DateTimeZone('UTC')), new DateTimeImmutable('2025-01-02', new DateTimeZone('UTC')));
check($afterMigrationUpsert[0]['usage_kwh'] === 1.1 && $afterMigrationUpsert[0]['generation_kwh'] === 4.2, 'writing usage_kwh after the migration does not disturb the pre-existing generation_kwh');
@unlink($migrationDbPath);
db($testDbPath); // db()'s path is sticky — switch back to the main throwaway DB for everything below

// --- ScheduleBuilder: buildPushWindow() (GitHub issue #4, replaces the old today+tomorrow-only spliceForPush()) ---
$pushTz = new DateTimeZone('Europe/London');
$todayGroups = [['enable' => 1, 'startHour' => 20, 'startMinute' => 0, 'endHour' => 0, 'endMinute' => 0, 'workMode' => 'ForceDischarge', 'minSocOnGrid' => 15, 'fdSoc' => 15, 'fdPwr' => 3000]];
$todayExplanations = ['Selling 20:00-00:00.'];
$tomorrowGroups = [['enable' => 1, 'startHour' => 2, 'startMinute' => 0, 'endHour' => 5, 'endMinute' => 0, 'workMode' => 'ForceCharge', 'minSocOnGrid' => 15, 'fdSoc' => 100, 'fdPwr' => 3000]];
$tomorrowExplanations = ['Charging 02:00-05:00.'];
$scheduleByDate = [
    '2026-01-05' => ['groups' => $todayGroups, 'explanations' => $todayExplanations],
    '2026-01-06' => ['groups' => $tomorrowGroups, 'explanations' => $tomorrowExplanations],
];

$pushBuilder = new ScheduleBuilder($strategy, $battery);
$push = $pushBuilder->buildPushWindow($scheduleByDate, new DateTimeImmutable('2026-01-05 18:00:00', $pushTz), $pushTz, null);
$pushModes = array_map(fn($g) => $g['workMode'] . ' ' . $g['startHour'] . '-' . $g['endHour'], $push['groups']);
check(
    $pushModes === ['ForceDischarge 20-0', 'ForceCharge 2-5'],
    'with nothing capping the window, both known days combine into one 24h push, in true chronological order (today\'s evening before tomorrow\'s early morning, not sorted by raw hour-of-day): got ' . implode(',', $pushModes),
);

// "Now" (21:00) falls inside today's 20:00-00:00 group — genuinely in progress, not merely
// "after the top of the hour" — so its true 20:00 start must survive, not get clipped to
// 21:00. This used to assert the opposite (clipped to 21:00) before a GitHub-reported bug
// was fixed: applyBstWorkaround() (applied after this function, on the copy actually sent
// to FoxESS) shifts everything an hour earlier to compensate for FoxESS's own delayed
// execution, and clipping an in-progress group's start here threw away exactly the portion
// that shift needed — the 20:00-21:00 stretch would then never actually apply on the
// device at all, workaround or not, confirmed live.
$push2 = $pushBuilder->buildPushWindow($scheduleByDate, new DateTimeImmutable('2026-01-05 21:00:00', $pushTz), $pushTz, null);
$tail = $push2['groups'][0];
check(
    $tail['startHour'] === 20 && $tail['endHour'] === 0,
    'a group actually in progress at "now" (20:00-00:00, now=21:00) keeps its true 20:00 start rather than being clipped to the top of the current hour: got startHour=' . $tail['startHour'],
);

// A group that's genuinely, fully in the past by "now" — as opposed to merely started
// before the current hour — must still be excluded entirely; the in-progress fix above
// must not resurrect a group that's actually finished.
$elapsedSchedule = ['2026-01-05' => ['groups' => [['enable' => 1, 'startHour' => 10, 'startMinute' => 0, 'endHour' => 11, 'endMinute' => 0, 'workMode' => 'ForceCharge', 'minSocOnGrid' => 15, 'fdSoc' => 100, 'fdPwr' => 3000]], 'explanations' => ['Charging 10:00-11:00.']]];
$elapsedPush = $pushBuilder->buildPushWindow($elapsedSchedule, new DateTimeImmutable('2026-01-05 13:05:00', $pushTz), $pushTz, null);
check($elapsedPush['groups'] === [], 'a group that fully ended before "now" is excluded, not resurrected by the in-progress fix: got ' . json_encode($elapsedPush['groups']));

// The exact scenario reported live: a 12:00-14:59 override, pushed while already partway
// through it (13:05) — must keep the true 12:00 start, not 13:00.
$inProgressSchedule = ['2026-01-05' => ['groups' => [['enable' => 1, 'startHour' => 12, 'startMinute' => 0, 'endHour' => 14, 'endMinute' => 59, 'workMode' => 'ForceDischarge', 'minSocOnGrid' => 15, 'fdSoc' => 15, 'fdPwr' => 3000]], 'explanations' => ['Selling 12:00-14:59.']]];
$inProgressPush = $pushBuilder->buildPushWindow($inProgressSchedule, new DateTimeImmutable('2026-01-05 13:05:00', $pushTz), $pushTz, null);
check(
    count($inProgressPush['groups']) === 1
        && $inProgressPush['groups'][0]['startHour'] === 12 && $inProgressPush['groups'][0]['startMinute'] === 0
        && $inProgressPush['groups'][0]['endHour'] === 14 && $inProgressPush['groups'][0]['endMinute'] === 59,
    'a saved 12:00-14:59 override pushed at 13:05 keeps its full 12:00-14:59 span: got ' . json_encode($inProgressPush['groups']),
);

// Only today known (tomorrow not in $scheduleByDate at all, e.g. not published yet) and
// pricing data ends at midnight tonight — the push must not extend a full 24h past that,
// satisfying "24h ahead, or the end of known pricing, whichever is sooner" (issue #4).
$todayOnly = ['2026-01-05' => ['groups' => $todayGroups, 'explanations' => $todayExplanations]];
$knownEndsAtMidnight = new DateTimeImmutable('2026-01-06 00:00:00', $pushTz);
$capped = $pushBuilder->buildPushWindow($todayOnly, new DateTimeImmutable('2026-01-05 14:00:00', $pushTz), $pushTz, $knownEndsAtMidnight);
check(count($capped['groups']) === 1 && $capped['groups'][0]['startHour'] === 20 && $capped['groups'][0]['endHour'] === 0, 'a day with no known pricing past it is included up to its own end, unclipped, when that end is sooner than 24h out');

// Pricing data that's already fully in the past relative to "now" collapses the window to nothing.
$stale = $pushBuilder->buildPushWindow($todayOnly, new DateTimeImmutable('2026-01-05 14:00:00', $pushTz), $pushTz, new DateTimeImmutable('2026-01-05 10:00:00', $pushTz));
check($stale['groups'] === [] && $stale['explanations'] === [], 'a known-data-end already before "now" collapses the push window to empty rather than an invalid negative-width window');
check($stale['windowEnd'] == $stale['windowStart'], 'a collapsed window still reports windowStart/windowEnd (equal to each other) for the caller\'s status message');

// --- ScheduleBuilder: applyBstWorkaround shifts pushed groups an hour earlier, splitting at midnight ---
$bstNormal = [['enable' => 1, 'startHour' => 6, 'startMinute' => 0, 'endHour' => 10, 'endMinute' => 0, 'workMode' => 'ForceCharge', 'minSocOnGrid' => 15, 'fdSoc' => 100, 'fdPwr' => 3000]];
$bstNormalResult = (new ScheduleBuilder($strategy, $battery))->applyBstWorkaround($bstNormal, ['x']);
check(
    $bstNormalResult['groups'][0]['startHour'] === 5 && $bstNormalResult['groups'][0]['endHour'] === 9,
    'applyBstWorkaround shifts a normal (non-wrapping) group an hour earlier',
);

$bstWholeWrap = [['enable' => 1, 'startHour' => 0, 'startMinute' => 0, 'endHour' => 0, 'endMinute' => 30, 'workMode' => 'ForceDischarge', 'minSocOnGrid' => 15, 'fdSoc' => 15, 'fdPwr' => 3000]];
$bstWholeWrapResult = (new ScheduleBuilder($strategy, $battery))->applyBstWorkaround($bstWholeWrap, ['x']);
check(
    count($bstWholeWrapResult['groups']) === 1
        && $bstWholeWrapResult['groups'][0]['startHour'] === 23 && $bstWholeWrapResult['groups'][0]['startMinute'] === 0
        && $bstWholeWrapResult['groups'][0]['endHour'] === 23 && $bstWholeWrapResult['groups'][0]['endMinute'] === 30,
    'applyBstWorkaround wraps a group entirely before midnight to the end of the day: got ' . json_encode($bstWholeWrapResult['groups']),
);

$bstSplitWrap = [['enable' => 1, 'startHour' => 0, 'startMinute' => 30, 'endHour' => 2, 'endMinute' => 0, 'workMode' => 'ForceCharge', 'minSocOnGrid' => 15, 'fdSoc' => 100, 'fdPwr' => 3000]];
$bstSplitResult = (new ScheduleBuilder($strategy, $battery))->applyBstWorkaround($bstSplitWrap, ['x']);
check(count($bstSplitResult['groups']) === 2, 'applyBstWorkaround splits a group that straddles midnight once shifted into two');
check(
    $bstSplitResult['groups'][0]['startHour'] === 0 && $bstSplitResult['groups'][0]['startMinute'] === 0
        && $bstSplitResult['groups'][0]['endHour'] === 1 && $bstSplitResult['groups'][0]['endMinute'] === 0,
    'the post-midnight half (sorted first, starting 00:00) runs to 01:00: got ' . json_encode($bstSplitResult['groups'][0]),
);
check(
    $bstSplitResult['groups'][1]['startHour'] === 23 && $bstSplitResult['groups'][1]['startMinute'] === 30
        && $bstSplitResult['groups'][1]['endHour'] === 0 && $bstSplitResult['groups'][1]['endMinute'] === 0,
    'the pre-midnight half (sorted second, starting 23:30) runs to end-of-day: got ' . json_encode($bstSplitResult['groups'][1]),
);

check(isBstDate(new DateTimeImmutable('2026-07-15 12:00:00', $pushTz), $pushTz) === true, 'isBstDate is true in mid-July');
check(isBstDate(new DateTimeImmutable('2026-01-15 12:00:00', $pushTz), $pushTz) === false, 'isBstDate is false in mid-January');

// --- Schedulers.php: pluggable scheduler registry (GitHub issue #2) ---
check(resolveSchedulerId() === 'forecast_weighted_price_model', 'resolveSchedulerId() defaults to the forecast-weighted scheduler with nothing stored');
check(resolveSchedulerId('classic') === 'classic', 'an explicit override (run.php --classic) wins regardless of any stored setting');
check(resolveSchedulerId('not-a-real-id') === 'forecast_weighted_price_model', 'an unrecognised override id is ignored, falling through to the stored/default resolution');

setSetting('intelligent_scheduler_enabled', '0');
check(resolveSchedulerId() === 'classic', 'legacy intelligent_scheduler_enabled=0 toggle maps to the classic scheduler when scheduler_id was never saved');
setSetting('intelligent_scheduler_enabled', '1');
check(resolveSchedulerId() === 'forecast_weighted_price_model', 'legacy intelligent_scheduler_enabled=1 toggle maps to the forecast-weighted scheduler');

setSetting('scheduler_id', 'classic');
check(resolveSchedulerId() === 'classic', 'a saved scheduler_id wins over the legacy boolean toggle once it exists');

$registryRates = array_fill(0, 48, 30.0);
for ($i = 0; $i < 6; $i++) { $registryRates[$i] = 10.0; }
$registrySlots = buildSlots($registryRates);
$registryCostBasis = array_fill(0, 48, 24.5);
$classicViaRegistry = buildScheduleWithScheduler('classic', $strategy, $battery, ['importSlots' => $registrySlots, 'exportSlots' => null, 'costBasis' => $registryCostBasis]);
$classicDirect = (new ScheduleBuilder($strategy, $battery))->build($registrySlots, null, $registryCostBasis);
check($classicViaRegistry['groups'] === $classicDirect['groups'], 'buildScheduleWithScheduler(\'classic\', ...) produces the same groups as calling ScheduleBuilder directly');

$forecastViaRegistry = buildScheduleWithScheduler('forecast_weighted_price_model', $strategy, $battery, [
    'importSlots' => $registrySlots, 'exportSlots' => null, 'costBasis' => $registryCostBasis,
    'usageConfig' => ['avg_daily_kwh' => 10.0], 'solarSlots' => null, 'currentSocPercent' => null,
]);
$forecastDirect = (new IntelligentScheduleBuilder($strategy, $battery, ['avg_daily_kwh' => 10.0]))->build($registrySlots, null, $registryCostBasis, null, null);
check($forecastViaRegistry['groups'] === $forecastDirect['groups'], 'buildScheduleWithScheduler(\'forecast_weighted_price_model\', ...) produces the same groups as calling IntelligentScheduleBuilder directly');

// --- Schedulers.php: buildMultiDaySchedule() (GitHub issue #4's "per calendar day" decision) ---
$day2Slots = buildSlotsFrom($registryRates, new DateTimeImmutable('2026-01-06 00:00:00', new DateTimeZone('UTC')));
$multiDaySlots = [
    '2026-01-05' => ['importSlots' => $registrySlots, 'exportSlots' => null, 'costBasis' => $registryCostBasis],
    '2026-01-06' => ['importSlots' => $day2Slots, 'exportSlots' => null, 'costBasis' => $registryCostBasis],
];

$multiClassic = buildMultiDaySchedule('classic', $strategy, $battery, $multiDaySlots);
check(
    $multiClassic['2026-01-05']['groups'] === $multiClassic['2026-01-06']['groups'],
    'the classic scheduler has no cross-day state, so two days with identical price patterns produce identical plans',
);

$multiForecastExtras = ['usageConfig' => ['avg_daily_kwh' => 10.0], 'solarSlots' => null, 'currentSocPercent' => 50.0];
$multiForecast = buildMultiDaySchedule('forecast_weighted_price_model', $strategy, $battery, $multiDaySlots, $multiForecastExtras);
check(
    abs($multiForecast['2026-01-05']['finalSocPercent'] - 50.0) > 0.5,
    'day 1 actually projects some real change in SoC over the day (otherwise the carry-over check below would be meaningless): got ' . $multiForecast['2026-01-05']['finalSocPercent'],
);
$expectedDay2 = buildScheduleWithScheduler('forecast_weighted_price_model', $strategy, $battery, [
    'importSlots' => $day2Slots, 'exportSlots' => null, 'costBasis' => $registryCostBasis,
    'usageConfig' => ['avg_daily_kwh' => 10.0], 'solarSlots' => null,
    'currentSocPercent' => $multiForecast['2026-01-05']['finalSocPercent'],
]);
check(
    $multiForecast['2026-01-06']['groups'] === $expectedDay2['groups'],
    'day 2 of the forecast-weighted scheduler starts from day 1\'s projected finalSocPercent, not the original live reading passed in for day 1',
);

@unlink($testDbPath);

// --- IntelligentScheduleBuilder: solar/usage/SoC-aware simulation (see roadmap.MD) ---
$intelligentTz = new DateTimeZone('Europe/London');
$intelligentStrategy = ['cheap_slots_to_charge' => 6, 'expensive_slots_to_export' => 4, 'timezone' => 'Europe/London'];
$intelligentBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 4.0, 'max_discharge_kw' => 4.0, 'min_soc_on_grid' => 20, 'reserve_soc' => 20];

function makeIntelligentSlots(array $rates, DateTimeZone $tz, string $startDate): array
{
    $slots = [];
    $t = new DateTimeImmutable($startDate . ' 00:00', $tz);
    foreach ($rates as $rate) {
        $slots[] = ['from' => $t, 'to' => $t->modify('+30 minutes'), 'rate' => $rate];
        $t = $t->modify('+30 minutes');
    }
    return $slots;
}

// 48 flat-ish import rates with a cheap early-morning trough and one peak at 18:00.
$intelligentImportRates = array_fill(0, 48, 20.0);
for ($i = 4; $i <= 9; $i++) { $intelligentImportRates[$i] = 10.0; } // 02:00-05:00 cheap
$intelligentImportRates[36] = 40.0; // 18:00 peak
$intelligentImportSlots = makeIntelligentSlots($intelligentImportRates, $intelligentTz, '2026-06-01');
$intelligentCostBasis = array_fill(0, 48, 24.5);

// Sunny day: plenty of solar, no shortfall expected.
$sunnySolar = [];
$solarT = new DateTimeImmutable('2026-06-01 06:00', $intelligentTz);
for ($h = 6; $h < 20; $h++) {
    $sunnySolar[] = ['from' => $solarT, 'to' => $solarT->modify('+1 hour'), 'watt_hours' => 1500];
    $solarT = $solarT->modify('+1 hour');
}

$intelligentBuilder = new IntelligentScheduleBuilder($intelligentStrategy, $intelligentBattery, ['avg_daily_kwh' => 8.0]);
$sunny = $intelligentBuilder->build($intelligentImportSlots, null, $intelligentCostBasis, $sunnySolar, 50.0);
check(
    !array_filter($sunny['groups'], fn($g) => $g['workMode'] === 'ForceCharge'),
    'plenty of solar + moderate starting SoC means no grid force-charge is needed: got ' . json_encode(array_column($sunny['groups'], 'workMode')),
);

// No solar at all, battery starts low: should force-charge in the cheap trough, capped by real need.
$dark = $intelligentBuilder->build($intelligentImportSlots, null, $intelligentCostBasis, null, 15.0); // 15% of 10kWh = 1.5kWh
$chargeGroups = array_filter($dark['groups'], fn($g) => $g['workMode'] === 'ForceCharge');
check(count($chargeGroups) > 0, 'no solar + low starting SoC forces some grid charging in the cheap window');
foreach ($chargeGroups as $g) {
    check($g['startHour'] >= 2 && $g['startHour'] < 5, 'forced charge lands in the 02:00-05:00 cheap window, not elsewhere: got startHour=' . $g['startHour']);
}

// Battery already full: no charging needed even though rates are cheap (real energy bound, not just price).
$full = $intelligentBuilder->build($intelligentImportSlots, null, $intelligentCostBasis, null, 100.0);
check(
    !array_filter($full['groups'], fn($g) => $g['workMode'] === 'ForceCharge'),
    'a battery already at 100% is not force-charged just because a slot is cheap',
);

// finalSocPercent (GitHub issue #4's multi-day carry-over) — the projected SoC at the end
// of the day, used as the next known day's starting point instead of always re-reading
// the real live SoC (which is only meaningful for the first day in a run).
check(isset($full['finalSocPercent']) && $full['finalSocPercent'] >= 0.0 && $full['finalSocPercent'] <= 100.0, 'finalSocPercent is present and a valid percentage');
check(
    $full['finalSocPercent'] <= 100.0 && $full['finalSocPercent'] >= $intelligentBattery['min_soc_on_grid'] - 0.01,
    'a full battery with no forced charge/discharge drains no further than the min_soc_on_grid floor over a no-solar day: got ' . $full['finalSocPercent'],
);
check(
    $sunny['finalSocPercent'] >= 50.0,
    'plenty of solar all day and moderate starting SoC should not project a lower end-of-day SoC than the 50% it started at: got ' . $sunny['finalSocPercent'],
);

// Arbitrage: even a full battery should still charge if import is cheaper than the best export rate.
$intelligentExportRates = array_fill(0, 48, 12.0);
$intelligentExportRates[10] = 30.0; // one very high export slot makes early cheap import slots arbitrage-worthy
$intelligentExportSlots = makeIntelligentSlots($intelligentExportRates, $intelligentTz, '2026-06-01');
$arb = $intelligentBuilder->build($intelligentImportSlots, $intelligentExportSlots, $intelligentCostBasis, null, 100.0);
check(
    (bool) array_filter($arb['groups'], fn($g) => $g['workMode'] === 'ForceCharge'),
    'a full battery still charges on arbitrage (cheap import vs. a much higher export rate later)',
);

// Discharge never drains the projected SoC below the reserve floor. No export slots (no
// arbitrage) and a cost basis below every import rate (no cost-basis charging either)
// isolates "nothing gets charged, so nothing should get discharged".
$intelligentNoChargeBasis = array_fill(0, 48, 5.0);
$lowSoc = $intelligentBuilder->build($intelligentImportSlots, null, $intelligentNoChargeBasis, null, 22.0); // barely above 20% reserve
check(
    !array_filter($lowSoc['groups'], fn($g) => $g['workMode'] === 'ForceCharge'),
    'sanity check: nothing charges in this scenario (cost basis below every import rate)',
);
$dischargeGroups = array_filter($lowSoc['groups'], fn($g) => $g['workMode'] === 'ForceDischarge');
check(count($dischargeGroups) === 0, 'a battery barely above reserve with nothing charged gets no discharge slots forced: got ' . count($dischargeGroups));

// The natural (unforced) trajectory must floor at min_soc_on_grid, not reserve_soc — those
// are two different config values (CLAUDE.md: min_soc_on_grid is the general system floor,
// reserve_soc is specifically how far a *forced* discharge may drain it). Exercised here via
// the clear-space mechanism (discharge is no longer driven by import price alone — see the
// reworked build()): min_soc_on_grid 80% (8kWh of 10kWh) is well above reserve_soc 10% (1kWh).
// A battery that starts full and only ever sees load-only natural drain (no solar, no other
// charging) settles at the *higher* min_soc_on_grid floor by the time a late arbitrage
// window is evaluated. That 8kWh, plus the window's 4kWh demand, doesn't fit in a 10kWh
// battery — a 2kWh shortfall, needing exactly one discharge slot (2kWh at 4kW/0.5h) to clear.
// If the natural trajectory wrongly floored at reserve_soc instead, it would already be
// sitting at 1kWh with 9kWh of (illusory) headroom, no shortfall would be computed, and no
// discharge would be reserved at all.
$floorTestStrategy = ['cheap_slots_to_charge' => 2, 'expensive_slots_to_export' => 2, 'timezone' => 'Europe/London'];
$floorTestBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 4.0, 'max_discharge_kw' => 4.0, 'min_soc_on_grid' => 80, 'reserve_soc' => 10];
$floorTestBuilder = new IntelligentScheduleBuilder($floorTestStrategy, $floorTestBattery, ['avg_daily_kwh' => 48.0]); // 1kWh/slot load, no solar
$floorTestRates = array_fill(0, 48, 20.0);
$floorTestRates[40] = 5.0; // 20:00-20:30
$floorTestRates[41] = 5.0; // 20:30-21:00 — contiguous 2-slot arbitrage window (below the flat 12p export)
$floorTestSlots = makeIntelligentSlots($floorTestRates, $intelligentTz, '2026-06-01');
$floorTestExportRates = array_fill(0, 48, 12.0);
$floorTestExportSlots = makeIntelligentSlots($floorTestExportRates, $intelligentTz, '2026-06-01');
$floorTestCostBasis = array_fill(0, 48, 0.0); // nothing qualifies via cost basis — isolates arbitrage
$floorTest = $floorTestBuilder->build($floorTestSlots, $floorTestExportSlots, $floorTestCostBasis, null, 100.0); // battery starts full
$floorTestCharge = array_values(array_filter($floorTest['groups'], fn($g) => $g['workMode'] === 'ForceCharge'));
check(
    count($floorTestCharge) === 1 && $floorTestCharge[0]['startHour'] === 20 && $floorTestCharge[0]['endHour'] === 21,
    'the 20:00-21:00 arbitrage window (5p import vs. 12p export) is charged: got ' . json_encode($floorTestCharge),
);
$floorTestDischarge = array_values(array_filter($floorTest['groups'], fn($g) => $g['workMode'] === 'ForceDischarge'));
check(
    count($floorTestDischarge) === 1 && $floorTestDischarge[0]['startHour'] === 19 && $floorTestDischarge[0]['startMinute'] === 30,
    'a single clear-space slot (19:30-20:00) is reserved immediately before the window, sized against the min_soc_on_grid floor (8kWh), not the lower reserve_soc floor (1kWh): got ' . json_encode($floorTestDischarge),
);

// High starting SoC with a variable export rate: discharge should include the export peak.
$highSoc = $intelligentBuilder->build($intelligentImportSlots, $intelligentExportSlots, $intelligentCostBasis, null, 90.0);
$dischargeGroups2 = array_values(array_filter($highSoc['groups'], fn($g) => $g['workMode'] === 'ForceDischarge'));
check(count($dischargeGroups2) > 0, 'a battery at 90% with a variable export rate gets some discharge slots');
$coversExportPeak = (bool) array_filter($dischargeGroups2, fn($g) => $g['startHour'] === 5 && $g['startMinute'] === 0);
check($coversExportPeak, 'the 05:00 export-peak slot (30p vs. a flat 12p elsewhere) is among the discharge groups: got ' . json_encode(array_map(fn($g) => "{$g['startHour']}:{$g['startMinute']}", $dischargeGroups2)));

// The exact reported bug: a high-SoC battery with a genuine expensive import peak but no
// export data must never discharge to "offset" it — SelfUse already draws from the battery
// for load, proportional to actual need; forcing a discharge on top has no financial upside
// without an export justification, and was what emptied the battery mid-peak in practice.
$highSocNoExport = $intelligentBuilder->build($intelligentImportSlots, null, $intelligentCostBasis, null, 90.0);
$dischargeAtPeak = array_filter($highSocNoExport['groups'], fn($g) => $g['workMode'] === 'ForceDischarge');
check(count($dischargeAtPeak) === 0, 'a high-SoC battery with an expensive import peak and no export data gets zero discharge groups: got ' . count($dischargeAtPeak));

// High starting SoC with a variable export rate: discharge should include the export peak.
$highSoc = $intelligentBuilder->build($intelligentImportSlots, $intelligentExportSlots, $intelligentCostBasis, null, 90.0);
$dischargeGroups2 = array_values(array_filter($highSoc['groups'], fn($g) => $g['workMode'] === 'ForceDischarge'));
check(count($dischargeGroups2) > 0, 'a battery at 90% with a variable export rate gets some discharge slots');
$coversExportPeak = (bool) array_filter($dischargeGroups2, fn($g) => $g['startHour'] === 5 && $g['startMinute'] === 0);
check($coversExportPeak, 'the 05:00 export-peak slot (30p vs. a flat 12p elsewhere) is among the discharge groups: got ' . json_encode(array_map(fn($g) => "{$g['startHour']}:{$g['startMinute']}", $dischargeGroups2)));

check(count($sunny['groups']) === count($sunny['explanations']), 'groups and explanations arrays stay the same length');

// --- UsageEstimator: day-length-based seasonal interpolation (see roadmap.MD) ---
$usageTz = new DateTimeZone('Europe/London');

/** Only 'from'/'to' need fractional seconds to be treated as a dawn/dusk marker — see UsageEstimator. */
function makeSolarBucket(DateTimeImmutable $from, DateTimeImmutable $to, int $wattHours): array
{
    return ['from' => $from, 'to' => $to, 'watt_hours' => $wattHours, 'fetched_at' => $from];
}

// Day length at exactly the summer bound (16.6h) -> the summer figure is used as-is. Only
// the dawn/dusk instants themselves carry fractional seconds — every other boundary is a
// clean hour, matching the real API's shape (see UsageEstimator's detection logic).
$summerDawn = new DateTimeImmutable('2026-06-21 06:00:01', $usageTz);
$summerDusk = new DateTimeImmutable('2026-06-21 22:36:01', $usageTz); // dawn + 16h36m = 16.6h
$summerForecast = [
    makeSolarBucket($summerDawn, new DateTimeImmutable('2026-06-21 07:00:00', $usageTz), 200),
    makeSolarBucket(new DateTimeImmutable('2026-06-21 22:00:00', $usageTz), $summerDusk, 100),
];
$summerDaily = UsageEstimator::estimateDailyKwh(300.0, 700.0, $summerDawn, $usageTz, $summerForecast);
check(abs($summerDaily - 300.0 / 30.44) < 0.01, "day length at the summer bound (16.6h) uses the summer figure as-is: got $summerDaily");

// Day length at exactly the winter bound (7.7h) -> the winter figure is used as-is.
$winterDawn = new DateTimeImmutable('2026-12-21 08:00:01', $usageTz);
$winterDusk = new DateTimeImmutable('2026-12-21 15:42:01', $usageTz); // dawn + 7h42m = 7.7h
$winterForecast = [
    makeSolarBucket($winterDawn, new DateTimeImmutable('2026-12-21 09:00:00', $usageTz), 100),
    makeSolarBucket(new DateTimeImmutable('2026-12-21 15:00:00', $usageTz), $winterDusk, 20),
];
$winterDaily = UsageEstimator::estimateDailyKwh(300.0, 700.0, $winterDawn, $usageTz, $winterForecast);
check(abs($winterDaily - 700.0 / 30.44) < 0.01, "day length at the winter bound (7.7h) uses the winter figure as-is: got $winterDaily");

// A day length beyond the summer bound still clamps to the summer figure, not extrapolated past it.
$longDawn = new DateTimeImmutable('2026-06-21 04:00:01', $usageTz);
$longDusk = new DateTimeImmutable('2026-06-22 00:00:01', $usageTz); // dawn + 20h, well beyond the 16.6h bound
$longForecast = [
    makeSolarBucket($longDawn, new DateTimeImmutable('2026-06-21 05:00:00', $usageTz), 200),
    makeSolarBucket(new DateTimeImmutable('2026-06-21 23:00:00', $usageTz), $longDusk, 50),
];
$longDaily = UsageEstimator::estimateDailyKwh(300.0, 700.0, $longDawn, $usageTz, $longForecast);
check(abs($longDaily - 300.0 / 30.44) < 0.01, "a day length beyond the summer bound clamps to the summer figure rather than extrapolating: got $longDaily");

// No solar data at all -> falls back to a day-of-year estimate, still within the summer/winter range,
// and clearly higher in midwinter than midsummer.
$noSolarSummer = UsageEstimator::estimateDailyKwh(300.0, 700.0, new DateTimeImmutable('2026-06-21', $usageTz), $usageTz, []);
$noSolarWinter = UsageEstimator::estimateDailyKwh(300.0, 700.0, new DateTimeImmutable('2026-12-21', $usageTz), $usageTz, []);
check(
    $noSolarSummer >= 300.0 / 30.44 - 0.01 && $noSolarSummer <= 700.0 / 30.44 + 0.01,
    "with no solar data, the day-of-year fallback still lands within the summer-winter range: got $noSolarSummer",
);
check($noSolarWinter > $noSolarSummer, "the day-of-year fallback estimates higher usage in December than June: got $noSolarWinter vs $noSolarSummer");

// A forecast spanning two days picks the target date's own dawn/dusk pair, not always the first day's.
$day1Dawn = new DateTimeImmutable('2026-03-01 07:00:01', $usageTz);
$day1Dusk = new DateTimeImmutable('2026-03-01 17:00:01', $usageTz); // an unremarkable 10h day length, day 1 — should be ignored
$day2Dawn = new DateTimeImmutable('2026-03-02 06:58:01', $usageTz);
$day2Dusk = new DateTimeImmutable('2026-03-02 23:34:01', $usageTz); // exactly the summer bound (16h36m), day 2 — should be picked up
$twoDayForecast = [
    makeSolarBucket($day1Dawn, new DateTimeImmutable('2026-03-01 08:00:00', $usageTz), 200),
    makeSolarBucket(new DateTimeImmutable('2026-03-01 16:00:00', $usageTz), $day1Dusk, 40),
    makeSolarBucket($day2Dawn, new DateTimeImmutable('2026-03-02 07:00:00', $usageTz), 220),
    makeSolarBucket(new DateTimeImmutable('2026-03-02 23:00:00', $usageTz), $day2Dusk, 60),
];
$day2Daily = UsageEstimator::estimateDailyKwh(300.0, 700.0, $day2Dawn, $usageTz, $twoDayForecast);
check(abs($day2Daily - 300.0 / 30.44) < 0.01, "a multi-day forecast uses the target date's own dawn/dusk pair, not the first day's: got $day2Daily");

// --- HalfHourlyUsageEstimator (GitHub issue #5) ---
/** @param array<string, float|array<int,float>> $dateToHourly Y-m-d => flat hourly kWh, or [hour => kWh] for a specific shape */
function buildHistoricUsageRows(array $dateToHourly, DateTimeZone $timezone): array
{
    $rows = [];
    foreach ($dateToHourly as $dateStr => $hourlyOrFlat) {
        $dayStart = new DateTimeImmutable($dateStr, $timezone);
        for ($h = 0; $h < 24; $h++) {
            $value = is_array($hourlyOrFlat) ? ($hourlyOrFlat[$h] ?? null) : $hourlyOrFlat;
            if ($value === null) {
                continue;
            }
            $from = $dayStart->setTime($h, 0);
            $rows[] = ['from' => $from, 'to' => $from->modify('+1 hour'), 'generation_kwh' => null, 'forecast_kwh' => null, 'usage_kwh' => $value];
        }
    }
    return $rows;
}

// 2026-01-07 is a Wednesday (a weekday); its ISO week (Mon-Fri) falls on clean weekday-only
// weeks for at least 6 years back — confirmed live before writing this fixture.
$refWednesday = new DateTimeImmutable('2026-01-07', $usageTz);
check((int) $refWednesday->format('N') === 3, 'sanity: the reference fixture date is genuinely a Wednesday');

// Fewer than 3 valid days anywhere in history -> flat 8am-20:00 fallback, using the
// existing UsageEstimator's daily estimate, zero outside that window.
$noHistory = HalfHourlyUsageEstimator::estimateHalfHourly($refWednesday, $usageTz, [], 300.0, 700.0);
check(count($noHistory) === 48, 'estimateHalfHourly() always returns exactly 48 values');
check($noHistory[0] === 0.0 && $noHistory[15] === 0.0 && $noHistory[47] === 0.0, 'the <3-days fallback is zero outside 8am-20:00: got ' . json_encode([$noHistory[0], $noHistory[15], $noHistory[47]]));
$fallbackDaily = UsageEstimator::estimateDailyKwh(300.0, 700.0, $refWednesday, $usageTz, []);
check(abs($noHistory[16] - $fallbackDaily / 24) < 0.001, '8am (index 16) in the fallback is the existing UsageEstimator daily estimate spread flat across the 24 daytime half-hours: got ' . $noHistory[16] . ' vs expected ' . ($fallbackDaily / 24));
check(abs(array_sum($noHistory) - $fallbackDaily) < 0.001, 'the fallback\'s 48 values sum back to the same daily total UsageEstimator would give');

// Exactly 2 valid weekday days (below the 3-day minimum) -> still falls back, even though data exists.
$almostEnough = buildHistoricUsageRows(['2026-01-06' => 4.0, '2026-01-05' => 4.0], $usageTz); // Tue, Mon before the reference Wednesday
$stillFallback = HalfHourlyUsageEstimator::estimateHalfHourly($refWednesday, $usageTz, $almostEnough, 300.0, 700.0);
check($stillFallback[16] === $noHistory[16], 'exactly 2 valid days (below MIN_VALID_DAYS=3) still falls back to the flat estimate, not a 2-day average');

// Day-type filtering: a block of Saturday history with a wildly different value must never
// leak into a weekday forecast, even when it's the only data available.
$saturdayOnly = buildHistoricUsageRows(['2026-01-03' => 50.0, '2025-12-27' => 50.0, '2025-12-20' => 50.0], $usageTz); // three Saturdays
$weekdayFromSaturdays = HalfHourlyUsageEstimator::estimateHalfHourly($refWednesday, $usageTz, $saturdayOnly, 300.0, 700.0);
check($weekdayFromSaturdays[16] === $noHistory[16], 'Saturday-only history never satisfies a weekday forecast\'s day-type filter, so it still falls back rather than averaging in mismatched days');

// Basic averaging: 3 valid weekdays with known, distinct flat hourly values average correctly,
// and each hour splits into two equal half-hour values.
$threeWeekdays = buildHistoricUsageRows([
    '2026-01-06' => 2.0, // Tue
    '2026-01-05' => 4.0, // Mon
    '2025-12-31' => 6.0, // Wed (last Wednesday, tier 2)
], $usageTz);
$averaged = HalfHourlyUsageEstimator::estimateHalfHourly($refWednesday, $usageTz, $threeWeekdays, 300.0, 700.0);
check(abs($averaged[0] - 2.0) < 0.001 && abs($averaged[1] - 2.0) < 0.001, "average of 2.0/4.0/6.0 flat-hourly is 4.0/hour = 2.0/half-hour, and both half-hours of hour 0 match: got {$averaged[0]}, {$averaged[1]}");

// Tier 1 (same ISO week, previous years) fills the 30-candidate cap on its own for a
// weekday reference (5 weekday candidates/year x 6 years = 30 exactly), so tier 2 (last 28
// days) must be entirely excluded once that happens, even though it's also present.
// $refWednesday's own ISO week (confirmed live: it's week 2, not week 1 — Jan 4 2026 is a
// Sunday, so week 1 is Dec 29 2025-Jan 4 2026 and week 2 starts Jan 5) — must match
// exactly, or the fixture lands on the wrong week and this test would silently exercise
// the empty-tier-1 case instead of the cap-exclusion behaviour it's meant to prove.
$refIsoWeek = (int) $refWednesday->format('W');
$tier1Fixture = [];
foreach ([2025, 2024, 2023, 2022, 2021, 2020] as $isoYear) {
    $jan4 = new DateTimeImmutable("$isoYear-01-04", $usageTz);
    $week1Monday = $jan4->modify('-' . ((int) $jan4->format('N') - 1) . ' days');
    $weekMonday = $week1Monday->modify('+' . (($refIsoWeek - 1) * 7) . ' days');
    for ($offset = 0; $offset <= 4; $offset++) { // Mon-Fri
        $tier1Fixture[$weekMonday->modify("+$offset days")->format('Y-m-d')] = 10.0;
    }
}
$tier2Fixture = [];
for ($daysBack = 1; $daysBack <= 28; $daysBack++) {
    $d = $refWednesday->modify("-$daysBack days");
    if ((int) $d->format('N') <= 5) {
        $tier2Fixture[$d->format('Y-m-d')] = 99.0; // dramatically different from tier 1's 10.0
    }
}
$tierMix = HalfHourlyUsageEstimator::estimateHalfHourly($refWednesday, $usageTz, buildHistoricUsageRows($tier1Fixture + $tier2Fixture, $usageTz), 300.0, 700.0);
check(abs($tierMix[0] - 5.0) < 0.001, 'tier 1 alone reaches the 30-day cap for a weekday reference, so tier 2\'s 99.0 never gets averaged in: got ' . $tierMix[0] . ', expected 5.0 (half of 10.0/hour)');

// --- ModellingScheduleBuilder (GitHub issue #5) — dynamic-programming solver ---
$mTz = new DateTimeZone('Europe/London');
$mStrategy = ['timezone' => 'Europe/London'];
$mBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 4.0, 'max_discharge_kw' => 4.0, 'min_soc_on_grid' => 0, 'reserve_soc' => 0, 'round_trip_efficiency_pct' => 100.0];

// A minimum-end-of-horizon-SoC constraint forces some charging with no usage/solar to
// justify it otherwise — the optimiser must pick the cheap slot to do it in, not the
// expensive one, to reach the same required end state at lower cost.
$cheapFirstSlots = buildSlotsFrom([10.0, 50.0], new DateTimeImmutable('2026-03-01 00:00:00', $mTz));
$noUsage = [0.0, 0.0];
$forceEndAt20pct = ['soc_bin_kwh' => 1.0, 'min_end_soc_pct' => 20];
$cheapCharge = (new ModellingScheduleBuilder($mStrategy, $mBattery, $forceEndAt20pct))->build($cheapFirstSlots, null, $noUsage, null, 0.0);
check(
    count($cheapCharge['intervals']) === 1 && $cheapCharge['intervals'][0]['workMode'] === 'ForceCharge',
    'a single ForceCharge interval satisfies the min-end-SoC constraint: got ' . json_encode(array_column($cheapCharge['intervals'], 'workMode')),
);
check($cheapCharge['intervals'][0]['start']->format('H:i') === '00:00', 'charging happens in the cheap first slot (10p), not the expensive second one (50p)');
check(abs($cheapCharge['finalSocPercent'] - 20.0) < 1.0, 'finalSocPercent reaches the 20% minimum end-of-horizon target: got ' . $cheapCharge['finalSocPercent']);
check(abs($cheapCharge['totalCostPence'] - 20.0) < 0.5, "charging 2kWh at 10p/kWh costs 20p total, cheaper than the 100p charging in the expensive slot would cost: got {$cheapCharge['totalCostPence']}");

// An absurdly high max_charge_kw must still clamp the projected end SoC at 100%, not beyond.
$hugeBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 1000.0, 'max_discharge_kw' => 4.0, 'min_soc_on_grid' => 0, 'reserve_soc' => 0, 'round_trip_efficiency_pct' => 100.0];
$oneSlot = buildSlotsFrom([1.0], new DateTimeImmutable('2026-03-01 00:00:00', $mTz));
$forceFull = ['soc_bin_kwh' => 1.0, 'min_end_soc_pct' => 100];
$capacityTest = (new ModellingScheduleBuilder($mStrategy, $hugeBattery, $forceFull))->build($oneSlot, null, [0.0], null, 0.0);
check(abs($capacityTest['finalSocPercent'] - 100.0) < 1.0, "an absurdly high max_charge_kw (1000kW) still clamps the projected SoC at capacity (100%), not beyond: got {$capacityTest['finalSocPercent']}");

// An absurdly high max_discharge_kw must still clamp at the reserve floor, not below —
// even with a highly favourable export price making the optimiser want to sell as much as
// possible.
$hugeDischargeBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 4.0, 'max_discharge_kw' => 1000.0, 'min_soc_on_grid' => 0, 'reserve_soc' => 50, 'round_trip_efficiency_pct' => 100.0];
$sellImport = buildSlotsFrom([10.0], new DateTimeImmutable('2026-03-01 00:00:00', $mTz));
$sellExport = buildSlotsFrom([100.0], new DateTimeImmutable('2026-03-01 00:00:00', $mTz));
$noConstraint = ['soc_bin_kwh' => 1.0, 'min_end_soc_pct' => 0];
$reserveTest = (new ModellingScheduleBuilder($mStrategy, $hugeDischargeBattery, $noConstraint))->build($sellImport, $sellExport, [0.0], null, 100.0);
check(abs($reserveTest['finalSocPercent'] - 50.0) < 1.0, "an absurdly high max_discharge_kw (1000kW) still clamps at the reserve floor (50%), not below: got {$reserveTest['finalSocPercent']}");
check(count($reserveTest['intervals']) === 1 && $reserveTest['intervals'][0]['workMode'] === 'ForceDischarge', 'selling at a highly favourable export price is chosen over idling');

// A real bug found via live verification against actual Agile prices, not by inspection:
// starting exactly at the reserve floor (the common case when no live SoC reading is
// available — see the class doc comment's fallback), ForceDischarge can't actually
// discharge anything (available energy is 0), so it produces the *same* net grid flow and
// cost as SelfUse for that slot — a tie the DP must resolve in SelfUse's favour, since
// ForceDischarge winning it arbitrarily would emit a real, misleadingly-explained
// "discharge" group to push to the inverter for a slot where nothing is actually
// discharged. ACTIONS' evaluation order (SelfUse first) is what makes this deterministic.
$tieBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 4.0, 'max_discharge_kw' => 4.0, 'min_soc_on_grid' => 20, 'reserve_soc' => 20, 'round_trip_efficiency_pct' => 100.0];
$tieSlots = buildSlotsFrom([30.0, 30.0], new DateTimeImmutable('2026-03-01 00:00:00', $mTz));
$tieUsage = [1.0, 1.0];
$tieModelling = ['soc_bin_kwh' => 1.0, 'min_end_soc_pct' => 0];
$tieResult = (new ModellingScheduleBuilder($mStrategy, $tieBattery, $tieModelling))->build($tieSlots, null, $tieUsage, null, 20.0);
check(
    count($tieResult['intervals']) === 0,
    'starting exactly at the reserve floor with no way to actually discharge, a cost tie between ForceDischarge and SelfUse resolves to SelfUse (no pushed group), not a no-op ForceDischarge: got ' . json_encode(array_column($tieResult['intervals'], 'workMode')),
);

// A second real bug found via live verification against actual Agile prices (production
// data, not this fixture): a battery starting full, with a flat/fixed export price well
// below the day's import rate, was force-discharging down to the minimum end-of-horizon
// SoC purely to "sell" the stored energy at that flat export rate — even though nothing
// needed offsetting (self-use alone comfortably covers usage without ever touching the
// grid) and that stored energy is worth strictly more than the flat export rate once you
// account for what it would cost to buy it back later. Root cause: pickTerminalBin() only
// compared *ending SoC ≥ floor*, treating anything held above the floor as worth nothing —
// so any non-negative export price looked like free money. Fixed by crediting SoC held
// above the floor at the horizon's own cheapest import rate (see pickTerminalBin()'s doc
// comment) — a proxy for what that energy would actually cost to replace.
$dumpBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 3.0, 'max_discharge_kw' => 3.0, 'min_soc_on_grid' => 15, 'reserve_soc' => 15, 'round_trip_efficiency_pct' => 100.0];
$dumpModelling = ['soc_bin_kwh' => 0.1, 'min_end_soc_pct' => 20];
$dumpImportRates = array_fill(0, 10, 25.0);
$dumpExportRates = array_fill(0, 10, 12.0); // flat, well below import — the reported scenario
$dumpImportSlots = buildSlotsFrom($dumpImportRates, new DateTimeImmutable('2026-08-19 12:00:00', $mTz));
$dumpExportSlots = buildSlotsFrom($dumpExportRates, new DateTimeImmutable('2026-08-19 12:00:00', $mTz));
$dumpUsage = array_fill(0, 10, 0.3); // modest, well below the 1.5kWh/slot rated discharge
$dumpResult = (new ModellingScheduleBuilder($mStrategy, $dumpBattery, $dumpModelling))->build($dumpImportSlots, $dumpExportSlots, $dumpUsage, null, 100.0);
check(
    count($dumpResult['intervals']) === 0,
    'a fully-charged battery with a flat export rate below import stays on self-use rather than force-discharging to sell stored energy at a loss: got ' . json_encode(array_column($dumpResult['intervals'], 'workMode')),
);
check(
    $dumpResult['finalSocPercent'] > 20.0 + 1.0,
    "self-use alone ends well above the 20% floor (usage is fully covered by the 100%-charged battery) rather than being drained down to it: got {$dumpResult['finalSocPercent']}%",
);
check(abs($dumpResult['totalCostPence'] - 0.0) < 0.5, "self-use alone costs nothing (no grid import, no needless export): got {$dumpResult['totalCostPence']}p");

// A third real bug, found chasing the second one against real Agile prices with a real
// solar forecast: even after capping ForceDischarge's export at that reference price, a
// near-full battery (minimal headroom) with an early solar surplus was still being
// force-discharged — not to sell stored energy at a bad price directly, but to *manufacture
// headroom* so more of the forecast solar would land in the battery instead of spilling to
// the flat export rate. That's a real edge, but it's a bet on the solar forecast being
// accurate, not a price the optimiser actually knows — and self-use's own natural
// absorption already captures a solar surplus for free whenever there's genuine headroom,
// so nothing was actually being gained here that self-use wouldn't already do. Root cause:
// the cap added for the second bug only capped the *discharge* amount at zero when selling
// wasn't worthwhile — it didn't make ForceDischarge absorb the surplus into the battery the
// way self-use does, so even a "capped to zero" ForceDischarge still quietly dumped a
// sliver of solar as export instead of storing it, coming out very slightly cheaper than
// self-use and winning the tie. Fixed by making the no-sell/surplus case degenerate to
// self-use's own absorption formula exactly (see transitionForceDischarge()'s doc comment).
$solarBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 3.0, 'max_discharge_kw' => 3.0, 'min_soc_on_grid' => 15, 'reserve_soc' => 15, 'round_trip_efficiency_pct' => 100.0];
$solarModelling = ['soc_bin_kwh' => 0.1, 'min_end_soc_pct' => 20];
// A real Agile day's shape (not flat, and a fuller 20-slot/10h horizon) is what actually
// triggers this bug — a flat rate, or a too-short horizon, gives the DP no reason to
// manufacture headroom at all, so smaller/flatter drafts of this fixture passed even on the
// unfixed code, proving nothing. This is the exact rate sequence pulled live from Octopus
// that first reproduced the bug against production data.
$solarImportRates = [22.2495, 22.1235, 22.2495, 22.2495, 22.323, 22.533, 37.443, 38.178, 40.173, 41.8215, 44.6775, 46.221, 35.343, 35.343, 35.343, 35.1225, 35.133, 32.613, 32.613, 29.736];
$solarExportRates = array_fill(0, 20, 12.0); // flat, well below import
$solarStart = new DateTimeImmutable('2026-08-19 13:00:00', $mTz);
$solarImportSlots = buildSlotsFrom($solarImportRates, $solarStart);
$solarExportSlots = buildSlotsFrom($solarExportRates, $solarStart);
$solarUsage = array_fill(0, 20, 7.5 / 20);
// Declining solar (afternoon into evening), summing to ~11.9kWh — the live forecast total.
$solarShape = [1.4, 1.3, 1.2, 1.1, 1.0, 0.9, 0.8, 0.7, 0.6, 0.5, 0.4, 0.3, 0.2, 0.1, 0.05, 0.02, 0.0, 0.0, 0.0, 0.0];
$solarScale = 11.9 / array_sum($solarShape);
$solarForecastSlots = [];
foreach ($solarImportSlots as $i => $slot) {
    $solarForecastSlots[] = ['from' => $slot['from'], 'to' => $slot['to'], 'watt_hours' => $solarShape[$i] * $solarScale * 1000.0];
}
$solarResult = (new ModellingScheduleBuilder($mStrategy, $solarBattery, $solarModelling))->build($solarImportSlots, $solarExportSlots, $solarUsage, $solarForecastSlots, 99.0);
check(
    count($solarResult['intervals']) === 0,
    'a near-full battery with an early solar surplus and a flat export rate below import stays on self-use — no force-discharge to manufacture headroom for forecast solar: got ' . json_encode(array_column($solarResult['intervals'], 'workMode')),
);

// User clarification: since self-use already sells any solar surplus the battery can't
// absorb, deliberately selling *stored* capacity is only ever justified by a price that
// beats what it would cost to buy an equivalent *usable* kWh back — and round-trip
// efficiency means that's strictly more than the bare cheapest import rate (recharging
// 1kWh of usable capacity draws more than 1kWh from the grid). A bare price-match export
// (worthwhile under the un-adjusted threshold) is actually a guaranteed loss once
// efficiency is accounted for, and must NOT trigger a deliberate discharge; a export price
// that clears the efficiency-adjusted bar still should.
$effBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 4.0, 'max_discharge_kw' => 4.0, 'min_soc_on_grid' => 0, 'reserve_soc' => 0, 'round_trip_efficiency_pct' => 80.0];
$effModelling = ['soc_bin_kwh' => 0.1, 'min_end_soc_pct' => 0];
$effStart = new DateTimeImmutable('2026-03-01 00:00:00', $mTz);
$effUsage = [0.0, 0.0];
// Cheapest import in the horizon is 20p (slot 1) -> replacement price = 20/0.8 = 25p.
$effImportSlots = buildSlotsFrom([999.0, 20.0], $effStart);
$marginalExportSlots = buildSlotsFrom([22.0, 20.0], $effStart); // 22p clears 20p but not 25p
$marginalResult = (new ModellingScheduleBuilder($mStrategy, $effBattery, $effModelling))->build($effImportSlots, $marginalExportSlots, $effUsage, null, 100.0);
check(
    count($marginalResult['intervals']) === 0,
    "an export price (22p) above the bare cheapest import rate (20p) but below the efficiency-adjusted replacement cost (20p / 80% = 25p) must not trigger a deliberate discharge — it's a guaranteed loss on the round trip: got " . json_encode(array_column($marginalResult['intervals'], 'workMode')),
);
$profitableExportSlots = buildSlotsFrom([30.0, 20.0], $effStart); // 30p clears the 25p bar
$profitableResult = (new ModellingScheduleBuilder($mStrategy, $effBattery, $effModelling))->build($effImportSlots, $profitableExportSlots, $effUsage, null, 100.0);
check(
    count($profitableResult['intervals']) === 1 && $profitableResult['intervals'][0]['workMode'] === 'ForceDischarge',
    'an export price (30p) that clears the efficiency-adjusted replacement cost (25p) still triggers a genuine discharge: got ' . json_encode(array_column($profitableResult['intervals'], 'workMode')),
);

// The optimiser's own reported cost must genuinely beat a naive always-idle baseline
// (every slot just imports exactly what covers usage at that slot's own price) — proves
// it's actually solving, not just producing *a* valid schedule.
$costBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 4.0, 'max_discharge_kw' => 4.0, 'min_soc_on_grid' => 10, 'reserve_soc' => 10, 'round_trip_efficiency_pct' => 95.0];
$costModelling = ['soc_bin_kwh' => 0.5, 'min_end_soc_pct' => 10];
$costRates = [10.0, 10.0, 60.0, 60.0, 10.0, 10.0, 60.0, 60.0];
$costSlots = buildSlotsFrom($costRates, new DateTimeImmutable('2026-03-01 00:00:00', $mTz));
$costUsage = array_fill(0, 8, 1.0);
$optimised = (new ModellingScheduleBuilder($mStrategy, $costBattery, $costModelling))->build($costSlots, null, $costUsage, null, 10.0);
$idleCost = 0.0;
for ($i = 0; $i < 8; $i++) {
    $idleCost += $costUsage[$i] * $costRates[$i];
}
check(
    $optimised['totalCostPence'] < $idleCost - 0.01,
    "the DP's optimal cost ({$optimised['totalCostPence']}p) beats the always-idle baseline ({$idleCost}p) of buying exactly what's needed every slot at that slot's own price",
);
check(isset($optimised['finalSocPercent']), 'finalSocPercent is present in the return value');
check(count($optimised['intervals']) > 0, 'the cost-beating schedule actually contains some force-charge/discharge activity, not an empty plan');

// --- Schedulers.php: buildModellingSchedule() (GitHub issue #5) ---
check(isset(SCHEDULER_DEFINITIONS['modelling']), 'the modelling scheduler is registered');
check(resolveSchedulerId('modelling') === 'modelling', 'resolveSchedulerId() accepts the modelling scheduler as an override');

// The two cheapest slots straddle a UTC midnight boundary — the DP should charge in
// exactly those two (to satisfy the min-end-SoC target at lowest cost), producing one
// merged ForceCharge interval that crosses midnight. buildModellingSchedule() must split
// that single interval into two correctly-clipped per-date entries, not silently drop the
// portion on the far side of the boundary or misattribute the whole thing to one date.
$midnightTz = new DateTimeZone('UTC');
$midnightImport = buildSlotsFrom([50.0, 10.0, 10.0, 50.0], new DateTimeImmutable('2026-03-01 23:00:00', $midnightTz));
$midnightUsage = [0.0, 0.0, 0.0, 0.0];
$midnightBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 4.0, 'max_discharge_kw' => 4.0, 'min_soc_on_grid' => 0, 'reserve_soc' => 0, 'round_trip_efficiency_pct' => 100.0];
$midnightModelling = ['soc_bin_kwh' => 1.0, 'min_end_soc_pct' => 40];
$midnightSchedule = buildModellingSchedule($mStrategy, $midnightBattery, $midnightModelling, $midnightImport, null, $midnightUsage, null, 0.0, $midnightTz);

check(array_keys($midnightSchedule) === ['2026-03-01', '2026-03-02'], 'buildModellingSchedule() produces one entry per calendar date the rolling window touches: got ' . json_encode(array_keys($midnightSchedule)));

$day1Charge = array_values(array_filter($midnightSchedule['2026-03-01']['groups'], fn($g) => $g['workMode'] === 'ForceCharge'));
$day2Charge = array_values(array_filter($midnightSchedule['2026-03-02']['groups'], fn($g) => $g['workMode'] === 'ForceCharge'));
check(
    count($day1Charge) === 1 && $day1Charge[0]['startHour'] === 23 && $day1Charge[0]['startMinute'] === 30 && $day1Charge[0]['endHour'] === 0 && $day1Charge[0]['endMinute'] === 0,
    'a ForceCharge interval crossing midnight is clipped to end at 00:00 on the first date: got ' . json_encode($day1Charge),
);
check(
    count($day2Charge) === 1 && $day2Charge[0]['startHour'] === 0 && $day2Charge[0]['startMinute'] === 0 && $day2Charge[0]['endHour'] === 0 && $day2Charge[0]['endMinute'] === 30,
    'the same interval continues from 00:00 on the second date: got ' . json_encode($day2Charge),
);
check($midnightSchedule['2026-03-01']['summary'] === $midnightSchedule['2026-03-02']['summary'], 'the whole-window summary is attached identically to every touched date, since the DP doesn\'t compute a separate per-date breakdown');

// --- Schedulers.php: modellingWindowEnd() plans as far ahead as data allows (user-requested,
// replacing the old fixed +24h cap — see CLAUDE.md's "Horizon later widened" note) ---
$priceHorizon48h = new DateTimeImmutable('2026-05-03 00:00:00', $mTz); // 48h out from an assumed "now" of 2026-05-01 00:00
check(modellingWindowEnd(null, null) === null, 'no known price data at all means no window to plan');
check(modellingWindowEnd($priceHorizon48h, null) == $priceHorizon48h, 'with no solar data, the window extends all the way to the price horizon — not capped at 24h');
check(modellingWindowEnd($priceHorizon48h, []) == $priceHorizon48h, 'an empty solar array is treated the same as no solar data (not a zero-length window)');

$solarHorizon30h = new DateTimeImmutable('2026-05-02 06:00:00', $mTz); // sooner than the 48h price horizon
$shortSolar = [
    ['from' => new DateTimeImmutable('2026-05-01 06:00:00', $mTz), 'to' => new DateTimeImmutable('2026-05-01 20:00:00', $mTz), 'watt_hours' => 5000],
    ['from' => new DateTimeImmutable('2026-05-02 00:00:00', $mTz), 'to' => $solarHorizon30h, 'watt_hours' => 0],
];
check(
    modellingWindowEnd($priceHorizon48h, $shortSolar) == $solarHorizon30h,
    'when solar forecast data runs out sooner than price data, the shorter of the two wins: got ' . modellingWindowEnd($priceHorizon48h, $shortSolar)->format('Y-m-d H:i'),
);

$solarHorizon60h = new DateTimeImmutable('2026-05-03 12:00:00', $mTz); // later than the 48h price horizon
$longSolar = [['from' => new DateTimeImmutable('2026-05-01 06:00:00', $mTz), 'to' => $solarHorizon60h, 'watt_hours' => 5000]];
check(
    modellingWindowEnd($priceHorizon48h, $longSolar) == $priceHorizon48h,
    'when solar forecast data extends further than price data, price stays the binding constraint (nothing to schedule against beyond it anyway)',
);

// --- ModellingScheduleBuilder: overrides fed in as compulsory DP actions (user-requested) ---
// Rather than painting an override onto the DP's output afterward (the way the other two
// schedulers' overrides work), a forced slot restricts the DP to a single action for that
// slot and lets it optimise every other slot around the resulting SoC trajectory. Slot 0 is
// the expensive one (50p) — left free, the DP would charge in one of the cheap slots (10p)
// instead, exactly like the existing "cheap slot preferred" test above. Forcing slot 0 to
// ForceCharge must make the DP charge there *and* recognise that the forced charge alone
// already meets the 20% end target, so it must NOT also charge in a cheap slot afterward —
// that's the "optimise around it" half of the ask, not just "obey it".
$forcedChargeBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 4.0, 'max_discharge_kw' => 4.0, 'min_soc_on_grid' => 0, 'reserve_soc' => 0, 'round_trip_efficiency_pct' => 100.0];
$forcedChargeModelling = ['soc_bin_kwh' => 0.5, 'min_end_soc_pct' => 20];
$forcedChargeSlots = buildSlotsFrom([50.0, 10.0, 10.0], new DateTimeImmutable('2026-04-01 00:00:00', $mTz));
$forcedChargeUsage = [0.0, 0.0, 0.0];
$forcedChargeResult = (new ModellingScheduleBuilder($mStrategy, $forcedChargeBattery, $forcedChargeModelling))
    ->build($forcedChargeSlots, null, $forcedChargeUsage, null, 0.0, ['ForceCharge', null, null]);
check(
    count($forcedChargeResult['intervals']) === 1 && $forcedChargeResult['intervals'][0]['workMode'] === 'ForceCharge' && $forcedChargeResult['intervals'][0]['start']->format('H:i') === '00:00',
    'a forced ForceCharge on the expensive slot is obeyed even though the DP would otherwise have preferred a cheap slot: got ' . json_encode(array_column($forcedChargeResult['intervals'], 'workMode')),
);
check(
    abs($forcedChargeResult['totalCostPence'] - 100.0) < 0.5,
    "charging 2kWh at the forced (expensive, 50p) slot costs 100p, and the DP must not also charge in a cheap slot once the end-SoC target is already met by the forced charge: got {$forcedChargeResult['totalCostPence']}p",
);

// The opposite direction: a forced SelfUse must block an otherwise clearly profitable
// discharge — same shape as the "highly favourable export price" test above (which left
// this slot free and got a ForceDischarge), but with the slot forced to SelfUse instead.
// This is exactly what a power_down event window means (see ScheduleBuilder::overrideModesFor()'s
// doc comment): held in reserve, not sold, even when the DP would otherwise want to sell.
$forcedSelfUseBattery = ['capacity_kwh' => 10.0, 'max_charge_kw' => 4.0, 'max_discharge_kw' => 1000.0, 'min_soc_on_grid' => 0, 'reserve_soc' => 50, 'round_trip_efficiency_pct' => 100.0];
$forcedSelfUseModelling = ['soc_bin_kwh' => 1.0, 'min_end_soc_pct' => 0];
$forcedSelfUseImport = buildSlotsFrom([10.0], new DateTimeImmutable('2026-04-01 00:00:00', $mTz));
$forcedSelfUseExport = buildSlotsFrom([100.0], new DateTimeImmutable('2026-04-01 00:00:00', $mTz));
$forcedSelfUseResult = (new ModellingScheduleBuilder($mStrategy, $forcedSelfUseBattery, $forcedSelfUseModelling))
    ->build($forcedSelfUseImport, $forcedSelfUseExport, [0.0], null, 100.0, ['SelfUse']);
check(
    count($forcedSelfUseResult['intervals']) === 0,
    'a forced SelfUse blocks a discharge the DP would otherwise clearly want (100p export vs 10p import): got ' . json_encode(array_column($forcedSelfUseResult['intervals'], 'workMode')),
);
check(abs($forcedSelfUseResult['finalSocPercent'] - 100.0) < 1.0, 'the battery stays at its starting 100% — forced self-use with no usage/solar genuinely does nothing: got ' . $forcedSelfUseResult['finalSocPercent']);

// --- Schedulers.php: buildForcedActionsFromOverrides() — translates overrides into per-slot
// compulsory actions ahead of the DP, so it can optimise around them rather than have them
// painted on afterward (user-requested) ---
$overrideTz = new DateTimeZone('Europe/London');
saveOverride('2026-04-02', 'fill_your_boots', '10:00', '11:00', '09:00', '10:00');
$bootsSlots = buildSlotsFrom(array_fill(0, 8, 20.0), new DateTimeImmutable('2026-04-02 08:00:00', $overrideTz));
$bootsForced = buildForcedActionsFromOverrides($bootsSlots, $overrideTz);
check(
    $bootsForced === [null, null, 'ForceDischarge', 'ForceDischarge', 'ForceCharge', 'ForceCharge', null, null],
    'fill_your_boots translates to a ForceDischarge prep window then a ForceCharge event window, leaving slots outside both free: got ' . json_encode($bootsForced),
);

saveOverride('2026-04-03', 'power_down', '14:00', '14:30', null, null);
$powerDownSlots = buildSlotsFrom([1.0, 1.0, 1.0], new DateTimeImmutable('2026-04-03 13:30:00', $overrideTz));
$powerDownForced = buildForcedActionsFromOverrides($powerDownSlots, $overrideTz);
check($powerDownForced === [null, 'SelfUse', null], 'power_down with no prep window forces only its event slot, to SelfUse: got ' . json_encode($powerDownForced));

// An override's free-form time doesn't have to land on a half-hour boundary — a slot the
// window only partially overlaps is still forced for its whole half hour (the DP's own
// granularity can't do better; the exact minute boundary is trimmed afterward by the
// existing post-hoc ScheduleBuilder::applyOverrides() pass — see buildForcedActionsFromOverrides()'s
// own doc comment).
saveOverride('2026-04-04', 'fill_your_boots', '10:15', '10:45', null, null);
$edgeSlots = buildSlotsFrom([1.0, 1.0], new DateTimeImmutable('2026-04-04 10:00:00', $overrideTz));
$edgeForced = buildForcedActionsFromOverrides($edgeSlots, $overrideTz);
check($edgeForced === ['ForceCharge', 'ForceCharge'], "a 10:15-10:45 event forces both the 10:00-10:30 and 10:30-11:00 slots it only partially overlaps: got " . json_encode($edgeForced));

// An override on one date must never leak into an adjacent date's same local time.
$noLeakSlots = buildSlotsFrom([1.0, 1.0], new DateTimeImmutable('2026-04-05 10:00:00', $overrideTz));
$noLeakForced = buildForcedActionsFromOverrides($noLeakSlots, $overrideTz);
check($noLeakForced === [null, null], "2026-04-02's fill_your_boots override doesn't leak into 2026-04-05's slots: got " . json_encode($noLeakForced));

check(overrideWindowInstants('2026-04-06', $overrideTz, '10:00', '10:00', 'ForceCharge') === null, 'an empty/invalid override window (end <= start) is ignored rather than corrupting the forced-actions array');

if ($failures > 0) {
    fwrite(STDERR, "\n$failures/$checks checks failed\n");
    exit(1);
}
echo "All $checks checks passed\n";
