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

/**
 * Booked Weekend Happy Hour slots (OctopusFlexClient::getWeekendHappyHours()) → one
 * override window per local date. Octopus runs these as consecutive one-hour slots (e.g.
 * four in a row, 11:00-15:00), booked individually, so adjacent/overlapping booked slots
 * are merged into a single window — a ForceCharge for each hour separately would just be
 * the same group four times over.
 *
 * Only booked slots count (an unbooked free hour earns nothing, so charging for it would
 * pay full price). The `overrides` table holds one fill_your_boots row per date, so if a
 * date somehow has *disjoint* booked windows only the earliest is kept — widening it to
 * span the gap would force-charge unbooked, paid-for hours, which is worse than missing a
 * window. A window that would run past local midnight is clipped to 23:59 (override times
 * are same-day H:i strings; see calculateOptInPrep()'s own midnight note).
 *
 * @param array<int, array{start: DateTimeImmutable, end: DateTimeImmutable, booked: bool}> $slots
 * @return array<string, array{start: DateTimeImmutable, end: DateTimeImmutable}> local Y-m-d => window
 */
function bookedHappyHourWindows(array $slots, DateTimeZone $timezone): array
{
    $booked = array_values(array_filter($slots, fn($s) => $s['booked']));
    usort($booked, fn($a, $b) => $a['start'] <=> $b['start']);

    $merged = [];
    foreach ($booked as $slot) {
        $last = count($merged) - 1;
        if ($last >= 0 && $slot['start'] <= $merged[$last]['end']) {
            $merged[$last]['end'] = max($merged[$last]['end'], $slot['end']);
        } else {
            $merged[] = ['start' => $slot['start'], 'end' => $slot['end']];
        }
    }

    $byDate = [];
    foreach ($merged as $window) {
        $date = $window['start']->setTimezone($timezone)->format('Y-m-d');
        if (isset($byDate[$date])) {
            continue; // disjoint second window on the same date — see above
        }
        $lastMinute = (new DateTimeImmutable($date, $timezone))->modify('+1 day')->modify('-1 minute');
        $byDate[$date] = ['start' => $window['start'], 'end' => min($window['end'], $lastMinute)];
    }
    return $byDate;
}

const HAPPY_HOUR_MANAGED_DATES_SETTING = 'happy_hour_override_dates';

/**
 * Turns booked Weekend Happy Hours into `fill_your_boots` overrides — the same row
 * override.php writes by hand and opt-in.php writes for a one-click opt-in, so every
 * scheduler's existing override handling (applyOverrides(), the modelling scheduler's
 * forced actions) does the actual charging with no new scheduling code: ForceDischarge
 * ahead of the slot to make room, ForceCharge for its duration (ScheduleBuilder::
 * overrideModesFor()). Called from Runner.php's runScheduler() on every real run.
 *
 * Which override rows are *ours* is tracked in the `happy_hour_override_dates` setting (a
 * JSON list of local dates), so the sync can tell "I wrote this and the booking has since
 * gone" (delete it) from "the user entered this by hand on override.php" (never touch it —
 * a manual override wins over an automatic one for the same date).
 *
 * Reconciles rather than only adding: a date we manage whose booking no longer shows up
 * has its override removed. That only ever happens on a *successful* fetch — callers must
 * not call this after a failed one, or a transient Octopus outage would tear down a live
 * booking's override.
 *
 * @param array $slots getWeekendHappyHours()-shaped, from a successful fetch
 * @param callable(): ?float $socProvider current battery SoC %, or null if unreadable —
 *        only invoked when a window actually needs prep, so a day with nothing booked
 *        costs no FoxESS call. Unreadable SoC degrades to *no prep* (the free-hour charge
 *        itself is still scheduled, which is the part that matters) rather than skipping
 *        the booking; an override that already exists for the date is then left as-is
 *        instead of being rewritten without the prep it already had.
 * @return string[] human-readable notes for the run log
 */
function syncBookedHappyHourOverrides(array $slots, callable $socProvider, array $batteryConfig, DateTimeImmutable $now, DateTimeZone $timezone): array
{
    $today = $now->setTimezone($timezone)->format('Y-m-d');
    $managed = json_decode(getSetting(HAPPY_HOUR_MANAGED_DATES_SETTING, '[]') ?? '[]', true);
    $managed = is_array($managed) ? array_values(array_filter($managed, fn($d) => is_string($d) && $d >= $today)) : [];
    $windows = bookedHappyHourWindows($slots, $timezone);
    $notes = [];

    foreach ($managed as $date) {
        if (!isset($windows[$date])) {
            deleteOverride($date, 'fill_your_boots');
            $notes[] = "Happy hour booking for $date is gone — removed its fill-your-boots override.";
        }
    }
    $managed = array_values(array_filter($managed, fn($d) => isset($windows[$d])));

    $soc = null;
    $socRead = false;
    foreach ($windows as $date => $window) {
        $existing = null;
        foreach (getOverridesForDate($date) as $row) {
            if ($row['kind'] === 'fill_your_boots') {
                $existing = $row;
            }
        }
        if ($existing !== null && !in_array($date, $managed, true)) {
            $notes[] = "Booked happy hour on $date, but a manual fill-your-boots override already exists for it — left alone.";
            continue;
        }
        if ($window['end'] <= $now) {
            continue; // already over — nothing to schedule; any row of ours is kept so the day's recorded plan stays accurate
        }

        if (!$socRead) {
            $soc = $socProvider();
            $socRead = true;
        }
        if ($soc === null && $existing !== null) {
            $notes[] = "Battery SoC unreadable — kept the existing happy hour override for $date unchanged.";
            $managed[] = $date;
            continue;
        }
        $prep = $soc === null ? null : calculateOptInPrep('fill_your_boots', $window['start'], $soc, $batteryConfig, $now, $timezone);

        saveOverride(
            $date,
            'fill_your_boots',
            $window['start']->setTimezone($timezone)->format('H:i'),
            $window['end']->setTimezone($timezone)->format('H:i'),
            $prep !== null ? $prep['start']->setTimezone($timezone)->format('H:i') : null,
            $prep !== null ? $prep['end']->setTimezone($timezone)->format('H:i') : null,
        );
        $managed[] = $date;
        $notes[] = sprintf(
            'Booked happy hour %s %s-%s: fill-your-boots override set%s.',
            $date,
            $window['start']->setTimezone($timezone)->format('H:i'),
            $window['end']->setTimezone($timezone)->format('H:i'),
            $prep !== null
                ? sprintf(' (discharging %s-%s first)', $prep['start']->setTimezone($timezone)->format('H:i'), $prep['end']->setTimezone($timezone)->format('H:i'))
                : ($soc === null ? ' (no prep — battery SoC unreadable)' : ' (battery already low enough, no prep needed)'),
        );
    }

    setSetting(HAPPY_HOUR_MANAGED_DATES_SETTING, json_encode(array_values(array_unique($managed))));
    return $notes;
}
