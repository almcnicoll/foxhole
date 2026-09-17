<?php

require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Store.php';

/**
 * Talks to Octopus's GraphQL APIs to discover "Power down" (Saving Sessions: use less,
 * get paid) and "Fill your boots" (Free Electricity/Power Up: use more, it's free/cheap)
 * opt-in sessions — the same two kinds `overrides`/`ScheduleBuilder::overrideModesFor()`
 * already model. Deliberately scoped to just these ad-hoc, announced events, not Octopus's
 * separate "Weekend Happy Hour" mechanism (booking one future slot ahead of time, a
 * different API shape) — out of scope for this feature by explicit decision.
 *
 * **Two genuinely different mechanisms, one per kind** — discovered by reading a real,
 * working third-party project (`mrdanielmitchell/automator-octopus-energy`, a Cloudflare
 * Worker that auto-joins Saving Sessions) after two earlier live spikes against the wrong
 * endpoint kept coming up empty:
 *
 * - **Power down** uses `api.backend.octopus.energy/v1/graphql/` — a *different host* from
 *   the public `api.octopus.energy` one, undocumented in the public GraphQL reference and
 *   never found by introspecting the public endpoint (which is why the first pass here
 *   concluded `joinSavingSessionsEvent` "doesn't exist" — it exists, just not there).
 *   Confirmed by reading that project's actual source and by introspecting this backend
 *   endpoint directly (introspection needs no auth): `savingSessions { events(includeDev:
 *   false) { id code rewardPerKwhInOctoPoints startAt endAt devEvent }, account(accountNumber)
 *   { hasJoinedCampaign, joinedEvents { eventId } } }` — no MPAN, no campaign slug, no
 *   arguments at all on `savingSessions` itself. Join is `joinSavingSessionsEvent(input:
 *   {accountNumber, eventCode}) { joinedEventCodes }` — exactly the shape this class
 *   originally guessed, just aimed at the wrong host. Auth header is the **bare token**
 *   (`Authorization: <token>`), not `JWT <token>` — that project's own code comments this
 *   explicitly, and it's real, currently-running code, not a guess.
 * - **Fill your boots requires no opt-in at all** (confirmed with the user — a
 *   misunderstanding in this feature's original spec): it's an invitation to use more
 *   power at a very low/free rate, not a scheme you join. This class still needs to
 *   *detect* one, via the public `api.octopus.energy` endpoint's
 *   `customerFlexibilityCampaignEvents(accountNumber, supplyPointIdentifier, campaignSlug:
 *   "free_electricity", first)` (confirmed live shape from the very first spike) — MPAN
 *   *is* required for this one query specifically (confirmed live: non-null argument) —
 *   but there's no corresponding join method in this class at all. opt-in.php just applies
 *   the optimised charge override and reports success; see its own comment.
 *
 * A backend GraphQL schema that also looked promising for a *unified* mechanism —
 * `flexibilitySchemeSessions(schemeSlug, member: {accountNumber})` /
 * `optInToFlexibilitySchemeSession(input: {sessionCode, member})`, no MPAN needed either —
 * was found by the same introspection pass but never adopted: unlike the Saving Sessions
 * path above, nothing external confirms it actually works end-to-end, and Power down
 * doesn't need it now that the proven path is in use. Worth revisiting if the Saving
 * Sessions path is ever deprecated (introspection shows both `isDeprecated: false` today).
 *
 * **Live-tested end-to-end against a real account and a real, working API key** (not just
 * introspection this time): `obtainKrakenToken` authenticates; `savingSessions` returns
 * real Power down event history including `account.joinedEvents`; `customerFlexibilityCampaignEvents`
 * returns real Fill your boots event history; `joinSavingSessionsEvent` was exercised
 * against a real (already-past) event and returned a real, correctly-shaped error rather
 * than a schema error — see `joinSession()`'s own doc comment for what that revealed about
 * error shape. The bare-token `Authorization` header works against *both* hosts (the main
 * one also accepts a `JWT `-prefixed value, but bare is simplest and definitely works).
 */
class OctopusFlexClient
{
    private const MAIN_URL = 'https://api.octopus.energy/v1/graphql/';
    private const BACKEND_URL = 'https://api.backend.octopus.energy/v1/graphql/';

    private ?string $token = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $accountNumber,
        private readonly ?string $mpan,
        private readonly string $freeElectricityCampaignSlug,
    ) {
    }

    /**
     * @return array<int, array{kind: string, code: string, start: DateTimeImmutable, end: DateTimeImmutable, alreadyJoined: bool}>
     *         sorted by start time, filtered to sessions starting on $today or $tomorrow (local).
     *         Power down needs only the API key/account number; Fill your boots additionally
     *         needs the MPAN, and is silently skipped (not an error) if it isn't configured —
     *         same "degrade quietly" precedent as an empty campaign slug used to be.
     */
    public function getAvailableSessions(DateTimeImmutable $today, DateTimeImmutable $tomorrow, DateTimeZone $timezone): array
    {
        $todayStr = $today->format('Y-m-d');
        $tomorrowStr = $tomorrow->format('Y-m-d');
        $inRange = fn(DateTimeImmutable $start) => in_array($start->setTimezone($timezone)->format('Y-m-d'), [$todayStr, $tomorrowStr], true);

        // Power down (backend host) and Fill your boots (main host) are two entirely
        // independent data sources — one failing (a transient backend outage, a
        // misconfigured MPAN, whatever) must never silently suppress the other, same
        // "attempt both, don't let one block the other" reasoning Runner.php already
        // applies to today's/tomorrow's Octopus price fetch. Caught here rather than by
        // the caller (index.php) so a partial result is still the class's own contract,
        // not something every caller has to remember to reassemble.
        $sessions = [];
        try {
            foreach ($this->querySavingSessions() as $event) {
                $start = new DateTimeImmutable($event['start']);
                if (!$inRange($start)) {
                    continue;
                }
                $sessions[] = [
                    'kind' => 'power_down',
                    'code' => $event['code'],
                    'start' => $start,
                    'end' => new DateTimeImmutable($event['end']),
                    'alreadyJoined' => $event['alreadyJoined'],
                ];
            }
        } catch (OctopusFlexException $e) {
            // swallowed deliberately — see comment above; Fill your boots below still runs
        }

        if ($this->mpan !== null && $this->mpan !== '' && $this->freeElectricityCampaignSlug !== '') {
            try {
                foreach ($this->queryCampaignEvents($this->freeElectricityCampaignSlug) as $event) {
                    $start = new DateTimeImmutable($event['startAt']);
                    if (!$inRange($start)) {
                        continue;
                    }
                    $sessions[] = [
                        'kind' => 'fill_your_boots',
                        'code' => (string) $event['code'],
                        'start' => $start,
                        'end' => new DateTimeImmutable($event['endAt']),
                        // No API-side join concept for this kind at all (see class doc
                        // comment) — index.php ORs this with the local octopus_session_optins
                        // record, which is the only source of truth that actually applies here.
                        'alreadyJoined' => false,
                    ];
                }
            } catch (OctopusFlexException $e) {
                // swallowed deliberately — see comment above
            }
        }

        usort($sessions, fn($a, $b) => $a['start'] <=> $b['start']);
        return $sessions;
    }

    /**
     * @return array<int, array{code: string, start: string, end: string, alreadyJoined: bool}>
     *         Filters out dev/test events and zero-reward events, same as
     *         automator-octopus-energy's own `getSavingSessions()` — a reward of 0
     *         OctoPoints/kWh isn't a real opt-in opportunity.
     */
    private function querySavingSessions(): array
    {
        $query = 'query($accountNumber: String!) {
            savingSessions {
                events(includeDev: false) { id code startAt endAt rewardPerKwhInOctoPoints devEvent }
                account(accountNumber: $accountNumber) { joinedEvents { eventId } }
            }
        }';
        $result = $this->graphql(self::BACKEND_URL, $query, ['accountNumber' => $this->accountNumber], 'savingSessions');
        $data = $result['data']['savingSessions'] ?? null;
        if ($data === null) {
            return [];
        }
        $joinedIds = array_column($data['account']['joinedEvents'] ?? [], 'eventId');

        $events = [];
        foreach ($data['events'] ?? [] as $event) {
            if (($event['devEvent'] ?? false) || (float) ($event['rewardPerKwhInOctoPoints'] ?? 0) <= 0) {
                continue;
            }
            $events[] = [
                'code' => (string) $event['code'],
                'start' => $event['startAt'],
                'end' => $event['endAt'],
                'alreadyJoined' => in_array($event['id'], $joinedIds, true),
            ];
        }
        return $events;
    }

    /** @return array<int, array{code: string, startAt: string, endAt: string}> */
    private function queryCampaignEvents(string $slug): array
    {
        $query = 'query($accountNumber: String!, $mpan: String!, $campaignSlug: String!) {
            customerFlexibilityCampaignEvents(accountNumber: $accountNumber, supplyPointIdentifier: $mpan, campaignSlug: $campaignSlug, first: 10) {
                edges { node { code startAt endAt } }
            }
        }';
        $result = $this->graphql(self::MAIN_URL, $query, [
            'accountNumber' => $this->accountNumber,
            'mpan' => $this->mpan,
            'campaignSlug' => $slug,
        ], "customerFlexibilityCampaignEvents:$slug");
        $edges = $result['data']['customerFlexibilityCampaignEvents']['edges'] ?? [];
        return array_column($edges, 'node');
    }

    /**
     * Joins a Power down (Saving Sessions) event — there is no equivalent for Fill your
     * boots, which needs no opt-in at all (see class doc comment); callers must not call
     * this for that kind.
     *
     * No idempotency swallowing here (an earlier version guessed that an "already ..."
     * -worded error meant success) — confirmed live, on a real already-joined event, that
     * ineligibility (already joined, or too late to join, or anything else) comes back as
     * errorCode `OE-1308` with the real reason in `extensions.reason`
     * ("Account cannot join event after the start of the event.", in that test), not an
     * "already"-worded message. Guessing which OE-1308 reasons are safe to swallow risks
     * masking a genuine failure as success, so every error is surfaced honestly to the
     * caller instead — getAvailableSessions() already excludes sessions the account has
     * joined, so an OE-1308 here in practice means something unexpected happened between
     * that check and this call, which is worth surfacing, not hiding.
     */
    public function joinSession(string $code): void
    {
        $mutation = 'mutation($accountNumber: String!, $eventCode: String!) {
            joinSavingSessionsEvent(input: {accountNumber: $accountNumber, eventCode: $eventCode}) {
                joinedEventCodes
            }
        }';
        $result = $this->graphql(self::BACKEND_URL, $mutation, [
            'accountNumber' => $this->accountNumber,
            'eventCode' => $code,
        ], 'joinSavingSessionsEvent');
        $joinedCodes = $result['data']['joinSavingSessionsEvent']['joinedEventCodes'] ?? [];
        if (!in_array($code, $joinedCodes, true)) {
            throw new OctopusFlexException("Octopus's join call for $code succeeded but didn't confirm it as joined (returned: " . implode(', ', $joinedCodes) . ')');
        }
    }

    private function authenticate(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $query = 'mutation($apiKey: String!) { obtainKrakenToken(input: {APIKey: $apiKey}) { token } }';
        $result = $this->graphql(self::MAIN_URL, $query, ['apiKey' => $this->apiKey], 'obtainKrakenToken', skipAuth: true);
        $token = $result['data']['obtainKrakenToken']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new OctopusFlexException('Octopus GraphQL authentication succeeded but returned no token');
        }
        return $this->token = $token;
    }

    /**
     * The one choke point every GraphQL call goes through (against either host) — logged
     * via Store::saveApiLogEntry() exactly like FoxessClient::post(), so these calls show
     * up in the same api-log.php page/table, distinguished by their $operationLabel (e.g.
     * `graphql:savingSessions`) rather than a URL path, since every call to a given host
     * hits the same URL. Single retry on transient network failure only, no backoff — same
     * rule OctopusClient::httpGet()/FoxessClient::post() already follow (spec §12).
     */
    protected function graphql(string $url, string $query, array $variables, string $operationLabel, bool $skipAuth = false, bool $isRetry = false): array
    {
        $headers = ['Content-Type: application/json'];
        if (!$skipAuth) {
            // Bare token, not "JWT <token>" — confirmed against automator-octopus-energy's
            // own code (real, currently-running against this exact backend host), which
            // comments this explicitly. See this class's own doc comment.
            $headers[] = 'Authorization: ' . $this->authenticate();
        }

        $body = json_encode(['query' => $query, 'variables' => $variables]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            // Confirmed live: api.backend.octopus.energy's edge/WAF returns a plain 403
            // (no GraphQL body at all) to a request with no User-Agent — PHP's cURL sends
            // none by default, unlike the `curl` CLI tool, which is why manual curl-based
            // spikes against this same host never hit this. automator-octopus-energy's own
            // code sets one for exactly this host for what's presumably the same reason.
            CURLOPT_USERAGENT => 'foxhole/1.0 (+https://github.com/almcnicoll/foxhole)',
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        saveApiLogEntry(
            "graphql:$operationLabel",
            $body,
            $errno !== 0 ? null : $status,
            $errno !== 0 ? "cURL error: $error" : $raw,
            new DateTimeImmutable('now'),
        );

        if ($errno !== 0) {
            if (!$isRetry) {
                return $this->graphql($url, $query, $variables, $operationLabel, $skipAuth, true);
            }
            throw new OctopusFlexException("cURL error calling Octopus GraphQL ($operationLabel): $error");
        }
        if ($status !== 200) {
            throw new OctopusFlexException("Octopus GraphQL ($operationLabel) returned HTTP $status: $raw");
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new OctopusFlexException("Octopus GraphQL ($operationLabel) returned non-JSON response: $raw");
        }
        if (!empty($decoded['errors'])) {
            $firstError = $decoded['errors'][0];
            // errorCode/reason (e.g. OE-1308, "Account cannot join event after the start
            // of the event.") carry the actually-useful detail for join failures — plain
            // `message` alone can be a generic "ineligible" wrapper. Confirmed live
            // against a real, already-past Saving Sessions event.
            $detail = array_filter([$firstError['extensions']['errorCode'] ?? null, $firstError['extensions']['reason'] ?? null]);
            throw new OctopusFlexException(sprintf(
                'Octopus GraphQL (%s) error: %s%s',
                $operationLabel,
                $firstError['message'] ?? 'unknown',
                $detail ? ' [' . implode(' — ', $detail) . ']' : '',
            ));
        }

        return $decoded;
    }
}
