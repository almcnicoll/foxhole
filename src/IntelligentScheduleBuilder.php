<?php

require_once __DIR__ . '/Exceptions.php';

// Toggled via the `intelligent_scheduler_enabled` setting (settings.php, on by default) —
// see Runner.php's runScheduler(). run.php can override it per-run with --classic/--intelligent.
// See roadmap.MD's "Solar-generation-aware scheduling" / "Load-aware scheduling" /
// "Live battery SoC awareness" items: this combines all three into one scheduler,
// instead of ScheduleBuilder's plain price-threshold-with-flat-caps heuristic.
//
// Still a greedy, explainable simulation, not an LP solver — same design philosophy
// as ScheduleBuilder (see CLAUDE.md): it walks the day once forward, tracking a
// projected battery SoC in kWh (starting from the inverter's actual current SoC when
// known), so charge/discharge decisions are bounded by real projected energy, not a
// blind slot-count cap.
//
// Model:
//   - Solar forecast (hourly Wh buckets) is prorated onto each half-hour price slot by
//     time overlap.
//   - Household load is a flat or hour-of-day profile from $usageConfig — there's no
//     real usage history in this app yet, so this is a rough estimate, not measured.
//   - "How much do we actually need to buy from the grid?" is computed by simulating
//     the battery through solar-minus-load alone (no forced charging) up to today's
//     import price peak, and taking the shortfall between that projection and full.
//     Only that much is force-charged, cheapest slots first (plus a separate,
//     unbounded-by-need arbitrage rule — buying below the best export rate is always
//     worth it regardless of how full the battery already is).
//   - Discharge is deliberately NOT driven by import price alone — SelfUse already
//     draws from the battery to cover load before importing, proportional to actual
//     need, so forcing a discharge purely because import is expensive has no upside
//     and just empties the battery faster than necessary (see CLAUDE.md — this was a
//     real reported bug: the battery ran dry mid-peak and the user ended up buying the
//     same expensive electricity anyway). Discharge only happens for two reasons, both
//     with a concrete financial payoff:
//       1. Selling at a genuinely high/variable export rate, ranked by export price —
//          only when the export rate actually varies today (a flat rate has no "best
//          time to sell").
//       2. Clearing space ahead of a genuine arbitrage opportunity: a future slot whose
//          import price undercuts the best export rate is worth buying regardless of
//          the battery's current state, but only if there's room to store it. Sized to
//          the real shortfall (via max_charge_kw vs max_discharge_kw), not a blind
//          full drain — see findArbitrageWindows()/build().
//   - Either way, a candidate is only kept if a forward simulation (including the
//     charge decisions above) shows the battery would still be at/above the reserve
//     floor afterwards — for sell candidates, the least price-desirable one is dropped
//     and the simulation re-run until that holds, or nothing's left to drop; clear-space
//     reservations are sized against this same simulation up front, not evicted.
class IntelligentScheduleBuilder
{
    public function __construct(
        private readonly array $strategyConfig,
        private readonly array $batteryConfig,
        private readonly array $usageConfig = [],
    ) {
    }

    /**
     * @param array $importSlots N chronological half-hour slots, ['from','to','rate']
     * @param ?array $exportSlots same shape/length as $importSlots, or null
     * @param float[] $costBasis N values aligned to $importSlots, pence/kWh
     * @param ?array $solarSlots SolarForecastClient-shaped periods (['from','to','watt_hours']),
     *        any granularity — prorated onto $importSlots by time overlap. Null if unavailable.
     * @param ?float $currentSocPercent actual battery SoC right now (FoxessClient::getBatterySoc()),
     *        0-100, or null if unknown (falls back to assuming it's at the reserve floor).
     * @return array{groups: array, explanations: string[], summary: string, finalSocPercent: float}
     *         finalSocPercent is this plan's projected SoC at the end of the given day —
     *         GitHub issue #4's multi-day scheduling passes it back in as the next known
     *         day's $currentSocPercent, so a projected trajectory carries across the day
     *         boundary instead of every day independently assuming it starts at the real
     *         live reading (which is only true for the first day in a run).
     */
    public function build(array $importSlots, ?array $exportSlots, array $costBasis, ?array $solarSlots, ?float $currentSocPercent): array
    {
        $n = count($importSlots);
        if ($n !== count($costBasis)) {
            throw new ScheduleBuildException('Slot count and cost basis count must match');
        }
        if ($exportSlots !== null && count($exportSlots) !== $n) {
            throw new ScheduleBuildException('Slot count and export slot count must match');
        }
        if ($n === 0) {
            throw new ScheduleBuildException('No slots to build a schedule from');
        }

        $timezone = new DateTimeZone($this->strategyConfig['timezone'] ?? 'Europe/London');
        $importRates = array_column($importSlots, 'rate');
        $exportRates = $exportSlots !== null ? array_column($exportSlots, 'rate') : null;
        $peakImportIndex = self::indexOfMax($importRates);
        $bestExportRate = $exportRates !== null ? max($exportRates) : null;
        $exportIsVariable = $exportRates !== null && max($exportRates) - min($exportRates) > 0.0;

        $capacityKwh = (float) ($this->batteryConfig['capacity_kwh'] ?? 0);
        $chargeKw = (float) ($this->batteryConfig['max_charge_kw'] ?? 0);
        $dischargeKw = (float) ($this->batteryConfig['max_discharge_kw'] ?? 0);
        $minSocOnGrid = (int) ($this->batteryConfig['min_soc_on_grid'] ?? 0);
        $reserveSoc = (int) ($this->batteryConfig['reserve_soc'] ?? 0);
        // Two different floors, per CLAUDE.md's own distinction (they're both 15-21%
        // in practice, not 0 — easy to conflate since they're usually equal): reserveKwh
        // (reserve_soc) is specifically how far a *forced* discharge is allowed to drain
        // the battery, so it only bounds the discharge-feasibility check below. Everything
        // else — the natural/unforced solar-minus-load trajectory that sizes how much
        // top-up is needed, and the non-charge/non-discharge slots in the planned
        // trajectory — floors at minSocOnGridKwh (min_soc_on_grid), the inverter's general
        // system floor, since nothing is force-draining the battery in those slots.
        $reserveKwh = $capacityKwh * $reserveSoc / 100;
        $minSocOnGridKwh = $capacityKwh * $minSocOnGrid / 100;
        $chargeEnergyKwh = $chargeKw * 0.5;
        $dischargeEnergyKwh = $dischargeKw * 0.5;
        $startingSocPercent = $currentSocPercent ?? (float) $reserveSoc;
        $startingSocKwh = $capacityKwh * $startingSocPercent / 100;

        $solarKwh = $solarSlots !== null ? $this->alignSolarToSlots($importSlots, $solarSlots) : array_fill(0, $n, 0.0);
        $loadKwh = $this->loadProfile($importSlots, $timezone);
        $netKwh = [];
        for ($i = 0; $i < $n; $i++) {
            $netKwh[$i] = $solarKwh[$i] - $loadKwh[$i];
        }

        // Natural trajectory (solar/load only, no forced grid action) up to today's peak,
        // to size how much top-up the grid actually needs to provide.
        $soc = $startingSocKwh;
        $projectedMaxBeforePeak = $soc;
        for ($i = 0; $i < $peakImportIndex; $i++) {
            $soc = max($minSocOnGridKwh, min($capacityKwh, $soc + $netKwh[$i]));
            $projectedMaxBeforePeak = max($projectedMaxBeforePeak, $soc);
        }
        $neededTopUpKwh = max(0.0, $capacityKwh - $projectedMaxBeforePeak);

        $chargeCapConfig = max(0, (int) ($this->strategyConfig['cheap_slots_to_charge'] ?? 0));
        $topUpSlotsNeeded = $chargeEnergyKwh > 0 ? (int) ceil($neededTopUpKwh / $chargeEnergyKwh) : 0;
        $topUpCap = min($chargeCapConfig, $topUpSlotsNeeded);

        // Cost-basis-driven candidates are bounded by real projected need ($topUpCap);
        // arbitrage candidates aren't — buying below the best export rate is worth it
        // regardless of how full the battery already is — but both still share the
        // same overall config cap.
        $costCandidates = [];
        $arbitrageCandidates = [];
        for ($i = 0; $i < $n; $i++) {
            if ($importRates[$i] < $costBasis[$i]) {
                $costCandidates[] = $i;
            }
            if ($bestExportRate !== null && $importRates[$i] < $bestExportRate) {
                $arbitrageCandidates[] = $i;
            }
        }
        $chargeIndexes = array_slice($this->rankPreferringPrePeak($costCandidates, $importRates, $peakImportIndex), 0, $topUpCap);
        $remainingCap = $chargeCapConfig - count($chargeIndexes);
        foreach ($this->rankPreferringPrePeak($arbitrageCandidates, $importRates, $peakImportIndex) as $i) {
            if ($remainingCap <= 0) {
                break;
            }
            if (in_array($i, $chargeIndexes, true)) {
                continue;
            }
            $chargeIndexes[] = $i;
            $remainingCap--;
        }
        sort($chargeIndexes);
        $chargeSet = array_flip($chargeIndexes);

        $dischargeCapConfig = max(0, (int) ($this->strategyConfig['expensive_slots_to_export'] ?? 0));

        // Reusable trajectory walk: given the fixed charge decisions above plus whatever
        // clear-space reservations have been committed so far, project SoC just before
        // slot $target. Used both to size each reservation and, once discharge is fully
        // decided, doesn't need re-running — the feasibility trim below has its own copy
        // because it also has to consider sell candidates, which this doesn't.
        $preChargeIndexes = []; // reserved index => the charge index it's clearing space for
        $simulateSocBefore = function (int $target) use ($startingSocKwh, $chargeSet, &$preChargeIndexes, $netKwh, $chargeEnergyKwh, $dischargeEnergyKwh, $capacityKwh, $minSocOnGridKwh, $reserveKwh): float {
            $soc = $startingSocKwh;
            for ($i = 0; $i < $target; $i++) {
                if (isset($chargeSet[$i])) {
                    $soc = min($capacityKwh, $soc + $chargeEnergyKwh);
                } elseif (isset($preChargeIndexes[$i])) {
                    $soc = max($reserveKwh, $soc - $dischargeEnergyKwh); // forced discharge — reserve is its floor, same as the feasibility trim below
                } else {
                    $soc = max($minSocOnGridKwh, min($capacityKwh, $soc + $netKwh[$i]));
                }
            }
            return $soc;
        };

        // Clear space ahead of each arbitrage charging window (import below the best
        // export rate — a guaranteed-value buy regardless of current battery state, but
        // only if there's room to store it), cheapest window first. Sized to the real
        // shortfall via each rate's own energy conversion, not a blind full drain — see
        // the class doc comment. Shares the same budget sell candidates use below.
        $arbitrageChargeIndexes = array_values(array_intersect($chargeIndexes, $arbitrageCandidates));
        sort($arbitrageChargeIndexes);
        $budget = $dischargeCapConfig;
        foreach ($this->findArbitrageWindows($arbitrageChargeIndexes, $importRates) as $window) {
            if ($budget <= 0) {
                break;
            }
            $socBeforeWindow = $simulateSocBefore($window['start']);
            $demandKwh = $window['slotCount'] * $chargeEnergyKwh;
            $shortfallKwh = max(0.0, $socBeforeWindow + $demandKwh - $capacityKwh);
            if ($shortfallKwh <= 0.0) {
                continue; // already enough room, nothing to clear
            }
            // Never plan to discharge below reserve just to make room — cap the slots
            // requested by how much headroom actually exists above it, same floor the
            // feasibility trim enforces for sell candidates.
            $availableAboveReserve = max(0.0, $socBeforeWindow - $reserveKwh);
            $slotsNeeded = $dischargeEnergyKwh > 0 ? (int) min(ceil($shortfallKwh / $dischargeEnergyKwh), floor($availableAboveReserve / $dischargeEnergyKwh)) : 0;

            $idx = $window['start'] - 1;
            $reserved = 0;
            while ($reserved < $slotsNeeded && $budget > 0 && $idx >= 0 && !isset($chargeSet[$idx]) && !isset($preChargeIndexes[$idx])) {
                $preChargeIndexes[$idx] = $window['start'];
                $reserved++;
                $budget--;
                $idx--;
            }
        }

        // Remaining budget, if any, goes to selling at the export peak — only when
        // export price actually varies (a flat rate has no "best time to sell").
        $sellCandidates = [];
        if ($exportIsVariable) {
            for ($i = 0; $i < $n; $i++) {
                if (!isset($chargeSet[$i]) && !isset($preChargeIndexes[$i])) {
                    $sellCandidates[$i] = $exportRates[$i];
                }
            }
        }
        arsort($sellCandidates); // most desirable to sell first, keys preserved
        $remainingBudget = max(0, $dischargeCapConfig - count($preChargeIndexes));
        $selected = array_slice(array_keys($sellCandidates), 0, $remainingBudget);

        // Feasibility trim: walk the day once with charges + clear-space reservations
        // (fixed, already sized against $simulateSocBefore above) + tentative sell
        // candidates applied in time order; if the battery would go below reserve, drop
        // the least price-desirable *sell* candidate and re-check — reservations aren't
        // evicted, since they were sized specifically to stay feasible. Bounded by
        // count($selected). Written as a closure returning both the outcome and the
        // full-day-end SoC (not just a bare while ($selected) loop) specifically so a day
        // with zero sell candidates still walks the whole day once — needed for
        // $finalSocPercent below, which needs the real end-of-day figure even when
        // nothing was ever a violation risk in the first place.
        $simulateFullDay = function () use ($startingSocKwh, $chargeSet, $preChargeIndexes, &$selected, $netKwh, $chargeEnergyKwh, $dischargeEnergyKwh, $capacityKwh, $minSocOnGridKwh, $reserveKwh, $n): array {
            $soc = $startingSocKwh;
            $selectedSet = array_flip($selected);
            for ($i = 0; $i < $n; $i++) {
                if (isset($chargeSet[$i])) {
                    $soc = min($capacityKwh, $soc + $chargeEnergyKwh);
                } elseif (isset($preChargeIndexes[$i])) {
                    $soc = max($reserveKwh, $soc - $dischargeEnergyKwh);
                } elseif (isset($selectedSet[$i])) {
                    if ($soc - $dischargeEnergyKwh < $reserveKwh) {
                        return ['soc' => $soc, 'violated' => true];
                    }
                    $soc -= $dischargeEnergyKwh;
                } else {
                    $soc = max($minSocOnGridKwh, min($capacityKwh, $soc + $netKwh[$i]));
                }
            }
            return ['soc' => $soc, 'violated' => false];
        };
        while (true) {
            $dayResult = $simulateFullDay();
            if (!$dayResult['violated']) {
                break;
            }
            array_pop($selected); // arsort order preserved by the slice above — last = worst-ranked
        }
        $dischargeIndexes = [...array_keys($preChargeIndexes), ...$selected];
        sort($dischargeIndexes);
        $finalSocPercent = $capacityKwh > 0 ? $dayResult['soc'] / $capacityKwh * 100 : 0.0;

        $modes = array_fill(0, $n, 'SelfUse');
        foreach ($chargeIndexes as $i) {
            $modes[$i] = 'ForceCharge';
        }
        foreach ($dischargeIndexes as $i) {
            $modes[$i] = 'ForceDischarge';
        }

        $periods = $this->mergeContiguous($importSlots, $modes, $timezone);

        return [
            'groups' => $this->periodsToGroups($periods, $chargeKw, $dischargeKw, $minSocOnGrid, $reserveSoc),
            'explanations' => $this->explainPeriods($periods, $importRates, $exportRates, $neededTopUpKwh, $preChargeIndexes, $importSlots, $timezone, $bestExportRate),
            'finalSocPercent' => $finalSocPercent,
            'summary' => sprintf(
                'Solar forecast: %.1fkWh today, assumed usage %.1fkWh — projected %.1fkWh needed from the grid to reach full before the %s peak (%sp/kWh). Battery starting at %.0f%% (%.1fkWh).',
                array_sum($solarKwh),
                array_sum($loadKwh),
                $neededTopUpKwh,
                $importSlots[$peakImportIndex]['from']->setTimezone($timezone)->format('H:i'),
                number_format($importRates[$peakImportIndex], 2),
                $startingSocPercent,
                $startingSocKwh,
            ),
        ];
    }

    /** @return float[] kWh per import slot, prorating each solar period by time overlap */
    private function alignSolarToSlots(array $importSlots, array $solarSlots): array
    {
        $result = array_fill(0, count($importSlots), 0.0);
        foreach ($importSlots as $i => $slot) {
            $slotStart = $slot['from']->getTimestamp();
            $slotEnd = $slot['to']->getTimestamp();
            foreach ($solarSlots as $s) {
                $sStart = $s['from']->getTimestamp();
                $sEnd = $s['to']->getTimestamp();
                if ($sEnd <= $sStart) {
                    continue; // zero-width sunrise/sunset marker
                }
                $overlap = min($slotEnd, $sEnd) - max($slotStart, $sStart);
                if ($overlap > 0) {
                    $result[$i] += $s['watt_hours'] * ($overlap / ($sEnd - $sStart)) / 1000.0;
                }
            }
        }
        return $result;
    }

    /** @return float[] kWh per import slot, from $usageConfig's hourly_kwh profile or a flat avg_daily_kwh fallback */
    private function loadProfile(array $importSlots, DateTimeZone $timezone): array
    {
        $hourly = $this->usageConfig['hourly_kwh'] ?? null;
        $flatPerSlot = ((float) ($this->usageConfig['avg_daily_kwh'] ?? 0.0)) / 24 / 2;

        $result = [];
        foreach ($importSlots as $slot) {
            $hour = (int) $slot['from']->setTimezone($timezone)->format('G');
            $result[] = is_array($hourly) && isset($hourly[$hour]) ? $hourly[$hour] / 2 : $flatPerSlot;
        }
        return $result;
    }

    /** Cheapest-first, but pre-peak candidates exhausted before post-peak ones (same tie-break as ScheduleBuilder). */
    private function rankPreferringPrePeak(array $indexes, array $importRates, int $peakImportIndex): array
    {
        $pre = array_values(array_filter($indexes, fn($i) => $i < $peakImportIndex));
        $post = array_values(array_filter($indexes, fn($i) => $i >= $peakImportIndex));
        usort($pre, fn($a, $b) => $importRates[$a] <=> $importRates[$b]);
        usort($post, fn($a, $b) => $importRates[$a] <=> $importRates[$b]);
        return [...$pre, ...$post];
    }

    /**
     * Maximal contiguous runs of $arbitrageChargeIndexes (already-selected arbitrage
     * charge slots — import below the best export rate), ranked cheapest-average-import-
     * rate first so the shared clear-space budget goes to the best opportunities first.
     * Mirrors ScheduleBuilder's findChargingWindows(), scoped to arbitrage-selected slots.
     *
     * @return array<int, array{start: int, slotCount: int}>
     */
    private function findArbitrageWindows(array $arbitrageChargeIndexes, array $importRates): array
    {
        $windows = [];
        $windowStart = null;
        $prev = null;
        foreach ($arbitrageChargeIndexes as $i) {
            if ($prev !== null && $i !== $prev + 1) {
                $windows[] = [$windowStart, $prev];
                $windowStart = null;
            }
            $windowStart ??= $i;
            $prev = $i;
        }
        if ($windowStart !== null) {
            $windows[] = [$windowStart, $prev];
        }

        $result = [];
        foreach ($windows as [$start, $end]) {
            $result[] = ['start' => $start, 'slotCount' => $end - $start + 1, 'avgRate' => $this->average($importRates, range($start, $end))];
        }
        usort($result, fn($a, $b) => $a['avgRate'] <=> $b['avgRate']);
        return $result;
    }

    private static function indexOfMax(array $values): int
    {
        $maxIndex = 0;
        foreach ($values as $i => $v) {
            if ($v > $values[$maxIndex]) {
                $maxIndex = $i;
            }
        }
        return $maxIndex;
    }

    private function mergeContiguous(array $slots, array $modes, DateTimeZone $timezone): array
    {
        $periods = [];
        $n = count($slots);
        $i = 0;
        while ($i < $n) {
            $mode = $modes[$i];
            $start = $slots[$i]['from']->setTimezone($timezone);
            $j = $i;
            while ($j + 1 < $n && $modes[$j + 1] === $mode) {
                $j++;
            }
            $end = $slots[$j]['to']->setTimezone($timezone);
            $periods[] = ['mode' => $mode, 'startIndex' => $i, 'endIndex' => $j, 'start' => $start, 'end' => $end];
            $i = $j + 1;
        }
        return $periods;
    }

    private function periodsToGroups(array $periods, float $chargeKw, float $dischargeKw, int $minSocOnGrid, int $reserveSoc): array
    {
        $groups = [];
        foreach ($periods as $period) {
            if ($period['mode'] === 'SelfUse') {
                continue;
            }
            $isCharge = $period['mode'] === 'ForceCharge';
            $groups[] = [
                'enable' => 1,
                'startHour' => (int) $period['start']->format('G'),
                'startMinute' => (int) $period['start']->format('i'),
                'endHour' => (int) $period['end']->format('G'),
                'endMinute' => (int) $period['end']->format('i'),
                'workMode' => $period['mode'],
                'minSocOnGrid' => $minSocOnGrid,
                'fdSoc' => $isCharge ? 100 : $reserveSoc,
                'fdPwr' => (int) round(($isCharge ? $chargeKw : $dischargeKw) * 1000),
            ];
        }
        return $groups;
    }

    /**
     * @param array $preChargeIndexes reserved index => the charge index it's clearing space for
     *        (see build()) — a ForceDischarge period explains as a clear-space reservation if
     *        any slot within it is one, otherwise as a sell-at-the-export-peak period (the only
     *        other source of discharge — see the class doc comment).
     * @return string[] one sentence per non-SelfUse period, same order periodsToGroups() emits groups in
     */
    private function explainPeriods(
        array $periods,
        array $importRates,
        ?array $exportRates,
        float $neededTopUpKwh,
        array $preChargeIndexes,
        array $importSlots,
        DateTimeZone $timezone,
        ?float $bestExportRate,
    ): array {
        $explanations = [];
        foreach ($periods as $period) {
            if ($period['mode'] === 'SelfUse') {
                continue;
            }
            $range = $period['start']->format('H:i') . '–' . $period['end']->format('H:i');
            $indexes = range($period['startIndex'], $period['endIndex']);
            if ($period['mode'] === 'ForceCharge') {
                $avg = $this->average($importRates, $indexes);
                $explanations[] = sprintf(
                    'Charging %s (avg %sp/kWh) — topping up the %.1fkWh solar and usage forecasts project as a shortfall before today\'s peak.',
                    $range,
                    number_format($avg, 2),
                    $neededTopUpKwh,
                );
                continue;
            }

            // A period is usually a single reserved slot, but don't assume it — use
            // whichever slot in the (possibly merged) period has a reservation.
            $targetIndex = null;
            foreach ($indexes as $i) {
                if (isset($preChargeIndexes[$i])) {
                    $targetIndex = $preChargeIndexes[$i];
                    break;
                }
            }
            if ($targetIndex !== null) {
                $explanations[] = sprintf(
                    'Discharging %s — clearing space so the %sp import at %s (cheaper than today\'s %sp export) can be bought and stored.',
                    $range,
                    number_format($importRates[$targetIndex], 2),
                    $importSlots[$targetIndex]['from']->setTimezone($timezone)->format('H:i'),
                    number_format($bestExportRate, 2),
                );
            } else {
                $avg = $this->average($exportRates, $indexes);
                $explanations[] = sprintf('Selling %s (avg %sp/kWh) — projected spare battery capacity that won\'t dip below reserve.', $range, number_format($avg, 2));
            }
        }
        return $explanations;
    }

    private function average(array $values, array $indexes): float
    {
        $sum = 0.0;
        foreach ($indexes as $i) {
            $sum += $values[$i];
        }
        return $sum / count($indexes);
    }
}
