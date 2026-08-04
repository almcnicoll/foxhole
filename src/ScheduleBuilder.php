<?php

require_once __DIR__ . '/Exceptions.php';

// Turns 48 Agile rate slots + a cost basis into FoxESS scheduler groups.
// Pure price-threshold logic (see spec §7) — no solar/load forecasting.
class ScheduleBuilder
{
    public function __construct(
        private readonly array $strategyConfig,
        private readonly array $batteryConfig,
    ) {
    }

    /**
     * @param array $slots 48 chronological slots from OctopusClient (UTC datetimes)
     * @param float[] $costBasis 48 values aligned to $slots, pence/kWh
     * @return array{groups: array}
     */
    public function build(array $slots, array $costBasis): array
    {
        $n = count($slots);
        if ($n !== count($costBasis)) {
            throw new ScheduleBuildException('Slot count and cost basis count must match');
        }
        if ($n === 0) {
            throw new ScheduleBuildException('No slots to build a schedule from');
        }

        $modes = array_fill(0, $n, 'SelfUse');

        // Candidates for charging: strictly cheaper than what we'd otherwise pay.
        $chargeCandidates = [];
        for ($i = 0; $i < $n; $i++) {
            if ($slots[$i]['rate'] < $costBasis[$i]) {
                $chargeCandidates[$i] = $slots[$i]['rate'];
            }
        }
        asort($chargeCandidates); // ascending by rate, keys preserved
        $chargeCap = max(0, (int) ($this->strategyConfig['cheap_slots_to_charge'] ?? 0));
        $chargeIndexes = array_slice(array_keys($chargeCandidates), 0, $chargeCap, true);
        foreach ($chargeIndexes as $i) {
            $modes[$i] = 'ForceCharge';
        }

        // Most expensive slots overall get force-discharged, whatever charging picked.
        $dischargeCandidates = [];
        for ($i = 0; $i < $n; $i++) {
            if (!in_array($i, $chargeIndexes, true)) {
                $dischargeCandidates[$i] = $slots[$i]['rate'];
            }
        }
        arsort($dischargeCandidates); // descending by rate, keys preserved
        $dischargeCap = max(0, (int) ($this->strategyConfig['expensive_slots_to_export'] ?? 0));
        $dischargeIndexes = array_slice(array_keys($dischargeCandidates), 0, $dischargeCap, true);
        foreach ($dischargeIndexes as $i) {
            $modes[$i] = 'ForceDischarge';
        }

        $timezone = new DateTimeZone($this->strategyConfig['timezone'] ?? 'Europe/London');
        $periods = $this->mergeContiguous($slots, $modes, $timezone);

        return ['groups' => $this->periodsToGroups($periods)];
    }

    /** @return array<int, array{mode: string, start: DateTimeImmutable, end: DateTimeImmutable}> */
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
            $periods[] = ['mode' => $mode, 'start' => $start, 'end' => $end];
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
}
