<?php

// Resolves the reference price ("what you actually pay") that Agile rates are
// compared against. Always returns 48 values aligned to the same slots as the
// Agile rates, regardless of mode, so ScheduleBuilder doesn't need to know
// which mode is active.
class CostBasisProvider
{
    public function __construct(private readonly array $config)
    {
    }

    /**
     * @param int $slotCount number of slots to align to (48 for a full day)
     * @return float[] pence/kWh, one per slot
     */
    public function getCostBasis(int $slotCount): array
    {
        $mode = $this->config['mode'] ?? 'fixed';

        if ($mode === 'fixed') {
            return array_fill(0, $slotCount, (float) $this->config['fixed_pence_per_kwh']);
        }

        if ($mode === 'octopus_product') {
            // TODO: implement once a time-banded tariff (e.g. Flux) is actually live.
            // Flux's rates are banded (a handful of daily bands), not 48 distinct
            // half-hourly values like Agile, so this needs to fetch via OctopusClient
            // against config.cost_basis.product_code/tariff_code and then map bands
            // onto 48 half-hour slots — confirm the real endpoint response shape
            // first rather than assuming it matches standard-unit-rates exactly.
            throw new RuntimeException('cost_basis mode "octopus_product" is not implemented yet (see CostBasisProvider TODO)');
        }

        throw new RuntimeException("Unknown cost_basis mode: $mode");
    }
}
