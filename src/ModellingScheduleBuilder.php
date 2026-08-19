<?php

require_once __DIR__ . '/Exceptions.php';

/**
 * GitHub issue #5's "Modelling scheduler" — an exact dynamic-programming solver, not a
 * greedy heuristic like ScheduleBuilder/IntelligentScheduleBuilder (see CLAUDE.md for why
 * those two are deliberately not solvers). Discretises battery SoC into bins and solves a
 * Bellman shortest-path recursion across (slot x SoC bin) for the minimum-cost action
 * sequence — exact and deterministic for a given discretisation, no tuning or random
 * search, and cheap to compute (tens of thousands of evaluations for a typical horizon).
 *
 * Unlike the other two schedulers, this one's horizon is a *rolling window from now*
 * (typically the rest of today plus overnight, up to 48 slots), not a single calendar day
 * — it may genuinely cross a midnight boundary. That's why build() returns absolute
 * DateTimeImmutable intervals rather than calendar-day-relative hour/minute groups; the
 * caller (Schedulers.php's buildModellingSchedule()) splits those into per-date storage,
 * the same reasoning ScheduleBuilder::buildPushWindow() already documents for its own
 * internal absolute-interval step.
 *
 * Confirmed with the user: three discrete actions per slot (force-charge/force-discharge
 * at rated power, self-use/idle) — no intermediate power levels.
 *
 * Cost model per slot, per action (kWh, before pence conversion):
 *   - ForceCharge: draws at the rated charge power (capped by remaining headroom, itself
 *     inflated by round-trip efficiency — storing 1kWh costs more than 1kWh drawn from the
 *     grid). Net grid = that raw draw + usage - solar, independent of whether solar covers
 *     some of it — same convention IntelligentScheduleBuilder already uses for forced
 *     charge (a fixed commanded rate, not solar-aware).
 *   - ForceDischarge: supplies at the rated discharge power (capped by energy available
 *     above the reserve floor). Net grid = usage - solar - that supply (can go negative,
 *     i.e. export, if discharge + solar together exceed load).
 *   - SelfUse (idle): the exact natural-trajectory formula already validated in
 *     IntelligentScheduleBuilder — solar covers load first, surplus charges the battery
 *     (excess beyond capacity exports), deficit draws from the battery down to
 *     min_soc_on_grid (not reserve_soc — same floor distinction CLAUDE.md documents
 *     elsewhere: reserve_soc is specifically how far a *forced* discharge may drain the
 *     battery, min_soc_on_grid is the general system floor for everything else).
 * Round-trip efficiency is applied once, on the charge side only — a standard
 * simplification, not a claim that discharge is lossless.
 *
 * Cost = net grid >= 0 ? net grid x import price : net grid x export price (a negative
 * cost from exporting is a credit). An unknown export price (no export data this run)
 * values any export at 0p/kWh — conservative, never invents a number.
 */
class ModellingScheduleBuilder
{
    // SelfUse first, deliberately: the DP only overwrites a (slot, bin) state on a
    // strictly lower cost (see the `$totalCost < $cost[...] - 1e-9` check below), so
    // evaluating SelfUse first makes it win every cost tie against ForceCharge/
    // ForceDischarge. This matters whenever SoC is already at a floor a forced action
    // can't usefully move past (e.g. starting exactly at reserve_soc, the common case
    // when no live SoC reading is available) — ForceDischarge there can't actually
    // discharge anything (available energy is 0), so it produces the *same* net grid
    // flow and cost as SelfUse, just mislabelled as an explicit forced action pushed to
    // the inverter with a misleading "highest-value point to discharge" explanation.
    // Found live: a throwaway-config dry run started at the reserve floor and the
    // optimiser picked ForceDischarge for a no-op tie purely because of array order.
    private const ACTIONS = ['SelfUse', 'ForceCharge', 'ForceDischarge'];

    public function __construct(
        private readonly array $strategyConfig,
        private readonly array $batteryConfig,
        private readonly array $modellingConfig,
    ) {
    }

    /**
     * @param array $importSlots N chronological half-hour slots (real instants — this
     *        scheduler's horizon is a rolling window, not necessarily calendar-day-aligned,
     *        so slots may span a midnight boundary), ['from','to','rate']
     * @param ?array $exportSlots same shape/length as $importSlots, or null if unavailable
     * @param float[] $halfHourlyUsageKwh N values aligned to $importSlots — see HalfHourlyUsageEstimator
     * @param ?array $solarSlots SolarForecastClient-shaped periods (['from','to','watt_hours']),
     *        prorated onto $importSlots by time overlap. Null if unavailable.
     * @param ?float $currentSocPercent actual battery SoC right now (FoxessClient::getBatterySoc()),
     *        0-100, or null if unknown (falls back to the reserve floor, same as IntelligentScheduleBuilder).
     * @return array{intervals: array, summary: string, finalSocPercent: float, totalCostPence: float}
     *         intervals: array<int, array{start: DateTimeImmutable, end: DateTimeImmutable, workMode: string, explanation: string}>,
     *         already excluding SelfUse periods (nothing to explicitly push for those, same
     *         convention as the other two schedulers' own build() output). totalCostPence
     *         is the DP's own optimal-path cost (negative = net credit) — mainly for tests
     *         to confirm it's actually optimising, but also generically useful (e.g. a
     *         future "projected cost" figure in the UI).
     */
    public function build(array $importSlots, ?array $exportSlots, array $halfHourlyUsageKwh, ?array $solarSlots, ?float $currentSocPercent): array
    {
        $n = count($importSlots);
        if ($n !== count($halfHourlyUsageKwh)) {
            throw new ScheduleBuildException('Slot count and usage forecast count must match');
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

        $capacityKwh = (float) ($this->batteryConfig['capacity_kwh'] ?? 0);
        $chargeKw = (float) ($this->batteryConfig['max_charge_kw'] ?? 0);
        $dischargeKw = (float) ($this->batteryConfig['max_discharge_kw'] ?? 0);
        $minSocOnGrid = (int) ($this->batteryConfig['min_soc_on_grid'] ?? 0);
        $reserveSoc = (int) ($this->batteryConfig['reserve_soc'] ?? 0);
        $efficiency = max(0.01, min(1.0, ((float) ($this->batteryConfig['round_trip_efficiency_pct'] ?? 100)) / 100));

        $reserveSocKwh = $capacityKwh * $reserveSoc / 100;
        $minSocOnGridKwh = $capacityKwh * $minSocOnGrid / 100;
        $chargeEnergyKwh = $chargeKw * 0.5; // raw draw at rated power for a half-hour, before efficiency loss
        $dischargeEnergyKwh = $dischargeKw * 0.5;

        $binSizeKwh = max(0.001, (float) ($this->modellingConfig['soc_bin_kwh'] ?? 0.1));
        $usableRangeKwh = max(0.0, $capacityKwh - $reserveSocKwh);
        $numBins = (int) round($usableRangeKwh / $binSizeKwh) + 1;

        $binKwh = fn (int $bin): float => min($capacityKwh, $reserveSocKwh + $bin * $binSizeKwh);
        $socToBin = fn (float $soc): int => max(0, min($numBins - 1, (int) round(($soc - $reserveSocKwh) / $binSizeKwh)));

        $startingSocPercent = $currentSocPercent ?? (float) $reserveSoc;
        $startingSocKwh = max($reserveSocKwh, min($capacityKwh, $capacityKwh * $startingSocPercent / 100));
        $startBin = $socToBin($startingSocKwh);

        $solarKwh = $solarSlots !== null ? $this->alignSolarToSlots($importSlots, $solarSlots) : array_fill(0, $n, 0.0);

        // --- Bellman recursion: forward DP across (slot x SoC bin), with backpointers ---
        $cost = array_fill(0, $n + 1, array_fill(0, $numBins, INF));
        $backpointer = array_fill(0, $n + 1, array_fill(0, $numBins, null));
        $cost[0][$startBin] = 0.0;

        for ($t = 0; $t < $n; $t++) {
            $importPrice = $importRates[$t];
            $exportPrice = $exportRates !== null ? $exportRates[$t] : 0.0;
            $usage = $halfHourlyUsageKwh[$t];
            $solar = $solarKwh[$t];

            for ($b = 0; $b < $numBins; $b++) {
                if ($cost[$t][$b] === INF) {
                    continue; // unreached state
                }
                $socKwh = $binKwh($b);
                foreach (self::ACTIONS as $action) {
                    [$newSocKwh, $netGridKwh] = $this->transition(
                        $action,
                        $socKwh,
                        $usage,
                        $solar,
                        $chargeEnergyKwh,
                        $dischargeEnergyKwh,
                        $efficiency,
                        $capacityKwh,
                        $reserveSocKwh,
                        $minSocOnGridKwh,
                    );
                    $stepCost = $netGridKwh >= 0 ? $netGridKwh * $importPrice : $netGridKwh * $exportPrice;
                    $totalCost = $cost[$t][$b] + $stepCost;
                    $newBin = $socToBin($newSocKwh);
                    if ($totalCost < $cost[$t + 1][$newBin] - 1e-9) {
                        $cost[$t + 1][$newBin] = $totalCost;
                        $backpointer[$t + 1][$newBin] = ['fromBin' => $b, 'action' => $action];
                    }
                }
            }
        }

        $minEndSocKwh = $capacityKwh * ((int) ($this->modellingConfig['min_end_soc_pct'] ?? 0)) / 100;
        // Stored energy above the floor is valued at this horizon's own cheapest import
        // rate — see pickTerminalBin()'s doc comment for why that's necessary at all.
        $cheapestImportRate = min($importRates);
        [$bestBin, $constraintRelaxed] = $this->pickTerminalBin($cost[$n], $binKwh, $numBins, $minEndSocKwh, $cheapestImportRate);

        // --- Reconstruct the action sequence via backpointers ---
        $actionByIndex = [];
        $bin = $bestBin;
        for ($t = $n; $t > 0; $t--) {
            $bp = $backpointer[$t][$bin];
            $actionByIndex[$t - 1] = $bp['action'];
            $bin = $bp['fromBin'];
        }
        ksort($actionByIndex);

        $periods = $this->mergeContiguous($importSlots, $actionByIndex, $timezone);
        $intervals = [];
        foreach ($periods as $period) {
            if ($period['mode'] === 'SelfUse') {
                continue; // the inverter's own default — nothing to explicitly push
            }
            $range = range($period['startIndex'], $period['endIndex']);
            $avgImport = $this->average($importRates, $range);
            $explanation = $period['mode'] === 'ForceCharge'
                ? sprintf('Charging %s (avg %sp/kWh import) — the lowest-cost point in this horizon for the optimiser to charge.', $this->formatRange($period), number_format($avgImport, 2))
                : sprintf('Discharging %s (avg %sp/kWh import) — the highest-value point in this horizon for the optimiser to discharge.', $this->formatRange($period), number_format($avgImport, 2));
            $intervals[] = [
                'start' => $period['start'],
                'end' => $period['end'],
                'workMode' => $period['mode'],
                'explanation' => $explanation,
            ];
        }

        $finalSocKwh = $binKwh($bestBin);
        $finalSocPercent = $capacityKwh > 0 ? $finalSocKwh / $capacityKwh * 100 : 0.0;

        $summary = sprintf(
            'Modelling scheduler: %.1fkWh forecast usage, %.1fkWh forecast solar over %d half-hour slot(s) — projected cost %sp. Battery starting at %.0f%% (%.1fkWh), ending at %.0f%% (%.1fkWh)%s.',
            array_sum($halfHourlyUsageKwh),
            array_sum($solarKwh),
            $n,
            number_format($cost[$n][$bestBin], 2),
            $startingSocPercent,
            $startingSocKwh,
            $finalSocPercent,
            $finalSocKwh,
            $constraintRelaxed ? ' — below the configured minimum end-of-horizon SoC, which no reachable state could meet' : '',
        );

        return ['intervals' => $intervals, 'summary' => $summary, 'finalSocPercent' => $finalSocPercent, 'totalCostPence' => $cost[$n][$bestBin]];
    }

    /** @return array{0: float, 1: float} [newSocKwh, netGridKwh] (netGridKwh negative = export) */
    private function transition(
        string $action,
        float $socKwh,
        float $usage,
        float $solar,
        float $chargeEnergyKwh,
        float $dischargeEnergyKwh,
        float $efficiency,
        float $capacityKwh,
        float $reserveSocKwh,
        float $minSocOnGridKwh,
    ): array {
        return match ($action) {
            'ForceCharge' => $this->transitionForceCharge($socKwh, $usage, $solar, $chargeEnergyKwh, $efficiency, $capacityKwh),
            'ForceDischarge' => $this->transitionForceDischarge($socKwh, $usage, $solar, $dischargeEnergyKwh, $reserveSocKwh),
            default => $this->transitionSelfUse($socKwh, $usage, $solar, $capacityKwh, $minSocOnGridKwh),
        };
    }

    private function transitionForceCharge(float $socKwh, float $usage, float $solar, float $chargeEnergyKwh, float $efficiency, float $capacityKwh): array
    {
        $headroomKwh = max(0.0, $capacityKwh - $socKwh);
        $rawDraw = min($chargeEnergyKwh, $efficiency > 0 ? $headroomKwh / $efficiency : 0.0);
        $newSoc = min($capacityKwh, $socKwh + $rawDraw * $efficiency);
        $netGrid = $rawDraw + $usage - $solar;
        return [$newSoc, $netGrid];
    }

    private function transitionForceDischarge(float $socKwh, float $usage, float $solar, float $dischargeEnergyKwh, float $reserveSocKwh): array
    {
        $available = max(0.0, $socKwh - $reserveSocKwh);
        $energyOut = min($dischargeEnergyKwh, $available);
        $newSoc = max($reserveSocKwh, $socKwh - $energyOut);
        $netGrid = $usage - $solar - $energyOut;
        return [$newSoc, $netGrid];
    }

    /** Natural trajectory — solar covers load first, surplus charges the battery (excess exports), deficit draws down to min_soc_on_grid (not reserve_soc — see class doc comment). */
    private function transitionSelfUse(float $socKwh, float $usage, float $solar, float $capacityKwh, float $minSocOnGridKwh): array
    {
        $net = $solar - $usage;
        if ($net >= 0) {
            $absorbed = min($net, max(0.0, $capacityKwh - $socKwh));
            $newSoc = $socKwh + $absorbed;
            $netGrid = -($net - $absorbed);
        } else {
            $deficit = -$net;
            $available = max(0.0, $socKwh - $minSocOnGridKwh);
            $drawn = min($deficit, $available);
            $newSoc = $socKwh - $drawn;
            $netGrid = $deficit - $drawn;
        }
        return [$newSoc, $netGrid];
    }

    /**
     * Prefers the minimum-cost terminal state whose SoC meets $minEndSocKwh. If none does
     * (a very tight horizon can genuinely make this infeasible), falls back to the global
     * minimum-cost terminal state regardless, flagged via the second return value, rather
     * than throwing — SelfUse the whole way is always reachable, so some finite-cost state
     * always exists.
     *
     * A real bug found via live verification (not by inspection): scoring candidate bins on
     * $terminalCosts alone treats any SoC above the floor as worth exactly nothing — so
     * whenever the DP could reach the floor exactly, it would happily force-discharge every
     * kWh above it "for free" at whatever the export price was, even a low flat rate well
     * below what that energy would cost to buy back later, because holding it carried no
     * offsetting value in the comparison. $referencePrice (the horizon's own cheapest import
     * rate — the least this energy could plausibly be replaced for) is credited against each
     * candidate bin's cost per kWh held above the floor, so a discharge is only preferred
     * when the price actually received for it beats that replacement cost, not merely
     * whenever it's non-negative. Genuine self-consumption offsetting (avoiding an
     * expensive import right now) is untouched — this only discourages exporting stored
     * capacity beyond that for a price that isn't actually worth it.
     *
     * @return array{0: int, 1: bool} [bestBin, constraintWasRelaxed]
     */
    private function pickTerminalBin(array $terminalCosts, Closure $binKwh, int $numBins, float $minEndSocKwh, float $referencePrice): array
    {
        // Pass 1: the lowest adjusted cost among feasible bins.
        $bestAdjustedCost = INF;
        for ($b = 0; $b < $numBins; $b++) {
            if ($terminalCosts[$b] === INF || $binKwh($b) < $minEndSocKwh - 1e-9) {
                continue;
            }
            $adjustedCost = $terminalCosts[$b] - ($binKwh($b) - $minEndSocKwh) * $referencePrice;
            $bestAdjustedCost = min($bestAdjustedCost, $adjustedCost);
        }
        // Pass 2: among bins tied on that adjusted cost, prefer the lower *raw* cost. A
        // near-flat market (or one that happens to match $referencePrice exactly) can leave
        // several bins genuinely tied on adjusted cost — e.g. "hold this kWh" vs "spend it
        // and buy an equivalent kWh back" cost the same when the buy-back price equals
        // $referencePrice. Without this tiebreaker the loop would just keep whichever bin it
        // reaches last, which can be an arbitrary no-op force action that costs real money
        // for no benefit instead of the cheaper actual path (typically SelfUse).
        $bestBin = null;
        $bestRawCost = INF;
        for ($b = 0; $b < $numBins; $b++) {
            if ($terminalCosts[$b] === INF || $binKwh($b) < $minEndSocKwh - 1e-9) {
                continue;
            }
            $adjustedCost = $terminalCosts[$b] - ($binKwh($b) - $minEndSocKwh) * $referencePrice;
            if ($adjustedCost < $bestAdjustedCost + 1e-9 && $terminalCosts[$b] < $bestRawCost) {
                $bestRawCost = $terminalCosts[$b];
                $bestBin = $b;
            }
        }
        if ($bestBin !== null) {
            return [$bestBin, false];
        }
        $bestCost = INF;
        for ($b = 0; $b < $numBins; $b++) {
            if ($terminalCosts[$b] < $bestCost) {
                $bestCost = $terminalCosts[$b];
                $bestBin = $b;
            }
        }
        return [$bestBin, true];
    }

    /** @return float[] kWh per import slot, prorating each solar period by time overlap — mirrors IntelligentScheduleBuilder's own alignSolarToSlots() */
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

    private function average(array $values, array $indexes): float
    {
        $sum = 0.0;
        foreach ($indexes as $i) {
            $sum += $values[$i];
        }
        return $sum / count($indexes);
    }

    /** Includes the date when a period's start/end fall on different calendar dates — this scheduler's periods can genuinely cross midnight, unlike the other two schedulers'. */
    private function formatRange(array $period): string
    {
        if ($period['start']->format('Y-m-d') !== $period['end']->format('Y-m-d')) {
            return $period['start']->format('D H:i') . '–' . $period['end']->format('D H:i');
        }
        return $period['start']->format('H:i') . '–' . $period['end']->format('H:i');
    }
}
