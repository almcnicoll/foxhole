<?php

require_once __DIR__ . '/Exceptions.php';

// Fetches an hourly solar generation estimate from the free Forecast.Solar API
// (https://api.forecast.solar/swagger.yaml) for one panel array. Callers persist
// the result (see Store::saveSolarForecast) — this class only fetches and parses.
// Not yet wired into ScheduleBuilder's decisions — see roadmap.MD's
// "Solar-generation-aware scheduling" item for that follow-up.
class SolarForecastClient
{
    private const BASE_URL = 'https://api.forecast.solar/estimate';

    public function __construct(
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array $config solar config block: latitude, longitude, declination, azimuth, kwp
     * @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, watt_hours: int}>
     *         Hourly periods (today + tomorrow, as far as the API returns), ascending by start time.
     */
    public function fetchForecast(array $config, DateTimeZone $timezone): array
    {
        $url = sprintf(
            '%s/%s/%s/%s/%s/%s?time=%s',
            self::BASE_URL,
            rawurlencode((string) $config['latitude']),
            rawurlencode((string) $config['longitude']),
            rawurlencode((string) $config['declination']),
            rawurlencode((string) $config['azimuth']),
            rawurlencode((string) $config['kwp']),
            rawurlencode($timezone->getName()),
        );

        $body = $this->httpGet($url);
        $data = json_decode($body, true);
        $periods = $data['result']['watt_hours_period'] ?? null;
        if (!is_array($periods)) {
            throw new SolarForecastException('Unexpected response shape from Forecast.Solar API');
        }

        $slots = [];
        $from = null;
        foreach ($periods as $timeStr => $wattHours) {
            $to = new DateTimeImmutable($timeStr, $timezone);
            $slots[] = ['from' => $from ?? $to, 'to' => $to, 'watt_hours' => (int) $wattHours];
            $from = $to;
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
            // Single retry on transient network failure, matching OctopusClient/FoxessClient (spec §12).
            if (!$isRetry) {
                return $this->httpGet($url, true);
            }
            throw new SolarForecastException("cURL error fetching solar forecast: $error");
        }
        // Forecast.Solar's free tier rate-limits to a handful of calls/day per location and
        // returns 429 with a JSON error body (not just a bare status) — surface it as-is.
        if ($status !== 200) {
            throw new SolarForecastException("Forecast.Solar API returned HTTP $status: $body");
        }

        return $body;
    }
}
