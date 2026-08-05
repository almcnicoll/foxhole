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
    // rate_slots gained a second price column (export) and dropped the old
    // single `rate_pence` column. The table is always fully replaced on every
    // fetch anyway (see saveRateSlots) — nothing worth migrating — so on the
    // old schema just drop and recreate rather than building a migration
    // system for one rename.
    $hasOldSchema = (int) $pdo->query("SELECT COUNT(*) FROM pragma_table_info('rate_slots') WHERE name = 'rate_pence'")->fetchColumn();
    if ($hasOldSchema > 0) {
        $pdo->exec('DROP TABLE rate_slots');
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_slots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slot_from TEXT NOT NULL,
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
    // Disposable, replace-on-every-fetch — same pattern as rate_slots, see saveSolarForecast().
    $pdo->exec('CREATE TABLE IF NOT EXISTS solar_forecast (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slot_from TEXT NOT NULL,
        slot_to TEXT NOT NULL,
        watt_hours INTEGER NOT NULL,
        fetched_at TEXT NOT NULL
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

    return $pdo;
}

function getSetting(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value !== false ? $value : $default;
}

function setSetting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute(['key' => $key, 'value' => $value]);
}

/**
 * @param array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, rate: float}> $importSlots
 * @param ?array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, rate: float}> $exportSlots
 *        same length/order as $importSlots, or null if export prices couldn't be resolved this run
 *        (stored as NULL per row rather than blocking the import/schedule/push path — see Runner.php)
 */
function saveRateSlots(array $importSlots, ?array $exportSlots, DateTimeImmutable $fetchedAt): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM rate_slots');
    $stmt = $pdo->prepare('INSERT INTO rate_slots (slot_from, slot_to, import_rate_pence, export_rate_pence, fetched_at) VALUES (?, ?, ?, ?, ?)');
    foreach ($importSlots as $i => $slot) {
        $stmt->execute([
            $slot['from']->format(DATE_ATOM),
            $slot['to']->format(DATE_ATOM),
            $slot['rate'],
            $exportSlots[$i]['rate'] ?? null,
            $fetchedAt->format(DATE_ATOM),
        ]);
    }
    $pdo->commit();
}

/** @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, import_rate: float, export_rate: ?float, fetched_at: DateTimeImmutable}> */
function getLatestRateSlots(): array
{
    $rows = db()->query('SELECT * FROM rate_slots ORDER BY slot_from ASC')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($row) => [
        'from' => new DateTimeImmutable($row['slot_from']),
        'to' => new DateTimeImmutable($row['slot_to']),
        'import_rate' => (float) $row['import_rate_pence'],
        'export_rate' => $row['export_rate_pence'] !== null ? (float) $row['export_rate_pence'] : null,
        'fetched_at' => new DateTimeImmutable($row['fetched_at']),
    ], $rows);
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

/** Schedules are date-linked (see getScheduleForDate) — anything before today can never be spliced against again. */
function pruneOldSchedules(string $today): void
{
    db()->prepare('DELETE FROM schedule_groups WHERE for_date < ?')->execute([$today]);
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
