<?php

// Minimal self-check for the money-affecting logic (ScheduleBuilder,
// CostBasisProvider). Not a framework — run directly: php tests/self_check.php
declare(strict_types=1);

require_once __DIR__ . '/../src/Exceptions.php';
require_once __DIR__ . '/../src/CostBasisProvider.php';
require_once __DIR__ . '/../src/ScheduleBuilder.php';
require_once __DIR__ . '/../src/Logger.php';
require_once __DIR__ . '/../src/Store.php';
require_once __DIR__ . '/../src/OctopusClient.php';
require_once __DIR__ . '/../src/PriceProvider.php';
require_once __DIR__ . '/../src/FoxessClient.php';
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
    $slots = [];
    $start = new DateTimeImmutable('2026-01-05 00:00:00', new DateTimeZone('UTC'));
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
check(str_contains($schedule5['explanations'][0], 'below both your'), 'a slot cheap enough for both reasons is explained as such');

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

$allOkResult = pushToDevices(['SN-OK' => $okDevice], [], $pushLogger);
check($allOkResult['failures'] === [], 'no failures reported when every device succeeds');

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

check(verifySystemPassword('foxhole') === true, 'default password "foxhole" works before any password is set');
check(verifySystemPassword('wrong') === false, 'wrong password rejected under the default');
setSystemPassword('a-real-password');
check(verifySystemPassword('foxhole') === false, 'old default stops working once a real password is set');
check(verifySystemPassword('a-real-password') === true, 'new password verifies correctly');
check(verifySystemPassword('a-real-password ') === false, 'password check is exact, not trimmed/fuzzy');

$fetchedAt = new DateTimeImmutable('2026-01-04 16:00:00', new DateTimeZone('UTC'));
saveRateSlots(buildSlots(array_fill(0, 4, 20.0)), buildSlots(array_fill(0, 4, 12.0)), $fetchedAt);
$storedSlots = getLatestRateSlots();
check(count($storedSlots) === 4, 'saved rate slots round-trip at the right count');
check($storedSlots[0]['import_rate'] === 20.0, 'import rate value round-trips');
check($storedSlots[0]['export_rate'] === 12.0, 'export rate value round-trips');
check($storedSlots[0]['fetched_at']->format(DATE_ATOM) === $fetchedAt->format(DATE_ATOM), 'fetched_at round-trips');
saveRateSlots(buildSlots(array_fill(0, 2, 15.0)), null, $fetchedAt);
$noExportSlots = getLatestRateSlots();
check(count($noExportSlots) === 2, 'saving new rate slots replaces the old batch, not appends');
check($noExportSlots[0]['export_rate'] === null, 'a null export batch stores null export prices rather than stale ones');

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
$storedSchedule = getLatestSchedule();
check($storedSchedule['groups'] == $groups, 'saved schedule groups round-trip identically (used for run.php\'s no-op diff)');
check($storedSchedule['for_date'] === '2026-01-05', 'for_date round-trips');
check($storedSchedule['explanations'] === $schedule['explanations'], 'saved explanations round-trip in the same order as their groups');

@unlink($testDbPath);

if ($failures > 0) {
    fwrite(STDERR, "\n$failures/$checks checks failed\n");
    exit(1);
}
echo "All $checks checks passed\n";
