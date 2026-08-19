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

// --- FoxessClient: pushSchedule() clears existing slots before pushing the real ones ---
// Confirmed live: stale slots from a previous push have blocked new ones even though the
// push call nominally replaces the whole schedule — see FoxessClient::pushSchedule()'s own
// doc comment. post() is protected (not private) specifically so this can subclass and
// intercept it, rather than the public pushSchedule() itself, to actually verify the
// two-call sequence without touching the network.
$recordingClient = new class('key', 'SN-REC', 'https://example.invalid') extends FoxessClient {
    public array $calls = []; // each entry: that call's 'groups' body value
    protected function post(string $path, array $body, bool $isRetry = false): array
    {
        $this->calls[] = $body['groups'];
        return ['errno' => 0];
    }
};
$pushedGroups = [['enable' => 1, 'startHour' => 10, 'startMinute' => 0, 'endHour' => 11, 'endMinute' => 0, 'workMode' => 'ForceCharge', 'minSocOnGrid' => 15, 'fdSoc' => 100, 'fdPwr' => 3000]];
$recordingClient->pushSchedule($pushedGroups);
check(count($recordingClient->calls) === 2, 'pushSchedule() makes exactly two calls: a clear, then the real push');
check($recordingClient->calls[0] === [], 'the first call clears the schedule with an empty groups array');
check($recordingClient->calls[1] === $pushedGroups, 'the second call sends the real computed groups');

$abortingClearClient = new class('key', 'SN-ABORT', 'https://example.invalid') extends FoxessClient {
    public array $calls = [];
    protected function post(string $path, array $body, bool $isRetry = false): array
    {
        $this->calls[] = $body['groups'];
        if ($body['groups'] === []) {
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

// --- Runner: isOfflineFailure() distinguishes a routine offline inverter from a real failure ---
check(isOfflineFailure('FoxESS /op/v1/device/scheduler/enable error 41935: Device offline, Please connect and retry'), 'a FoxESS "Device offline" error is recognised as routine (battery-less inverter after dark, see CLAUDE.md)');
check(!isOfflineFailure('FoxESS /op/v1/device/scheduler/enable error 41811: User permissions do not allow this operation'), 'a permissions error is not treated as a routine offline failure');
check(!isOfflineFailure('cURL error fetching Octopus rates: Could not resolve host'), 'an unrelated cURL error is not treated as a routine offline failure');

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
    getBatteryConfig() == ['capacity_kwh' => 10.0, 'max_charge_kw' => 3.0, 'max_discharge_kw' => 3.0, 'min_soc_on_grid' => 15, 'reserve_soc' => 15],
    'getBatteryConfig() falls back to hardcoded defaults with no setting and no legacy config'
);
check(
    getBatteryConfig(['max_discharge_kw' => 5.0, 'capacity_kwh' => 8.0]) == ['capacity_kwh' => 8.0, 'max_charge_kw' => 3.0, 'max_discharge_kw' => 5.0, 'min_soc_on_grid' => 15, 'reserve_soc' => 15],
    'getBatteryConfig() falls back to the legacy config.php array for keys not yet saved as settings'
);
setSetting('battery_max_discharge_kw', '6.5');
check(
    getBatteryConfig(['max_discharge_kw' => 5.0])['max_discharge_kw'] === 6.5,
    'getBatteryConfig() prefers a saved setting over the legacy config.php value'
);
check(getBatteryConfig(['max_discharge_kw' => 5.0])['capacity_kwh'] === 10.0, 'getBatteryConfig() still falls back per-key for anything not individually saved');

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

$push2 = $pushBuilder->buildPushWindow($scheduleByDate, new DateTimeImmutable('2026-01-05 21:00:00', $pushTz), $pushTz, null);
$tail = $push2['groups'][0];
check(
    $tail['startHour'] === 21 && $tail['endHour'] === 0,
    'the window starts at the current hour (21:00), trimming away the already-elapsed 20:00-21:00 portion of today\'s plan: got startHour=' . $tail['startHour'],
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

if ($failures > 0) {
    fwrite(STDERR, "\n$failures/$checks checks failed\n");
    exit(1);
}
echo "All $checks checks passed\n";
