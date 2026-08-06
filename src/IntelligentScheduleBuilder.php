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
//   - Discharge candidates are ranked by price exactly like ScheduleBuilder, but a
//     candidate is only kept if a forward simulation (including the charge decisions
//     above) shows the battery would still be at/above the reserve floor afterwards —
//     the least price-desirable candidate is dropped and the simulation re-run until
//     that holds, or nothing's left to drop.
//
// Dropped relative to ScheduleBuilder, to keep a first version tractable: the "reserve
// a discharge slot ahead of each cheap charging window" heuristic. With a real energy
// simulation, forcing a charge into an already-full battery just curtails/exports the
// extra instead of storing it — mildly wasteful on the rare day that happens, not a
// broken schedule. Worth revisiting if that turns out to matter in practice.
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
     * @return array{groups: array, explanations: string[], summary: string}
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
        $reserveKwh = $capacityKwh * $reserveSoc / 100;
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
            $soc = max($reserveKwh, min($capacityKwh, $soc + $netKwh[$i]));
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

        // Projected SoC at the *start* of each slot, charge decisions included — this is
        // what discharge feasibility gets checked against below.
        $plannedSocBefore = [];
        $soc = $startingSocKwh;
        for ($i = 0; $i < $n; $i++) {
            $plannedSocBefore[$i] = $soc;
            $soc = isset($chargeSet[$i])
                ? min($capacityKwh, $soc + $chargeEnergyKwh)
                : max($reserveKwh, min($capacityKwh, $soc + $netKwh[$i]));
        }

        $dischargeCapConfig = max(0, (int) ($this->strategyConfig['expensive_slots_to_export'] ?? 0));
        $dischargeSortRates = $exportIsVariable ? $exportRates : $importRates;
        $dischargeCandidates = [];
        for ($i = 0; $i < $n; $i++) {
            if (!isset($chargeSet[$i])) {
                $dischargeCandidates[$i] = $dischargeSortRates[$i];
            }
        }
        arsort($dischargeCandidates); // most desirable to discharge first, keys preserved
        $selected = array_slice(array_keys($dischargeCandidates), 0, $dischargeCapConfig);

        // Feasibility trim: walk the day once with charges + all tentative discharges
        // applied in time order; if the battery would go below reserve anywhere, drop
        // the least price-desirable tentative slot and re-check. Bounded by count($selected).
        while ($selected) {
            $soc = $startingSocKwh;
            $selectedSet = array_flip($selected);
            $violated = false;
            for ($i = 0; $i < $n; $i++) {
                if (isset($chargeSet[$i])) {
                    $soc = min($capacityKwh, $soc + $chargeEnergyKwh);
                } elseif (isset($selectedSet[$i])) {
                    if ($soc - $dischargeEnergyKwh < $reserveKwh) {
                        $violated = true;
                        break;
                    }
                    $soc -= $dischargeEnergyKwh;
                } else {
                    $soc = max($reserveKwh, min($capacityKwh, $soc + $netKwh[$i]));
                }
            }
            if (!$violated) {
                break;
            }
            array_pop($selected); // arsort order preserved by the slice above — last = worst-ranked
        }
        sort($selected);
        $dischargeIndexes = $selected;

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
            'explanations' => $this->explainPeriods($periods, $importRates, $exportRates, $exportIsVariable, $neededTopUpKwh),
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

    /** @return string[] one sentence per non-SelfUse period, same order periodsToGroups() emits groups in */
    private function explainPeriods(array $periods, array $importRates, ?array $exportRates, bool $exportIsVariable, float $neededTopUpKwh): array
    {
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
            } else {
                $rates = $exportIsVariable ? $exportRates : $importRates;
                $avg = $this->average($rates, $indexes);
                $label = $exportIsVariable ? 'Selling' : 'Discharging';
                $explanations[] = sprintf(
                    '%s %s (avg %sp/kWh) — projected spare battery capacity that won\'t dip below reserve.',
                    $label,
                    $range,
                    number_format($avg, 2),
                );
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
