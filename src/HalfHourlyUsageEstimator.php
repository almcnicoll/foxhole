<?php

require_once __DIR__ . '/UsageEstimator.php';

/**
 * Half-hour-by-half-hour usage forecast for the "Modelling scheduler" (GitHub issue #5) —
 * separate from the existing UsageEstimator (which stays untouched, still used by the
 * other two schedulers' flat daily estimate): different granularity, different consumers,
 * no reason to risk the working one for a feature that needs something more detailed.
 *
 * Samples real historical usage (historic_generation.usage_kwh — see Store.php,
 * HistoryFetcher.php) rather than interpolating a flat daily figure: up to 30 days, ranked
 * "same ISO week in previous years" ahead of "the last 4 weeks", both tiers filtered to
 * dates whose day-type (weekday/Saturday/Sunday) matches the date being forecast, since a
 * Tuesday's usage shape is a poor predictor of a Saturday's. Falls back to a flat estimate
 * when fewer than 3 such days actually have data — the common case for a while after this
 * ships, since there's no usage history before it.
 */
class HalfHourlyUsageEstimator
{
    private const MIN_VALID_DAYS = 3;
    private const MAX_SAMPLED_DAYS = 30;
    private const TIER2_LOOKBACK_DAYS = 28;

    /**
     * @param DateTimeImmutable $date the calendar date to forecast (local to $timezone)
     * @param array $historicRows getHistoricGeneration()-shaped rows spanning as much
     *        history as is available — any range is fine, only usage_kwh is read and only
     *        dates actually needed are used; not fetching a narrower range here keeps this
     *        method pure/DB-free, same reasoning as the existing UsageEstimator.
     * @return float[] 48 values, index 0 = 00:00-00:30 local ... index 47 = 23:30-00:00
     */
    public static function estimateHalfHourly(
        DateTimeImmutable $date,
        DateTimeZone $timezone,
        array $historicRows,
        float $summerKwhPerMonth,
        float $winterKwhPerMonth,
    ): array {
        $date = $date->setTimezone($timezone)->setTime(0, 0);
        $hourlyByDate = self::groupHourlyByDate($historicRows, $timezone);

        $dayType = self::dayType($date);
        $candidates = [
            ...self::tier1Candidates($date, $timezone, $dayType, $hourlyByDate),
            ...self::tier2Candidates($date, $timezone, $dayType),
        ];

        $validDays = [];
        foreach ($candidates as $candidateDate) {
            $key = $candidateDate->format('Y-m-d');
            if (isset($hourlyByDate[$key]) && $hourlyByDate[$key] !== []) {
                $validDays[] = $key;
            }
            if (count($validDays) >= self::MAX_SAMPLED_DAYS) {
                break;
            }
        }

        if (count($validDays) < self::MIN_VALID_DAYS) {
            return self::flatFallback($date, $timezone, $summerKwhPerMonth, $winterKwhPerMonth);
        }

        return self::averageHalfHourly($validDays, $hourlyByDate);
    }

    /** @return array<string, array<int, float>> Y-m-d => [hour => kWh], only hours with a real (non-null) usage_kwh reading */
    private static function groupHourlyByDate(array $historicRows, DateTimeZone $timezone): array
    {
        $byDate = [];
        foreach ($historicRows as $row) {
            if ($row['usage_kwh'] === null) {
                continue;
            }
            $local = $row['from']->setTimezone($timezone);
            $byDate[$local->format('Y-m-d')][(int) $local->format('G')] = $row['usage_kwh'];
        }
        return $byDate;
    }

    private static function dayType(DateTimeImmutable $date): string
    {
        return match ((int) $date->format('N')) {
            6 => 'saturday',
            7 => 'sunday',
            default => 'weekday',
        };
    }

    /**
     * The same ISO week number as $date, in each previous ISO year that has any usage
     * history at all — nearest-year-first — filtered to $dayType. Stops once a candidate
     * week falls entirely before the earliest date with any usage data, rather than
     * searching back indefinitely.
     *
     * @return DateTimeImmutable[]
     */
    private static function tier1Candidates(DateTimeImmutable $date, DateTimeZone $timezone, string $dayType, array $hourlyByDate): array
    {
        if (!$hourlyByDate) {
            return [];
        }
        $earliestKey = min(array_keys($hourlyByDate));
        $isoYear = (int) $date->format('o');
        $isoWeek = (int) $date->format('W');

        $candidates = [];
        for ($yearsBack = 1; $yearsBack <= 50; $yearsBack++) {
            $weekMonday = self::isoWeekMonday($isoYear - $yearsBack, $isoWeek, $timezone);
            $weekSunday = $weekMonday->modify('+6 days');
            if ($weekSunday->format('Y-m-d') < $earliestKey) {
                break; // this whole candidate week, and every earlier year, predates all known history
            }
            for ($offset = 0; $offset <= 6; $offset++) {
                $candidateDate = $weekMonday->modify("+$offset days");
                if (self::dayType($candidateDate) === $dayType) {
                    $candidates[] = $candidateDate;
                }
            }
        }
        return $candidates;
    }

    /** Monday of ISO week $isoWeek in ISO year $isoYear — computed arithmetically (Jan 4th is always in ISO week 1) rather than relying on ISO week date string parsing. */
    private static function isoWeekMonday(int $isoYear, int $isoWeek, DateTimeZone $timezone): DateTimeImmutable
    {
        $jan4 = new DateTimeImmutable("$isoYear-01-04", $timezone);
        $jan4Dow = (int) $jan4->format('N');
        $week1Monday = $jan4->modify('-' . ($jan4Dow - 1) . ' days');
        return $week1Monday->modify('+' . (($isoWeek - 1) * 7) . ' days');
    }

    /** The most recent TIER2_LOOKBACK_DAYS days before $date, most-recent-first, filtered to $dayType. @return DateTimeImmutable[] */
    private static function tier2Candidates(DateTimeImmutable $date, DateTimeZone $timezone, string $dayType): array
    {
        $candidates = [];
        for ($daysBack = 1; $daysBack <= self::TIER2_LOOKBACK_DAYS; $daysBack++) {
            $candidateDate = $date->modify("-$daysBack days");
            if (self::dayType($candidateDate) === $dayType) {
                $candidates[] = $candidateDate;
            }
        }
        return $candidates;
    }

    /** @param string[] $validDays Y-m-d keys, already confirmed present in $hourlyByDate. @return float[] 48 half-hourly values */
    private static function averageHalfHourly(array $validDays, array $hourlyByDate): array
    {
        $result = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $readings = [];
            foreach ($validDays as $day) {
                if (isset($hourlyByDate[$day][$hour])) {
                    $readings[] = $hourlyByDate[$day][$hour];
                }
            }
            $halfHourly = $readings ? (array_sum($readings) / count($readings)) / 2 : 0.0;
            $result[$hour * 2] = $halfHourly;
            $result[$hour * 2 + 1] = $halfHourly;
        }
        return $result;
    }

    /**
     * Fewer than MIN_VALID_DAYS sampled days actually had data — spread that date's daily
     * estimate (from the *existing* UsageEstimator, so the summer/winter settings stay the
     * single source of truth for "how much does this house use") flat across 8am-20:00
     * only; zero elsewhere. A deliberately simple placeholder used only during the
     * bootstrap period or a genuine data gap, not a real usage shape.
     */
    private static function flatFallback(DateTimeImmutable $date, DateTimeZone $timezone, float $summerKwhPerMonth, float $winterKwhPerMonth): array
    {
        $dailyKwh = UsageEstimator::estimateDailyKwh($summerKwhPerMonth, $winterKwhPerMonth, $date, $timezone, []);
        $daytimeHalfHours = (20 - 8) * 2; // 8am-20:00
        $perHalfHour = $daytimeHalfHours > 0 ? $dailyKwh / $daytimeHalfHours : 0.0;

        $result = array_fill(0, 48, 0.0);
        for ($i = 8 * 2; $i < 20 * 2; $i++) {
            $result[$i] = $perHalfHour;
        }
        return $result;
    }
}
