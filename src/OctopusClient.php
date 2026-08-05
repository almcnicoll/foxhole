<?php

require_once __DIR__ . '/Exceptions.php';

// Fetches half-hourly Agile (or any standard-unit-rates tariff) rates from
// the public Octopus Energy API. Callers are responsible for persisting the
// result (see Store::saveRateSlots) — this class only fetches and parses.
class OctopusClient
{
    private const BASE_URL = 'https://api.octopus.energy/v1';

    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    /**
     * @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, rate: float}>
     *         Up to 48 half-hour slots, ascending by start time, rate in pence/kWh inc. VAT.
     *         Can return fewer than 48 (even 0) — Octopus's published horizon sometimes lags
     *         the last hour or so of "today" too, not just an unpublished "tomorrow". Callers
     *         decide what "not enough" means (see PriceProvider); this method just fetches
     *         and parses whatever currently exists.
     */
    public function fetchRatesForDate(string $productCode, string $tariffCode, DateTimeImmutable $localDate): array
    {
        $periodFrom = $localDate->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'));
        $periodTo = $periodFrom->modify('+1 day');

        $url = sprintf(
            '%s/products/%s/electricity-tariffs/%s/standard-unit-rates/?period_from=%s&period_to=%s',
            self::BASE_URL,
            rawurlencode($productCode),
            rawurlencode($tariffCode),
            $periodFrom->format('Y-m-d\TH:i:s\Z'),
            $periodTo->format('Y-m-d\TH:i:s\Z'),
        );

        $body = $this->httpGet($url);
        $data = json_decode($body, true);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            throw new OctopusFetchException('Unexpected response shape from Octopus API');
        }

        // A single day is 48 slots, well under the API's default page_size=100,
        // so pagination ('next') is not expected here — not handled.

        $slots = [];
        foreach ($data['results'] as $result) {
            $slots[] = [
                'from' => new DateTimeImmutable($result['valid_from']),
                'to' => new DateTimeImmutable($result['valid_to']),
                'rate' => (float) $result['value_inc_vat'],
            ];
        }
        usort($slots, fn($a, $b) => $a['from'] <=> $b['from']);

        if (count($slots) < 48) {
            // Not necessarily a problem — Octopus's publish horizon can lag by an hour or two
            // even for "today" (confirmed live: the API's own count/next/previous fields show
            // no pagination involved, it genuinely just hasn't published the last slot or two
            // yet). Missing slots simply stay on SelfUse until a later run has full data for
            // them. Only a caller seeing zero usable slots should treat this as a failure.
            $this->logger->warn(sprintf('Octopus returned %d/48 slots for %s', count($slots), $localDate->format('Y-m-d')));
        }

        return $slots;
    }

    private function httpGet(string $url, bool $isRetry = false): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            // Single retry on transient network failure, per spec §12 — no backoff, no further retries.
            if (!$isRetry) {
                return $this->httpGet($url, true);
            }
            throw new OctopusFetchException("cURL error fetching Octopus rates: $error");
        }
        if ($status !== 200) {
            throw new OctopusFetchException("Octopus API returned HTTP $status");
        }

        return $body;
    }
}
