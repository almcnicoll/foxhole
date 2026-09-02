<?php

require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Store.php';

// Signs and sends requests to the FoxESS OpenAPI. Never v0 for the scheduler — community
// reports (foxesscommunity.com) describe v0 scheduler writes corrupting backend state on
// some inverters. scheduler/get and scheduler/enable are both v2 (migrated from v1 — see
// doc/foxess-scheduler-api-migration-plan.md) — v1 is avoided by choice, not just left
// behind: multiple FoxESS community forum reports describe v1 pushes that the cloud API
// accepts (errno 0) but the inverter itself never actually applies, exactly the kind of
// silent, hard-to-detect failure this app can't tolerate for something that controls real
// money and a real battery. v2's own hard cap of 8 groups per scheduler/enable call
// (confirmed live, errno 40257 above that — see CLAUDE.md's "FoxESS scheduler endpoint"
// note) is handled by the caller (Runner.php caps to the soonest `max_scheduler_groups`
// groups before calling pushSchedule() — a later cron run picks up whatever didn't fit,
// since none of them are needed yet), not by this class. scheduler/get/flag and
// scheduler/set/flag deliberately stay on v1, since even the current v3-era community
// reference code keeps the master-switch flag there. See CLAUDE.md for the fuller
// research trail behind these choices.
//
// Requires Store.php (a dependency this class didn't have before) purely so post() can
// log every call via Store::saveApiLogEntry() — see CLAUDE.md's "API call log". This is
// the one exception to this class otherwise having zero persistence concerns of its own.
class FoxessClient
{
    private int $callCount = 0;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $deviceSn,
        private readonly string $baseUrl,
    ) {
    }

    public function callCount(): int
    {
        return $this->callCount;
    }

    /**
     * Push a computed schedule. $groups is the array built by ScheduleBuilder — this
     * app's own internal shape (flat `enable`/`startHour`/.../`fdPwr` fields) never
     * changes regardless of which scheduler API version is actually in use; toV2Groups()
     * below is the one place that translates it to whatever the wire currently expects,
     * so nothing upstream of this class needs to know or care about that.
     *
     * $groups must already be capped to `config.php`'s `foxess.max_scheduler_groups`
     * (default 8) and sorted soonest-first before it reaches here — see Runner.php's
     * `runScheduler()`/`reapplyOverrides()` and `ScheduleBuilder::capToSoonestGroups()`.
     * v2's scheduler/enable hard-rejects a call with more groups than that (confirmed
     * live, errno 40257 — see CLAUDE.md's "FoxESS scheduler endpoint" note); this method
     * doesn't re-check the count itself, since the caller already knows the real absolute
     * ordering (this app's own group shape is dateless hour/minute-of-day, ambiguous
     * across a rolling window that crosses midnight) and doing it again here would risk
     * capping the wrong groups.
     *
     * Clears any existing schedule first — a separate push with an empty `groups` array —
     * before sending the real one. Confirmed live (and via community reports): stale slots
     * from a previous push have been observed persisting and blocking the desired new ones
     * even though this call nominally sends a full replacement `groups` array; an empty
     * array is FoxESS's own documented way to clear everything. Both calls go through
     * post(), so both get logged (see api-log.php) and the existing single-retry-on-
     * transient-failure handling. Deliberately not best-effort: if the clear call itself
     * fails, the whole push fails (same as today when the one push call fails) rather than
     * silently risking the exact stale-slot bug this exists to fix.
     */
    public function pushSchedule(array $groups): array
    {
        $this->post('/op/v2/device/scheduler/enable', [
            'deviceSN' => $this->deviceSn,
            'groups' => [],
        ]);
        $result = $this->post('/op/v2/device/scheduler/enable', [
            'deviceSN' => $this->deviceSn,
            'groups' => $this->toV2Groups($groups),
        ]);

        // Re-assert Mode Scheduler as the active mode if this device supports the flag
        // and it isn't already on — see getSchedulerFlag()'s doc comment for why: a
        // manually-picked WorkMode (e.g. via the FoxESS app) leaves the schedule's own
        // groups untouched but stops the device from actually following them, and
        // pushing new groups alone doesn't turn it back on. Checked-then-set rather than
        // an unconditional write: a no-op skip on unsupported models, and one fewer call
        // against FoxESS's daily quota (see CLAUDE.md's rate-limit notes) on the common
        // case where it's already on.
        //
        // Best-effort, deliberately — the opposite of the clear-then-push calls above.
        // Confirmed live: this call can fail (a wrong request shape returned errno 40257
        // in production before the `enable` int/bool fix above) independently of whether
        // the schedule itself pushed successfully, and the schedule having actually
        // reached the device is the more important of the two outcomes to report — the
        // whole push shouldn't read as failed, and get retried from scratch next run, just
        // because this one follow-up call didn't land. The failure is still surfaced, not
        // swallowed: attached to the return value so pushToDevices() (Runner.php) can log
        // it and put a warning on the dashboard rather than it only ever showing up in the
        // API log.
        $result['_schedulerFlagWarning'] = null;
        try {
            if ($this->getSchedulerFlag() === false) {
                $this->setSchedulerFlag(true);
            }
        } catch (FoxessPushException $e) {
            $result['_schedulerFlagWarning'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Reads the scheduler "master switch" — community-documented as `SegmentedTimedModeEnable`
     * — which is distinct from the schedule's own time-segment groups (pushSchedule()/
     * getSchedule()). When it's off, the inverter runs whatever WorkMode was picked directly
     * (e.g. via the FoxESS app's own work-mode dropdown) and ignores whatever's stored in its
     * schedule, even though the schedule itself is untouched. Confirmed live against this
     * project's own account: `support: true, enable: false` on a device that still had
     * schedule groups from a recent push — the schedule was simply not being followed.
     *
     * Path and field names aren't in FoxESS's own OpenAPI docs in enough detail to confirm
     * independently — taken from a maintained third-party client (`gostonefire/foxess`,
     * Rust), whose source was read directly, and corroborated by community forum reports of
     * the same underlying setting name. Deliberately kept on v1 even though scheduler/get
     * and scheduler/enable have since moved to v2 (see
     * doc/foxess-scheduler-api-migration-plan.md) — every version of the community
     * reference code checked, right up to its current v3-era release, keeps the
     * master-switch flag on v1, so there's no v2/v3 equivalent to move to. Not the v0
     * endpoints known to corrupt state (see CLAUDE.md's "FoxESS scheduler endpoint" note)
     * — treat as reasonably solid, not as fully confirmed as the request-signing/
     * scheduler-push logic elsewhere in this file.
     *
     * @return ?bool null if this device model doesn't support the flag at all
     *         (`support: false`) — nothing this app can or should do about that case.
     */
    public function getSchedulerFlag(): ?bool
    {
        $response = $this->post('/op/v1/device/scheduler/get/flag', ['deviceSN' => $this->deviceSn]);
        if (($response['result']['support'] ?? false) !== true) {
            return null;
        }
        return (bool) ($response['result']['enable'] ?? false);
    }

    /**
     * Writes the scheduler master switch — see getSchedulerFlag(). `enable` is sent as an
     * integer (0/1), not a JSON boolean: confirmed live, a JSON `true`/`false` here gets
     * rejected with errno 40257 ("Parameters do not meet expectations"), even though
     * get/flag's *response* happily returns `enable` as a JSON boolean. Consistent with
     * every other enable-style field already used elsewhere in this file (schedule groups'
     * own `enable` is always an int 1, never a bool) — FoxESS's request parsing apparently
     * isn't as lenient about the two as its response encoding is.
     */
    public function setSchedulerFlag(bool $enable): array
    {
        return $this->post('/op/v1/device/scheduler/set/flag', [
            'deviceSN' => $this->deviceSn,
            'enable' => $enable ? 1 : 0,
        ]);
    }

    /** Read back the currently applied schedule — useful to verify a push landed. */
    public function getSchedule(): array
    {
        return $this->post('/op/v2/device/scheduler/get', ['deviceSN' => $this->deviceSn]);
    }

    /**
     * Translates this app's internal flat group shape (ScheduleBuilder's output —
     * `enable`, `startHour`/`startMinute`/`endHour`/`endMinute`, `workMode`,
     * `minSocOnGrid`, `fdSoc`, `fdPwr`, all top-level) into the v2 wire shape, which
     * nests the three control fields under `extraParam`. `enable`/the time fields/
     * `workMode` stay top-level in v2 — only the reshape changed, not which fields exist.
     * Confirmed against `TonyM1958/FoxESS-Cloud`'s source at the commit where it moved to
     * v2 (`2b6e7fad`, 2025-11-30), and against this project's own live account — see
     * doc/foxess-scheduler-api-migration-plan.md.
     *
     * @param array $groups periodsToGroups()/buildPushWindow()-shaped groups
     */
    private function toV2Groups(array $groups): array
    {
        return array_map(fn(array $g) => [
            'enable' => $g['enable'],
            'startHour' => $g['startHour'],
            'startMinute' => $g['startMinute'],
            'endHour' => $g['endHour'],
            'endMinute' => $g['endMinute'],
            'workMode' => $g['workMode'],
            'extraParam' => [
                'minSocOnGrid' => $g['minSocOnGrid'],
                'fdSoc' => $g['fdSoc'],
                'fdPwr' => $g['fdPwr'],
            ],
        ], $groups);
    }

    /** Low-risk read-only call, useful for testing the signature logic in isolation. */
    public function testSignature(): array
    {
        return $this->post('/op/v1/device/real/query', ['sns' => [$this->deviceSn]]);
    }

    /**
     * Hourly generation (kWh) for one calendar day, per FoxESS's report/query endpoint
     * (dimension=day) — this is the same total FoxESS Cloud's own app charts, not a raw
     * power sample needing manual integration. Returns an array of up to 24 values,
     * index 0 = 00:00-01:00 local (device timezone) through index 23 = 23:00-00:00 —
     * FoxESS computes "day" by the power station's own timezone, per the OpenAPI docs.
     * For "today" the trailing not-yet-elapsed hours may come back as 0 rather than
     * absent — callers should only trust indexes before the current local hour (see
     * HistoryFetcher, which is the one caller of this method).
     *
     * Returns null — not an empty array or a thrown exception — when FoxESS has no
     * report data at all for this day (errno 0, empty/missing result). This is
     * deliberately distinguished from a real error (thrown below, via post()): it's the
     * signal HistoryFetcher's backward backfill uses to detect it's walked back past
     * the device's own history horizon and should stop trying further back.
     *
     * Field name is 'sn' (singular), unlike the scheduler endpoints' 'deviceSN' or
     * real/query's 'sns' array — confirmed against TonyM1958/FoxESS-Cloud (the same
     * community reference CLAUDE.md already leans on for the scheduler endpoint and
     * SoC field names), since FoxESS's own OpenAPI docs are thin here too.
     */
    public function getGenerationReport(int $year, int $month, int $day): ?array
    {
        $response = $this->post('/op/v0/device/report/query', [
            'sn' => $this->deviceSn,
            'dimension' => 'day',
            'variables' => ['generation'],
            'year' => $year,
            'month' => $month,
            'day' => $day,
        ]);
        $result = $response['result'] ?? [];
        if (!$result) {
            return null;
        }
        foreach ($result as $entry) {
            if (($entry['variable'] ?? null) === 'generation') {
                return array_map(fn($v) => $v !== null ? (float) $v : 0.0, $entry['values'] ?? []);
            }
        }
        return null;
    }

    /**
     * Hourly household consumption (kWh) for one calendar day — same report/query endpoint
     * and shape as getGenerationReport() above, just the `loads` variable instead of
     * `generation`. Added for GitHub issue #5 ("Modelling scheduler") — see
     * HistoryFetcher.php and HalfHourlyUsageEstimator. Deliberately its own method/own API
     * call rather than folding `loads` into getGenerationReport()'s existing `variables`
     * array: generation history is a real, permanent, never-re-fetched record, so this
     * stays zero-risk to that already-working path rather than sharing a code path with it.
     *
     * Known caveat, not confirmed further: community reports describe the OpenAPI `loads`
     * variable undercounting versus the FoxESS mobile app's own "load today" register (one
     * reported case: ~10.1 vs ~12.5 kWh) — the OpenAPI implementation doesn't appear to
     * read the same register the app does. Still the best available source through this
     * API; treat as directionally useful, not exact.
     */
    public function getUsageReport(int $year, int $month, int $day): ?array
    {
        $response = $this->post('/op/v0/device/report/query', [
            'sn' => $this->deviceSn,
            'dimension' => 'day',
            'variables' => ['loads'],
            'year' => $year,
            'month' => $month,
            'day' => $day,
        ]);
        $result = $response['result'] ?? [];
        if (!$result) {
            return null;
        }
        foreach ($result as $entry) {
            if (($entry['variable'] ?? null) === 'loads') {
                return array_map(fn($v) => $v !== null ? (float) $v : 0.0, $entry['values'] ?? []);
            }
        }
        return null;
    }

    /**
     * Current battery state of charge (0-100), or null if this device didn't report one.
     * Field name per community reference implementations: 'SoC' for a single battery,
     * 'SoC_1' for the first battery on a multi-battery inverter — not in FoxESS's own
     * docs, so both are requested and whichever is present wins. See CLAUDE.md.
     */
    public function getBatterySoc(): ?float
    {
        $response = $this->post('/op/v1/device/real/query', [
            'sns' => [$this->deviceSn],
            'variables' => ['SoC', 'SoC_1'],
        ]);
        $datas = $response['result'][0]['datas'] ?? [];
        foreach ($datas as $entry) {
            if (in_array($entry['variable'] ?? null, ['SoC', 'SoC_1'], true)) {
                return (float) $entry['value'];
            }
        }
        return null;
    }

    /**
     * Protected, not private, specifically so tests can subclass FoxessClient and override
     * this to intercept calls (e.g. counting/recording pushSchedule()'s two-step clear+push)
     * without touching the network — see tests/self_check.php.
     */
    protected function post(string $path, array $body, bool $isRetry = false): array
    {
        $this->callCount++;

        // Timestamp+signature generated fresh for this attempt — a retry gets its own pair.
        $timestamp = (string) round(microtime(true) * 1000);
        $signature = md5($path . "\r\n" . $this->apiKey . "\r\n" . $timestamp);

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Token: ' . $this->apiKey,
                'Timestamp: ' . $timestamp,
                'Signature: ' . $signature,
                'Lang: en',
                'Content-Type: application/json',
            ],
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Logged here, the one choke point every FoxESS request goes through, rather than
        // at each call site — see Store::saveApiLogEntry(). A retried attempt logs as two
        // rows (one per real network round-trip), and a transport-level failure (no HTTP
        // response at all) logs status_code as null with the cURL error in place of a
        // response body — there genuinely isn't a status code to record for that case.
        saveApiLogEntry(
            $path,
            json_encode($body),
            $errno !== 0 ? null : $status,
            $errno !== 0 ? "cURL error: $error" : $raw,
            new DateTimeImmutable('now'),
        );

        if ($errno !== 0) {
            // Single retry on transient network failure, per spec §12 — no backoff, no further retries.
            if (!$isRetry) {
                return $this->post($path, $body, true);
            }
            throw new FoxessPushException("cURL error calling FoxESS $path: $error");
        }
        if ($status !== 200) {
            throw new FoxessPushException("FoxESS $path returned HTTP $status: $raw");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new FoxessPushException("FoxESS $path returned non-JSON response: $raw");
        }
        // FoxESS wraps results as {errno, msg, result}; errno 0 = success.
        // errno 40256 specifically means a missing/invalid auth header — check
        // Token/Timestamp/Signature are all present and freshly generated.
        if (($decoded['errno'] ?? 0) !== 0) {
            throw new FoxessPushException(sprintf(
                'FoxESS %s error %s: %s',
                $path,
                $decoded['errno'] ?? '?',
                $decoded['msg'] ?? 'unknown',
            ));
        }

        return $decoded;
    }
}
