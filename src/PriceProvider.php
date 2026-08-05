<?php

require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Store.php';
require_once __DIR__ . '/OctopusClient.php';

/**
 * Resolves the 48 half-hourly import (purchase) and export (sale) prices for
 * a given local day. Each side independently either fetches live Octopus
 * rates or returns a flat rate, controlled by the `{import,export}_price_mode`
 * / `{import,export}_price_fixed_pence` settings (see Store) — so either can
 * be switched to a fixed price from settings.php without touching config.php.
 * Import defaults to 'api' (Agile), export defaults to 'fixed' (most FoxESS
 * owners are on a flat export rate, not Agile Outgoing).
 */
class PriceProvider
{
    public function __construct(
        private readonly OctopusClient $octopus,
        private readonly array $octopusConfig,
    ) {
    }

    /** @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, rate: float}> */
    public function resolveImport(DateTimeImmutable $localDate): array
    {
        return $this->resolve(
            'import',
            $localDate,
            $this->octopusConfig['product_code'] ?? null,
            $this->octopusConfig['tariff_code'] ?? null,
            defaultMode: 'api',
            defaultFixedPence: '0',
        );
    }

    /** @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, rate: float}> */
    public function resolveExport(DateTimeImmutable $localDate): array
    {
        return $this->resolve(
            'export',
            $localDate,
            $this->octopusConfig['export_product_code'] ?? null,
            $this->octopusConfig['export_tariff_code'] ?? null,
            defaultMode: 'fixed',
            defaultFixedPence: '12',
        );
    }

    private function resolve(
        string $kind,
        DateTimeImmutable $localDate,
        ?string $productCode,
        ?string $tariffCode,
        string $defaultMode,
        string $defaultFixedPence,
    ): array {
        $mode = getSetting("{$kind}_price_mode") ?? $defaultMode;

        if ($mode === 'fixed') {
            $fixed = (float) (getSetting("{$kind}_price_fixed_pence") ?? $defaultFixedPence);
            return $this->flatSlotsForDate($localDate, $fixed);
        }

        if (!$productCode || !$tariffCode) {
            throw new OctopusFetchException("$kind price mode is 'api' but no Octopus product/tariff code is configured for it");
        }

        $slots = $this->octopus->fetchRatesForDate($productCode, $tariffCode, $localDate);
        if ($slots === []) {
            // OctopusClient no longer treats a partial day as fatal — this is the one case
            // that genuinely means "nothing usable", so it's where resolve() draws the line.
            throw new OctopusFetchException("No $kind rates published yet for " . $localDate->format('Y-m-d'));
        }

        return $slots;
    }

    /** @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, rate: float}> */
    private function flatSlotsForDate(DateTimeImmutable $localDate, float $rate): array
    {
        $slots = [];
        $start = $localDate->setTime(0, 0);
        for ($i = 0; $i < 48; $i++) {
            $from = $start->modify(sprintf('+%d minutes', $i * 30));
            $slots[] = ['from' => $from, 'to' => $from->modify('+30 minutes'), 'rate' => $rate];
        }
        return $slots;
    }
}
