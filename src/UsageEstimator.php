<?php

// Estimates today's household load (kWh) for the intelligent scheduler's load profile
// (see IntelligentScheduleBuilder) — there's no real usage history in this app (see
// roadmap.MD), so this interpolates between a configured summer and winter monthly
// figure (settings.php) using day length as the seasonal proxy, rather than a flat
// year-round guess.
class UsageEstimator
{
    // Approximate day length (hours) at the UK's solstices — the scale day length is
    // interpolated against. Used directly as the fallback when solar forecast data isn't
    // available to derive an exact, location-accurate day length from (see below).
    private const FALLBACK_WINTER_DAY_LENGTH_HOURS = 7.7;
    private const FALLBACK_SUMMER_DAY_LENGTH_HOURS = 16.6;

    /**
     * @param array $solarForecast getLatestSolarForecast()-shaped rows (any date range),
     *        or [] if solar isn't enabled/available for this run.
     */
    public static function estimateDailyKwh(float $summerKwhPerMonth, float $winterKwhPerMonth, DateTimeImmutable $date, DateTimeZone $timezone, array $solarForecast): float
    {
        $dayLengthHours = self::dayLengthFromSolar($solarForecast, $date, $timezone)
            ?? self::dayLengthFromDayOfYear($date);

        $fraction = self::seasonFraction($dayLengthHours);
        $monthlyKwh = $winterKwhPerMonth + $fraction * ($summerKwhPerMonth - $winterKwhPerMonth);
        return $monthlyKwh / 30.44; // average days/month
    }

    /** 0.0 = the shortest day of the year (winter), 1.0 = the longest (summer), clamped. */
    private static function seasonFraction(float $dayLengthHours): float
    {
        $range = self::FALLBACK_SUMMER_DAY_LENGTH_HOURS - self::FALLBACK_WINTER_DAY_LENGTH_HOURS;
        $fraction = ($dayLengthHours - self::FALLBACK_WINTER_DAY_LENGTH_HOURS) / $range;
        return max(0.0, min(1.0, $fraction));
    }

    /**
     * Forecast.Solar marks sunrise/sunset with real sub-minute precision (non-zero
     * seconds); every other slot boundary lands exactly on the hour — the only reliable
     * signal that a given boundary is a dawn/dusk instant rather than a plain hourly one.
     * Location-accurate (it's derived from the same lat/long the forecast itself uses),
     * unlike the day-of-year fallback below.
     *
     * @return ?float hours, or null if a dawn/dusk pair can't be identified for this date
     */
    private static function dayLengthFromSolar(array $solarForecast, DateTimeImmutable $date, DateTimeZone $timezone): ?float
    {
        $dateStr = $date->setTimezone($timezone)->format('Y-m-d');

        // Collect every fractional-second boundary across the whole forecast, deduped by
        // instant (the overnight bucket's 'to' and the next day's dawn bucket's 'from'
        // are literally the same timestamp) and sorted chronologically — they then
        // alternate dawn, dusk, dawn, dusk, ... one pair per day covered.
        $instants = [];
        foreach ($solarForecast as $bucket) {
            foreach (['from', 'to'] as $key) {
                $t = $bucket[$key]->setTimezone($timezone);
                if ((int) $t->format('s') !== 0) {
                    $instants[$t->getTimestamp()] = $t;
                }
            }
        }
        ksort($instants);
        $instants = array_values($instants);

        for ($i = 0; $i + 1 < count($instants); $i += 2) {
            $dawn = $instants[$i];
            $dusk = $instants[$i + 1];
            if ($dawn->format('Y-m-d') === $dateStr) {
                return ($dusk->getTimestamp() - $dawn->getTimestamp()) / 3600;
            }
        }
        return null;
    }

    /** Cosine approximation of UK day length by day-of-year — the fallback when solar data isn't available. */
    private static function dayLengthFromDayOfYear(DateTimeImmutable $date): float
    {
        $dayOfYear = (int) $date->format('z'); // 0-365
        $mid = (self::FALLBACK_SUMMER_DAY_LENGTH_HOURS + self::FALLBACK_WINTER_DAY_LENGTH_HOURS) / 2;
        $amplitude = (self::FALLBACK_SUMMER_DAY_LENGTH_HOURS - self::FALLBACK_WINTER_DAY_LENGTH_HOURS) / 2;
        // Summer solstice ~day 172 (21 Jun), where the cosine peaks; troughs ~day 355 (21 Dec).
        return $mid + $amplitude * cos((2 * M_PI / 365.25) * ($dayOfYear - 172));
    }
}
