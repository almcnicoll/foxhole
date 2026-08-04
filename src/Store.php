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
    $pdo->exec('CREATE TABLE IF NOT EXISTS rate_slots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slot_from TEXT NOT NULL,
        slot_to TEXT NOT NULL,
        rate_pence REAL NOT NULL,
        fetched_at TEXT NOT NULL
    )');
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
        pushed_at TEXT NOT NULL
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

function setSetting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (:key, :value)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute(['key' => $key, 'value' => $value]);
}

/** @param array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, rate: float}> $slots */
function saveRateSlots(array $slots, DateTimeImmutable $fetchedAt): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM rate_slots');
    $stmt = $pdo->prepare('INSERT INTO rate_slots (slot_from, slot_to, rate_pence, fetched_at) VALUES (?, ?, ?, ?)');
    foreach ($slots as $slot) {
        $stmt->execute([
            $slot['from']->format(DATE_ATOM),
            $slot['to']->format(DATE_ATOM),
            $slot['rate'],
            $fetchedAt->format(DATE_ATOM),
        ]);
    }
    $pdo->commit();
}

/** @return array<int, array{from: DateTimeImmutable, to: DateTimeImmutable, rate: float, fetched_at: DateTimeImmutable}> */
function getLatestRateSlots(): array
{
    $rows = db()->query('SELECT * FROM rate_slots ORDER BY slot_from ASC')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($row) => [
        'from' => new DateTimeImmutable($row['slot_from']),
        'to' => new DateTimeImmutable($row['slot_to']),
        'rate' => (float) $row['rate_pence'],
        'fetched_at' => new DateTimeImmutable($row['fetched_at']),
    ], $rows);
}

/** @param array $groups shape produced by ScheduleBuilder: enable/startHour/.../fdPwr */
function saveSchedule(string $forDate, array $groups, DateTimeImmutable $pushedAt): void
{
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM schedule_groups');
    $stmt = $pdo->prepare('INSERT INTO schedule_groups
        (for_date, start_hour, start_minute, end_hour, end_minute, work_mode, min_soc_on_grid, fd_soc, fd_pwr, pushed_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($groups as $group) {
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
            $pushedAt->format(DATE_ATOM),
        ]);
    }
    $pdo->commit();
}

/** @return array{for_date: ?string, pushed_at: ?DateTimeImmutable, groups: array} */
function getLatestSchedule(): array
{
    $rows = db()->query('SELECT * FROM schedule_groups ORDER BY start_hour ASC, start_minute ASC')->fetchAll(PDO::FETCH_ASSOC);
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
    ];
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
