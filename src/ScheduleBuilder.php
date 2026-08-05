<?php

require_once __DIR__ . '/Exceptions.php';

// Turns import/export rate slots + a cost basis into FoxESS scheduler groups,
// plus a plain-English explanation for each decision. A greedy, explainable
// heuristic by design, not a global optimiser — the spec wants price-threshold
// logic (§7), and explanations need to be narratable, which argues against an
// opaque solver anyway. See CLAUDE.md for the reasoning behind each rule.
//
// Selection rules:
//   - Charge a slot if it's below your cost basis, OR cheap enough that even
//     the day's best export rate would make buying now profitable (arbitrage).
//   - When there are more charge candidates than the cap, prefer ones before
//     today's most expensive import slot, so the battery tends to be full
//     heading into it.
//   - Ahead of each cheap charging window, reserve one discharge slot (cheapest
//     window first) so there's a bit more room to absorb it — out of the same
//     discharge cap as everything else, not an extra budget.
//   - With whatever discharge budget remains, sell at the slots with the
//     highest export rate, if export price actually varies today. If it's
//     flat, fall back to offsetting the most expensive import slots instead
//     (a flat export has no "best time to sell").
class ScheduleBuilder
{
    public function __construct(
        private readonly array $strategyConfig,
        private readonly array $batteryConfig,
    ) {
    }

    /**
     * @param array $importSlots N chronological slots (UTC datetimes), ['from','to','rate']
     * @param ?array $exportSlots same shape/length as $importSlots, or null if unavailable this run
     * @param float[] $costBasis N values aligned to $importSlots, pence/kWh
     * @return array{groups: array, explanations: string[], summary: string}
     */
    public function build(array $importSlots, ?array $exportSlots, array $costBasis): array
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

        $importRates = array_column($importSlots, 'rate');
        $exportRates = $exportSlots !== null ? array_column($exportSlots, 'rate') : null;

        $peakImportIndex = self::indexOfMax($importRates);
        $bestExportRate = $exportRates !== null ? max($exportRates) : null;
        $exportIsVariable = $exportRates !== null && max($exportRates) - min($exportRates) > 0.0;

        $modes = array_fill(0, $n, 'SelfUse');

        // --- Charge candidates: below cost basis, or cheap enough to arbitrage against the best export rate ---
        $chargeCandidates = []; // index => reason[] ('cost_basis' and/or 'arbitrage')
        for ($i = 0; $i < $n; $i++) {
            $reasons = [];
            if ($importRates[$i] < $costBasis[$i]) {
                $reasons[] = 'cost_basis';
            }
            if ($bestExportRate !== null && $importRates[$i] < $bestExportRate) {
                $reasons[] = 'arbitrage';
            }
            if ($reasons) {
                $chargeCandidates[$i] = $reasons;
            }
        }

        // Prefer slots before today's import peak, so the battery is more likely to be
        // full heading into it — only fall back to post-peak slots if that's not enough.
        $preIndexes = array_values(array_filter(array_keys($chargeCandidates), fn($i) => $i < $peakImportIndex));
        $postIndexes = array_values(array_filter(array_keys($chargeCandidates), fn($i) => $i >= $peakImportIndex));
        usort($preIndexes, fn($a, $b) => $importRates[$a] <=> $importRates[$b]);
        usort($postIndexes, fn($a, $b) => $importRates[$a] <=> $importRates[$b]);

        $chargeCap = max(0, (int) ($this->strategyConfig['cheap_slots_to_charge'] ?? 0));
        $chargeIndexes = array_slice([...$preIndexes, ...$postIndexes], 0, $chargeCap);
        $chargeReasons = [];
        foreach ($chargeIndexes as $i) {
            $modes[$i] = 'ForceCharge';
            $chargeReasons[$i] = $chargeCandidates[$i];
        }

        $timezone = new DateTimeZone($this->strategyConfig['timezone'] ?? 'Europe/London');
        $dischargeCap = max(0, (int) ($this->strategyConfig['expensive_slots_to_export'] ?? 0));

        // Reserve a discharge slot immediately before each cheap charging window (cheapest
        // window first), so the battery has a bit more room to absorb it — out of the same
        // cap as everything else below, not an extra budget. Windows are found from the
        // full candidate set (not the capped $chargeIndexes): for a wide cheap block, the
        // cap can select from the middle of it, so anchoring on the selection itself would
        // either land inside the cheap block or miss the window's true edge entirely.
        $preChargeIndexes = []; // reserved index => next-charge-time string, for the explanation
        $budget = $dischargeCap;
        foreach ($this->findChargingWindows($chargeCandidates, $chargeIndexes, $importRates) as $window) {
            if ($budget <= 0) {
                break;
            }
            $candidate = $window['start'] - 1;
            if ($candidate < 0 || isset($chargeCandidates[$candidate]) || isset($preChargeIndexes[$candidate])) {
                continue; // no room before this window, or already claimed by another one
            }
            $preChargeIndexes[$candidate] = $importSlots[$window['firstChargeIndex']]['from']->setTimezone($timezone)->format('H:i');
            $budget--;
        }

        // --- Remaining budget: sell at the export peak if export price actually varies,
        // otherwise fall back to offsetting the most expensive import slots (the old behaviour). ---
        $dischargeSortRates = $exportIsVariable ? $exportRates : $importRates;
        $dischargeCandidates = [];
        for ($i = 0; $i < $n; $i++) {
            if (!in_array($i, $chargeIndexes, true) && !isset($preChargeIndexes[$i])) {
                $dischargeCandidates[$i] = $dischargeSortRates[$i];
            }
        }
        arsort($dischargeCandidates); // descending, keys preserved
        $priceRankedIndexes = array_slice(array_keys($dischargeCandidates), 0, max(0, $dischargeCap - count($preChargeIndexes)));
        foreach ([...$priceRankedIndexes, ...array_keys($preChargeIndexes)] as $i) {
            $modes[$i] = 'ForceDischarge';
        }

        $periods = $this->mergeContiguous($importSlots, $modes, $timezone);

        return [
            'groups' => $this->periodsToGroups($periods),
            'explanations' => $this->explainPeriods($periods, $importRates, $exportRates, $costBasis, $bestExportRate, $chargeReasons, $exportIsVariable, $preChargeIndexes),
            'summary' => $this->explainPeak($importSlots[$peakImportIndex], $importRates[$peakImportIndex], $timezone, count($chargeIndexes) > 0),
        ];
    }

    /**
     * Maximal contiguous runs of $chargeCandidates (the full eligible set, not the capped
     * selection) that actually got at least one slot into $chargeIndexes, ranked
     * cheapest-first by the average rate of the slots that were actually selected within
     * each window — i.e. how cheap the real charging happening there is, not a hypothetical
     * full-window average that might include slots the cap didn't select.
     *
     * @return array<int, array{start: int, firstChargeIndex: int, avgRate: float}>
     */
    private function findChargingWindows(array $chargeCandidates, array $chargeIndexes, array $importRates): array
    {
        $candidateIndexes = array_keys($chargeCandidates);
        sort($candidateIndexes);

        $windows = [];
        $windowStart = null;
        $prev = null;
        foreach ($candidateIndexes as $i) {
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

        $chargingWindows = [];
        foreach ($windows as [$start, $end]) {
            $selected = array_values(array_filter($chargeIndexes, fn($i) => $i >= $start && $i <= $end));
            if (!$selected) {
                continue; // the cap excluded this whole window — nothing to make room for
            }
            $chargingWindows[] = [
                'start' => $start,
                'firstChargeIndex' => min($selected),
                'avgRate' => $this->average($importRates, $selected),
            ];
        }
        usort($chargingWindows, fn($a, $b) => $a['avgRate'] <=> $b['avgRate']);

        return $chargingWindows;
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

    /** @return array<int, array{mode: string, startIndex: int, endIndex: int, start: DateTimeImmutable, end: DateTimeImmutable}> */
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

    private function periodsToGroups(array $periods): array
    {
        $chargeKw = (float) ($this->batteryConfig['max_charge_kw'] ?? 0);
        $dischargeKw = (float) ($this->batteryConfig['max_discharge_kw'] ?? 0);
        $minSocOnGrid = (int) ($this->batteryConfig['min_soc_on_grid'] ?? 0);
        $reserveSoc = (int) ($this->batteryConfig['reserve_soc'] ?? 0);

        $groups = [];
        foreach ($periods as $period) {
            // SelfUse is the inverter's own default — leave those slots alone
            // rather than sending an explicit period for every one of them.
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
                // fdSoc/fdPwr per the spec's example payload: SoC ceiling/floor and
                // power limit for whichever force mode is active. Field semantics
                // aren't fully confirmed against live docs — see CLAUDE.md.
                'fdSoc' => $isCharge ? 100 : $reserveSoc,
                'fdPwr' => (int) round(($isCharge ? $chargeKw : $dischargeKw) * 1000),
            ];
        }

        return $groups;
    }

    /** @return string[] one sentence per non-SelfUse period, same order periodsToGroups() emits groups in */
    private function explainPeriods(
        array $periods,
        array $importRates,
        ?array $exportRates,
        array $costBasis,
        ?float $bestExportRate,
        array $chargeReasons,
        bool $exportIsVariable,
        array $nextChargeTimeByIndex,
    ): array {
        $explanations = [];
        foreach ($periods as $period) {
            if ($period['mode'] === 'SelfUse') {
                continue;
            }
            $range = $this->formatRange($period);
            $slotIndexes = range($period['startIndex'], $period['endIndex']);

            if ($period['mode'] === 'ForceCharge') {
                $avgImport = $this->average($importRates, $slotIndexes);
                $avgCostBasis = $this->average($costBasis, $slotIndexes);
                $reasonsInPeriod = [];
                foreach ($slotIndexes as $i) {
                    $reasonsInPeriod = [...$reasonsInPeriod, ...($chargeReasons[$i] ?? [])];
                }
                $explanations[] = $this->explainCharge(
                    $range,
                    $avgImport,
                    $avgCostBasis,
                    in_array('cost_basis', $reasonsInPeriod, true),
                    in_array('arbitrage', $reasonsInPeriod, true),
                    $bestExportRate,
                );
            } else {
                // A period is usually a single reserved slot, but don't assume it —
                // use whichever slot in the (possibly merged) period has a reservation.
                $nextChargeTime = null;
                foreach ($slotIndexes as $i) {
                    if (isset($nextChargeTimeByIndex[$i])) {
                        $nextChargeTime = $nextChargeTimeByIndex[$i];
                        break;
                    }
                }
                if ($nextChargeTime !== null) {
                    $avgImport = $this->average($importRates, $slotIndexes);
                    $explanations[] = $this->explainDischarge($range, false, $avgImport, true, $nextChargeTime);
                } else {
                    $avgRate = $this->average($exportIsVariable ? $exportRates : $importRates, $slotIndexes);
                    $explanations[] = $this->explainDischarge($range, $exportIsVariable, $avgRate);
                }
            }
        }
        return $explanations;
    }

    private function explainCharge(string $range, float $avgImport, float $avgCostBasis, bool $usedCostBasis, bool $usedArbitrage, ?float $bestExportRate): string
    {
        $rate = number_format($avgImport, 2);
        if ($usedCostBasis && $usedArbitrage) {
            return sprintf(
                'Charging %s (avg %sp/kWh) — below both your %sp cost basis and the best export rate today (%sp), so it beats your normal rate either way.',
                $range,
                $rate,
                number_format($avgCostBasis, 2),
                number_format($bestExportRate, 2),
            );
        }
        if ($usedArbitrage) {
            return sprintf(
                'Charging %s (avg %sp/kWh) — above your %sp cost basis, but cheaper than the best export rate today (%sp), so it\'s worth buying now to sell or self-use later.',
                $range,
                $rate,
                number_format($avgCostBasis, 2),
                number_format($bestExportRate, 2),
            );
        }
        return sprintf('Charging %s (avg %sp/kWh) — below your %sp cost basis.', $range, $rate, number_format($avgCostBasis, 2));
    }

    private function explainDischarge(string $range, bool $exportDriven, float $avgRate, bool $isPreCharge = false, ?string $nextChargeTime = null): string
    {
        if ($isPreCharge) {
            return sprintf(
                'Discharging %s (avg import %sp/kWh) — clearing space in the battery ahead of the cheap charging window at %s, so more of it can be bought.',
                $range,
                number_format($avgRate, 2),
                $nextChargeTime,
            );
        }
        if ($exportDriven) {
            return sprintf('Selling %s (avg export %sp/kWh) — the highest export rate today.', $range, number_format($avgRate, 2));
        }
        return sprintf(
            'Discharging %s (avg import %sp/kWh) — among the most expensive import slots today, so battery power offsets it instead of a flat-rate export.',
            $range,
            number_format($avgRate, 2),
        );
    }

    private function explainPeak(array $peakSlot, float $peakRate, DateTimeZone $timezone, bool $chargedAnything): string
    {
        $time = $peakSlot['from']->setTimezone($timezone)->format('H:i');
        if (!$chargedAnything) {
            return sprintf("Today's most expensive import slot is %s at %sp/kWh — no slots were cheap enough to charge ahead of it.", $time, number_format($peakRate, 2));
        }
        return sprintf("Today's most expensive import slot is %s at %sp/kWh — charging is prioritised beforehand so the battery is topped up going into it.", $time, number_format($peakRate, 2));
    }

    private function formatRange(array $period): string
    {
        return $period['start']->format('H:i') . '–' . $period['end']->format('H:i');
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
