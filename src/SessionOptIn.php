<?php

/**
 * Pure prep-window calculation for opt-in.php's single-click flow (see CLAUDE.md's
 * "Octopus opt-in sessions" section). Deliberately a standalone pure function, not a
 * method on ScheduleBuilder — same "small, testable, no DB/network" precedent as
 * Schedulers.php's modellingWindowEnd().
 *
 * The battery physics/target-SoC choices here are the direct consequence of
 * ScheduleBuilder::overrideModesFor()'s existing mode mapping, not a new decision: a
 * power_down event runs on SelfUse (so it must start from a full battery to avoid any
 * grid import during the event), and a fill_your_boots event force-charges (so it needs
 * an empty battery — down to the reserve_soc floor, the same floor overrideModesFor()'s
 * own ForceDischarge prep already targets — to have somewhere to put the free/cheap
 * energy).
 *
 * @param array{capacity_kwh: float, max_charge_kw: float, max_discharge_kw: float, min_soc_on_grid: int, reserve_soc: int} $batteryConfig Store::getBatteryConfig()'s shape
 * @return ?array{start: DateTimeImmutable, end: DateTimeImmutable, clamped: bool} null if no prep is needed (already at/past target) or no usable time remains before the event; $end always equals $eventStart. $clamped is true when the naturally-required prep duration would have started before local midnight of the event's own date — the override system has no way to express a window spanning midnight (same limitation override.php's own UI already has), so prep is shortened to start at that midnight instead of being rejected outright.
 */
function calculateOptInPrep(
    string $kind,
    DateTimeImmutable $eventStart,
    float $currentSocPercent,
    array $batteryConfig,
    DateTimeImmutable $now,
    DateTimeZone $timezone,
): ?array {
    $capacityKwh = $batteryConfig['capacity_kwh'];
    if ($kind === 'power_down') {
        $energyKwh = max(0.0, (100.0 - $currentSocPercent) / 100 * $capacityKwh);
        $powerKw = $batteryConfig['max_charge_kw'];
    } else {
        $energyKwh = max(0.0, ($currentSocPercent - $batteryConfig['reserve_soc']) / 100 * $capacityKwh);
        $powerKw = $batteryConfig['max_discharge_kw'];
    }

    if ($energyKwh <= 0.0 || $powerKw <= 0.0) {
        return null; // already at/past target, or prep power is unconfigured — nothing to do
    }

    // Round up to the nearest half-hour — this app's native slot granularity everywhere
    // else (cheap_slots_to_charge, the DP's soc_bin_kwh horizon, etc.) — rather than an
    // exact-to-the-minute duration that would be a false precision given the underlying
    // charge/discharge power figures are themselves just settings.php estimates.
    $durationMinutes = (int) (ceil(($energyKwh / $powerKw) * 60 / 30) * 30);

    $naturalStart = $eventStart->modify("-{$durationMinutes} minutes");
    $localMidnight = new DateTimeImmutable($eventStart->setTimezone($timezone)->format('Y-m-d'), $timezone);

    $start = max($naturalStart, $now);
    $clamped = false;
    if ($start < $localMidnight) {
        $start = $localMidnight;
        $clamped = true;
    }
    if ($start >= $eventStart) {
        return null; // no usable prep time remains before the event
    }

    return ['start' => $start, 'end' => $eventStart, 'clamped' => $clamped];
}
