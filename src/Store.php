<?php

const DEFAULT_SYSTEM_PASSWORD = 'foxhole';

/**
 * $overridePath is "sticky": pass it once (e.g. from a test, before any other
 * Store function runs) and every subsequent no-arg db() call — including ones
 * inside getSetting()/saveRateSlots()/etc — reuses that same connection. This
 * is what lets tests point the whole module at a throwaway file instead of
 * truncating the real database.
 */
function db(?string $overridePath = null): PDO
{
    static $pdo = null;
    static $path = null;
    $path ??= __DIR__ . '/../data/scheduler.sqlite';

    if ($overridePath !== null) {
        $path = $overridePath;
        $pdo = null;
    }
    if ($pdo !== null) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');
    // rate_slots (this table's previous name) was disposable by design — replaced
    // wholesale on every fetch, never real history (see CLAUDE.md's old "Data storage"
    // writeup). GitHub issue #4 replaced it with price_slots: permanent, upserted by
    // slot_from, never wholesale-replaced. Nothing in the old table was ever worth
    // migrating (it only ever held the latest fetch), so it's just dropped outright —
    // same guarded-DROP-and-recreate pattern already used twice for this table's prior
    // schema changes, just this time retiring the table itself rather than a column.
    $hasOldRateSlots = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'rate_slots'")->fetchColumn();
    if ($hasOldRateSlots > 0) {
        $pdo->exec('DROP TABLE rate_slots');
    }
    // Permanent, unlike the old rate_slots — see Store::upsertPriceSlots()/
    // getPriceSlotsFrom() and CLAUDE.md's "Date-time-aware scheduling". slot_from is the
    // natural primary key: each half-hour instant is only ever fetched once, re-fetched
    // with (hopefully) the same value, or has its export leg filled in later.
    $pdo->exec('CREATE TABLE IF NOT EXISTS price_slots (
        slot_from TEXT PRIMARY KEY,
        slot_to TEXT NOT NULL,
        import_rate_pence REAL NOT NULL,
        export_rate_pence REAL,
        fetched_at TEXT NOT NULL
    )');
    // Same disposable-table story as rate_slots above: schedule_groups gained an
    // `explanation` column, so drop and recreate on the old schema rather than migrate.
    // (Unlike rate_slots' rename, this is a pure add, so — unlike the check above —
    // "column missing" alone doesn't distinguish old-schema from no-table-yet; check
    // both explicitly.)
    $groupsTableExists = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'schedule_groups'")->fetchColumn() > 0;
    $hasExplanationColumn = (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('schedule_groups') WHERE name = 'explanation'")->fetchColumn() > 0;
    if ($groupsTableExists && !$hasExplanationColumn) {
        $pdo->exec('DROP TABLE schedule_groups');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS schedule_groups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        for_date TEXT NOT NULL,
        start_hour INTEGER NOT NULL,
        start_minute INTEGER NOT NULL,
        end_hour INTEGER NOT NULL,
        end_minute INTEGER NOT NULL,
        work_mode TEXT NOT NULL,
        min_soc_on_grid INTEGER NOT NULL,
        fd_soc INTEGER NOT NULL,
        fd_pwr INTEGER NOT NULL,
        explanation TEXT,
        pushed_at TEXT NOT NULL
    )');
    // One day-level summary sentence per known date — replaces the old single global
    // `schedule_summary` setting, which only ever fit a single "target date" per run.
    // Upserted per date (see upsertScheduleSummary()), pruned alongside schedule_groups.
    $pdo->exec('CREATE TABLE IF NOT EXISTS schedule_summaries (
        for_date TEXT PRIMARY KEY,
        summary TEXT NOT NULL
    )');
    // Disposable, replace-on-every-fetch (unlike price_slots above, this stays a
    // latest-fetch-only cache — see saveSolarForecast()).
    $pdo->exec('CREATE TABLE IF NOT EXISTS solar_forecast (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slot_from TEXT NOT NULL,
        slot_to TEXT NOT NULL,
        watt_hours INTEGER NOT NULL,
        fetched_at TEXT NOT NULL
    )');
    // Deliberately NOT disposable, unlike every table above — this is a real accumulating
    // history, kept indefinitely (see HistoryFetcher.php and CLAUDE.md's "Generation
    // history" section). One row per local clock hour, keyed by its own start instant, so
    // a generation backfill and a forecast capture landing at different times both upsert
    // the same row without clobbering each other's column — see upsertHistoricGeneration()/
    // upsertHistoricForecast() below. A separate table from rate_slots/solar_forecast on
    // purpose, even though it overlaps in spirit with solar_forecast — those stay
    // latest-fetch-only for the dashboard's "what's the plan right now" view; this table
    // answers a different question ("what actually happened, over time") and duplicating
    // the data is cheaper than making solar_forecast serve both jobs.
    $pdo->exec('CREATE TABLE IF NOT EXISTS historic_generation (
        slot_from TEXT PRIMARY KEY,
        slot_to TEXT NOT NULL,
        generation_kwh REAL,
        forecast_kwh REAL,
        updated_at TEXT NOT NULL
    )');
    // One row per (date, kind) — a date-linked exception to the normal schedule, not
    // history, so it's fine as a plain upsertable table rather than the disposable
    // replace-all pattern rate_slots/schedule_groups use.
    $pdo->exec("CREATE TABLE IF NOT EXISTS overrides (
        for_date TEXT NOT NULL,
        kind TEXT NOT NULL,
        event_start TEXT NOT NULL,
        event_end TEXT NOT NULL,
        prep_start TEXT,
        prep_end TEXT,
        PRIMARY KEY (for_date, kind)
    )");
    // Deliberately not disposable, same reasoning as historic_generation — this table
    // exists specifically to answer "what did we actually send/receive, over time," so
    // rows are never deleted. Only the request_body/response_body columns get redacted
    // (set to NULL) once they're more than 7 days old — see saveApiLogEntry() — leaving
    // called_at/endpoint/status_code as a permanent, storage-cheap call history. See
    // CLAUDE.md's "API call log".
    $pdo->exec('CREATE TABLE IF NOT EXISTS api_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        called_at TEXT NOT NULL,
        endpoint TEXT NOT NULL,
        request_body TEXT,
        status_code INTEGER,
        response_body TEXT
    )');

    return $pdo;
}

function getSetting(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

/**
 * Battery hardware specs (capacity, max charge/discharge power, SoC floors) — moved out
 * of config.php's 'battery' section into the settings table (see CLAUDE.md's "Battery
 * config moved to settings") so they're editable from settings.php without a deploy.
 * The trigger was `max_discharge_kw` specifically being left at a too-conservative value
 * (mirrored from `max_charge_kw`) with no easy way to notice or fix it short of editing
 * config.php directly.
 *
 * @param array $legacyConfig config.php's old 'battery' array, if the caller has one —
 *        read once per key as a migration fallback for any value that hasn't been saved
 *        via settings.php yet, same pattern as foxess_device_sns' fallback to the old
 *        singular foxess_device_sn key. Once a key is saved via settings.php it's in the
 *        settings table for good and this fallback stops mattering for that key.
 * @return array{capacity_kwh: float, max_charge_kw: float, max_discharge_kw: float, min_soc_on_grid: int, reserve_soc: int}
 */
function getBatteryConfig(array $legacyConfig = []): array
{
    $defaults = ['capacity_kwh' => 10.0, 'max_charge_kw' => 3.0, 'max_discharge_kw' => 3.0, 'min_soc_on_grid' => 15, 'reserve_soc' => 15];
    $result = [];
    foreach ($defaults as $key => $default) {
        $fallback = $legacyConfig[$key] ?? $default;
        $stored = getSetting("battery_{$key}");
        $result[$key] = $stored !== null ? (float) $stored : (float) $fallback;
    }
    $result['min_soc_on_grid'] = (int) $result['min_soc_on_grid'];
    $result['reserve_soc'] = (int) $result['reserve_soc'];
    return $result;
}

function setSetting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute(['key' => $key, 'value' => $value]);
}

/**
 * Upserts (never wholesale-replaces) every fetched slot into the permanent price_slots
 * table — see CLAUDE.md's "Date-time-aware scheduling" (GitHub issue #4). Each slot's
 * `slot_from` is its natural primary key, so re-fetching a slot already known just
 * refreshes it in place rather than duplicating a row.
 *
 * @param array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, rate: float}> $importSlots
 * @param ?array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, rate: float}> $exportSlots
 *        same length/order as $importSlots, or null if export prices couldn't be resolved this run.
 *        Import always overwrites; export only overwrites when non-null — a run that
 *        couldn't resolve export prices must never clobber an export rate a previous run
 *        already recorded for the same slot (COALESCE against the existing value), same
 *        non-clobbering principle as historic_generation's independently-written columns.
 */
function upsertPriceSlots(array $importSlots, ?array $exportSlots, DateTimeImmutable $fetchedAt): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO price_slots (slot_from, slot_to, import_rate_pence, export_rate_pence, fetched_at)
        VALUES (:slot_from, :slot_to, :import_rate_pence, :export_rate_pence, :fetched_at)
        ON CONFLICT(slot_from) DO UPDATE SET
            slot_to = excluded.slot_to,
            import_rate_pence = excluded.import_rate_pence,
            export_rate_pence = COALESCE(excluded.export_rate_pence, export_rate_pence),
            fetched_at = excluded.fetched_at');
    foreach ($importSlots as $i => $slot) {
        $stmt->execute([
            'slot_from' => $slot['from']->format(DATE_ATOM),
            'slot_to' => $slot['to']->format(DATE_ATOM),
            'import_rate_pence' => $slot['rate'],
            'export_rate_pence' => $exportSlots[$i]['rate'] ?? null,
            'fetched_at' => $fetchedAt->format(DATE_ATOM),
        ]);
    }
    $pdo->commit();
}

/**
 * Every known slot from $from onward, ascending — the single query that replaces the old
 * getLatestRateSlots() everywhere: pass local midnight today for "what should the
 * dashboard/Schedulers page show", pass "now" for "what's actually left to schedule".
 *
 * $from is normalised to UTC before comparison — every stored slot_from is UTC (upsertPriceSlots()
 * only ever writes import slots' own timestamps, and OctopusClient always returns UTC
 * DateTimeImmutable objects), so a $from formatted in local time would compare its
 * "+01:00"/"+00:00" offset against stored "+00:00" values as plain TEXT, which is only
 * chronologically correct when every value shares the same offset. Confirmed live during
 * BST: local midnight ("...T00:00:00+01:00") sorted as *later* than the UTC-stored slot
 * for that exact same instant ("...T23:00:00+00:00" the previous calendar day), silently
 * dropping the first hour of "today" from every query. Normalising both sides to UTC
 * fixes this without needing every stored value to change.
 *
 * @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, import_rate: float, export_rate: ?float, fetched_at: DateTimeImmutable}>
 */
function getPriceSlotsFrom(DateTimeImmutable $from): array
{
    $stmt = db()->prepare('SELECT * FROM price_slots WHERE slot_from >= ? ORDER BY slot_from ASC');
    $stmt->execute([$from->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM)]);
    return array_map(fn($row) => [
        'from' => new DateTimeImmutable($row['slot_from']),
        'to' => new DateTimeImmutable($row['slot_to']),
        'import_rate' => (float) $row['import_rate_pence'],
        'export_rate' => $row['export_rate_pence'] !== null ? (float) $row['export_rate_pence'] : null,
        'fetched_at' => new DateTimeImmutable($row['fetched_at']),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/** For the "rates last fetched at X" dashboard line — null if nothing has ever been fetched. */
function getLatestPriceFetchedAt(): ?DateTimeImmutable
{
    $value = db()->query('SELECT MAX(fetched_at) FROM price_slots')->fetchColumn();
    return $value !== null ? new DateTimeImmutable($value) : null;
}

/** The latest slot_to across every known price slot — the true end of current pricing knowledge, or null if none. */
function getLatestPriceHorizon(): ?DateTimeImmutable
{
    $value = db()->query('SELECT MAX(slot_to) FROM price_slots')->fetchColumn();
    return $value !== null ? new DateTimeImmutable($value) : null;
}

/**
 * @param array $groups shape produced by ScheduleBuilder: enable/startHour/.../fdPwr
 * @param string[] $explanations same length/order as $groups — see ScheduleBuilder::build()
 */
function saveSchedule(string $forDate, array $groups, array $explanations, DateTimeImmutable $pushedAt): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM schedule_groups WHERE for_date = ?')->execute([$forDate]);
    $stmt = $pdo->prepare('INSERT INTO schedule_groups
        (for_date, start_hour, start_minute, end_hour, end_minute, work_mode, min_soc_on_grid, fd_soc, fd_pwr, explanation, pushed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($groups as $i => $group) {
        $stmt->execute([
            $forDate,
            $group['startHour'],
            $group['startMinute'],
            $group['endHour'],
            $group['endMinute'],
            $group['workMode'],
            $group['minSocOnGrid'],
            $group['fdSoc'],
            $group['fdPwr'],
            $explanations[$i] ?? null,
            $pushedAt->format(DATE_ATOM),
        ]);
    }
    $pdo->commit();
}

/**
 * One row set per calendar date — needed so a run computing tomorrow's plan doesn't wipe
 * out today's still-in-effect one (see CLAUDE.md's "Today/Tomorrow" note). `saveSchedule()`
 * only replaces the given date's rows, so at least today's and tomorrow's plans can be
 * stored at once; call pruneOldSchedules() to drop dates that have fully passed.
 *
 * @return array{for_date: ?string, pushed_at: ?DateTimeImmutable, groups: array, explanations: string[]}
 *         `groups` intentionally excludes `explanation` — it's what the no-op-push diff
 *         compares, and that check must stay about what's actually sent to FoxESS, not
 *         wording that can change without the schedule itself changing.
 */
function getScheduleForDate(string $forDate): array
{
    $stmt = db()->prepare('SELECT * FROM schedule_groups WHERE for_date = ? ORDER BY start_hour ASC, start_minute ASC');
    $stmt->execute([$forDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $groups = array_map(fn($row) => [
        'enable' => 1,
        'startHour' => (int) $row['start_hour'],
        'startMinute' => (int) $row['start_minute'],
        'endHour' => (int) $row['end_hour'],
        'endMinute' => (int) $row['end_minute'],
        'workMode' => $row['work_mode'],
        'minSocOnGrid' => (int) $row['min_soc_on_grid'],
        'fdSoc' => (int) $row['fd_soc'],
        'fdPwr' => (int) $row['fd_pwr'],
    ], $rows);

    return [
        'for_date' => $rows[0]['for_date'] ?? null,
        'pushed_at' => isset($rows[0]) ? new DateTimeImmutable($rows[0]['pushed_at']) : null,
        'groups' => $groups,
        'explanations' => array_column($rows, 'explanation'),
    ];
}

/** Schedules are date-linked (see getScheduleForDate) — anything before today can never be pushed again. */
function pruneOldSchedules(string $today): void
{
    db()->prepare('DELETE FROM schedule_groups WHERE for_date < ?')->execute([$today]);
    db()->prepare('DELETE FROM schedule_summaries WHERE for_date < ?')->execute([$today]);
}

/** One day-level summary sentence per date — replaces the old single global `schedule_summary` setting. */
function upsertScheduleSummary(string $forDate, string $summary): void
{
    $stmt = db()->prepare('INSERT INTO schedule_summaries (for_date, summary) VALUES (:for_date, :summary)
        ON CONFLICT(for_date) DO UPDATE SET summary = excluded.summary');
    $stmt->execute(['for_date' => $forDate, 'summary' => $summary]);
}

function getScheduleSummary(string $forDate): ?string
{
    $stmt = db()->prepare('SELECT summary FROM schedule_summaries WHERE for_date = ?');
    $stmt->execute([$forDate]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : null;
}

/**
 * @param array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, watt_hours: int}> $slots
 *        SolarForecastClient::fetchForecast()'s output — replaced whole on every fetch, same
 *        disposable-table pattern as rate_slots (see CLAUDE.md's "Data storage" section).
 */
function saveSolarForecast(array $slots, DateTimeImmutable $fetchedAt): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM solar_forecast');
    $stmt = $pdo->prepare('INSERT INTO solar_forecast (slot_from, slot_to, watt_hours, fetched_at) VALUES (?, ?, ?, ?)');
    foreach ($slots as $slot) {
        $stmt->execute([
            $slot['from']->format(DATE_ATOM),
            $slot['to']->format(DATE_ATOM),
            $slot['watt_hours'],
            $fetchedAt->format(DATE_ATOM),
        ]);
    }
    $pdo->commit();
}

/** @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, watt_hours: int, fetched_at: DateTimeImmutable}> */
function getLatestSolarForecast(): array
{
    $rows = db()->query('SELECT * FROM solar_forecast ORDER BY slot_from ASC')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($row) => [
        'from' => new DateTimeImmutable($row['slot_from']),
        'to' => new DateTimeImmutable($row['slot_to']),
        'watt_hours' => (int) $row['watt_hours'],
        'fetched_at' => new DateTimeImmutable($row['fetched_at']),
    ], $rows);
}

/**
 * Upserts one local hour's actual generation (summed across every configured device —
 * see HistoryFetcher), touching only generation_kwh/slot_to/updated_at. Any forecast_kwh
 * already stored for the same hour (see upsertHistoricForecast()) is left exactly as-is —
 * the two are written independently, on different schedules, by different callers.
 */
function upsertHistoricGeneration(DateTimeImmutable $slotFrom, DateTimeImmutable $slotTo, float $kwh, DateTimeImmutable $updatedAt): void
{
    $stmt = db()->prepare('INSERT INTO historic_generation (slot_from, slot_to, generation_kwh, updated_at) VALUES (:from, :to, :kwh, :updated_at)
        ON CONFLICT(slot_from) DO UPDATE SET slot_to = excluded.slot_to, generation_kwh = excluded.generation_kwh, updated_at = excluded.updated_at');
    $stmt->execute([
        'from' => $slotFrom->format(DATE_ATOM),
        'to' => $slotTo->format(DATE_ATOM),
        'kwh' => $kwh,
        'updated_at' => $updatedAt->format(DATE_ATOM),
    ]);
}

/**
 * Upserts one bucket's solar forecast, touching only forecast_kwh/slot_to/updated_at —
 * mirrors upsertHistoricGeneration() above but never overwrites generation_kwh. Called
 * prospectively, the moment a forecast fetch covers that hour (Runner.php) — there's no
 * backfill equivalent for forecasts, since Forecast.Solar only offers *historic* forecasts
 * on a paid tier (see HistoryFetcher.php's doc comment).
 */
function upsertHistoricForecast(DateTimeImmutable $slotFrom, DateTimeImmutable $slotTo, float $kwh, DateTimeImmutable $updatedAt): void
{
    $stmt = db()->prepare('INSERT INTO historic_generation (slot_from, slot_to, forecast_kwh, updated_at) VALUES (:from, :to, :kwh, :updated_at)
        ON CONFLICT(slot_from) DO UPDATE SET slot_to = excluded.slot_to, forecast_kwh = excluded.forecast_kwh, updated_at = excluded.updated_at');
    $stmt->execute([
        'from' => $slotFrom->format(DATE_ATOM),
        'to' => $slotTo->format(DATE_ATOM),
        'kwh' => $kwh,
        'updated_at' => $updatedAt->format(DATE_ATOM),
    ]);
}

/**
 * @return array{earliest: ?DateTimeImmutable, latest: ?DateTimeImmutable} slot_from range
 *         of rows with a real (non-null) generation reading — forecast-only rows don't
 *         count, since HistoryFetcher uses this purely to know where to resume backfilling/
 *         catching up actual generation, not forecasts.
 */
function getHistoricGenerationBounds(): array
{
    $row = db()->query('SELECT MIN(slot_from) AS earliest, MAX(slot_from) AS latest FROM historic_generation WHERE generation_kwh IS NOT NULL')->fetch(PDO::FETCH_ASSOC);
    return [
        'earliest' => $row['earliest'] !== null ? new DateTimeImmutable($row['earliest']) : null,
        'latest' => $row['latest'] !== null ? new DateTimeImmutable($row['latest']) : null,
    ];
}

/**
 * @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, generation_kwh: ?float, forecast_kwh: ?float}>
 *         Raw hourly rows in [$from, $to), ascending. Aggregation into day/week/month/year
 *         buckets (summed, not averaged — see history.php) happens in PHP on top of this,
 *         same as every other table in this app; even a few years of hourly rows is a
 *         trivial amount of data for SQLite/PHP to sum unaggregated.
 */
function getHistoricGeneration(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $stmt = db()->prepare('SELECT * FROM historic_generation WHERE slot_from >= :from AND slot_from < :to ORDER BY slot_from ASC');
    $stmt->execute(['from' => $from->format(DATE_ATOM), 'to' => $to->format(DATE_ATOM)]);
    return array_map(fn($row) => [
        'from' => new DateTimeImmutable($row['slot_from']),
        'to' => new DateTimeImmutable($row['slot_to']),
        'generation_kwh' => $row['generation_kwh'] !== null ? (float) $row['generation_kwh'] : null,
        'forecast_kwh' => $row['forecast_kwh'] !== null ? (float) $row['forecast_kwh'] : null,
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * $kind is 'fill_your_boots' or 'power_down'. $eventStart/$eventEnd/$prepStart/$prepEnd
 * are 'H:i' strings (native <input type="time"> format); prep is optional (null = no
 * prep period). Upserts on (for_date, kind) so re-saving the same override just updates it.
 */
function saveOverride(string $forDate, string $kind, string $eventStart, string $eventEnd, ?string $prepStart, ?string $prepEnd): void
{
    $stmt = db()->prepare('INSERT INTO overrides (for_date, kind, event_start, event_end, prep_start, prep_end) VALUES (:for_date, :kind, :event_start, :event_end, :prep_start, :prep_end)
        ON CONFLICT(for_date, kind) DO UPDATE SET event_start = excluded.event_start, event_end = excluded.event_end, prep_start = excluded.prep_start, prep_end = excluded.prep_end');
    $stmt->execute([
        'for_date' => $forDate,
        'kind' => $kind,
        'event_start' => $eventStart,
        'event_end' => $eventEnd,
        'prep_start' => $prepStart,
        'prep_end' => $prepEnd,
    ]);
}

function deleteOverride(string $forDate, string $kind): void
{
    $stmt = db()->prepare('DELETE FROM overrides WHERE for_date = ? AND kind = ?');
    $stmt->execute([$forDate, $kind]);
}

/** @return array<int, array{for_date: string, kind: string, event_start: string, event_end: string, prep_start: ?string, prep_end: ?string}> */
function getOverridesForDate(string $forDate): array
{
    $stmt = db()->prepare('SELECT * FROM overrides WHERE for_date = ? ORDER BY kind');
    $stmt->execute([$forDate]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Overrides are date-linked by design (CLAUDE.md) — anything before today can never match a run again. */
function pruneOldOverrides(string $today): void
{
    db()->prepare('DELETE FROM overrides WHERE for_date < ?')->execute([$today]);
}

/**
 * Logs one FoxESS API call — called from FoxessClient::post(), the single choke point
 * every FoxESS request goes through (scheduler push/get, real/query, report/query), so
 * no individual call site has to remember to log itself. $statusCode is the HTTP status,
 * or null for a transport-level failure (no HTTP response at all) — see FoxessClient.php.
 *
 * Redacts (nulls out) request_body/response_body on any existing row older than 7 days
 * *before* inserting the new one, rather than on a separate cron/cleanup schedule — see
 * CLAUDE.md's "API call log" — so the retention rule runs every time this table is
 * touched at all and can't silently stop happening if a separate cleanup job were ever
 * forgotten. Rows themselves are never deleted — see the api_log table comment in db().
 */
function saveApiLogEntry(string $endpoint, ?string $requestBody, ?int $statusCode, ?string $responseBody, DateTimeImmutable $calledAt): void
{
    $pdo = db();
    $cutoff = $calledAt->modify('-7 days')->format(DATE_ATOM);
    $pdo->prepare('UPDATE api_log SET request_body = NULL, response_body = NULL
        WHERE called_at < ? AND (request_body IS NOT NULL OR response_body IS NOT NULL)')->execute([$cutoff]);
    $stmt = $pdo->prepare('INSERT INTO api_log (called_at, endpoint, request_body, status_code, response_body) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$calledAt->format(DATE_ATOM), $endpoint, $requestBody, $statusCode, $responseBody]);
}

/** @return array<int, array{id:int, called_at: DateTimeImmutable, endpoint:string, request_body:?string, status_code:?int, response_body:?string}> most recent first */
function getApiLogEntries(int $limit, int $offset = 0): array
{
    $stmt = db()->prepare('SELECT * FROM api_log ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return array_map(fn($row) => [
        'id' => (int) $row['id'],
        'called_at' => new DateTimeImmutable($row['called_at']),
        'endpoint' => $row['endpoint'],
        'request_body' => $row['request_body'],
        'status_code' => $row['status_code'] !== null ? (int) $row['status_code'] : null,
        'response_body' => $row['response_body'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function countApiLogEntries(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM api_log')->fetchColumn();
}

function verifySystemPassword(string $attempt): bool
{
    $hash = getSetting('system_password_hash');
    if ($hash === null) {
        return hash_equals(DEFAULT_SYSTEM_PASSWORD, $attempt);
    }
    return password_verify($attempt, $hash);
}

function setSystemPassword(string $newPassword): void
{
    setSetting('system_password_hash', password_hash($newPassword, PASSWORD_DEFAULT));
}
