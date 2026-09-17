<?php

require_once __DIR__ . '/Exceptions.php';
require_once __DIR__ . '/Store.php';

/**
 * Talks to Octopus's GraphQL API (api.octopus.energy/v1/graphql/) to discover and join
 * ad-hoc opt-in sessions — "Power down" (Saving Sessions: use less, get paid) and
 * "Fill your boots" (Free Electricity/Power Up: use more, it's free/cheap) — the same two
 * kinds `overrides`/`ScheduleBuilder::overrideModesFor()` already model. Deliberately
 * scoped to just these ad-hoc, announced-then-joined events, not Octopus's separate
 * "Weekend Happy Hour" mechanism (booking one future slot ahead of time, a different API
 * shape) — out of scope for this feature by explicit decision.
 *
 * **Several pieces of this class are best-effort, not confirmed live**, in the same spirit
 * as this app's `fdSoc`/`fdPwr` FoxESS fields. Confirmed by live GraphQL introspection
 * against the real, unauthenticated endpoint (schema introspection needs no token, so this
 * much was checkable even without a working API key):
 *
 * - `customerFlexibilityCampaignEvents(accountNumber: String!, supplyPointIdentifier: String!, campaignSlug: String!, first: Int)`
 *   returning a connection of `{name, code, startAt, endAt, isEventParticipant}` nodes —
 *   confirmed live. **`supplyPointIdentifier` (MPAN) is a required (non-null) argument,
 *   not optional** — an earlier version of this class treated it as optional; that was
 *   wrong. `isEventParticipant` is a genuine API-side join-status boolean — this class
 *   prefers it over the local `octopus_session_optins` table (which still exists as a
 *   fast-path/fallback — see Store.php), since it also reflects an opt-in made via
 *   Octopus's own app, not just one made through this feature.
 * - The query itself still requires an `Authorization` header despite not taking an API
 *   key as a query argument — confirmed live (`KT-CT-1112`, "You must provide the
 *   AUTHORIZATION header"). Auth is `obtainKrakenToken(input: {APIKey: $key})` — the input
 *   field is `APIKey`, not `apiKey` (confirmed live: the API's own error message corrects
 *   the casing) — but no API key tested against this account so far has actually
 *   authenticated (`KT-CT-1139`, "Authentication failed"), so the resulting JWT has never
 *   actually been exercised. The `Authorization: JWT <token>` header format below is the
 *   standard Kraken/Octopus convention, not independently confirmed against this account.
 * - **Not confirmed**: the campaign slug for Power Down/Saving Sessions — `campaignSlug`
 *   isn't a schema enum (introspection can't enumerate valid values), and Power Down
 *   predates the Free Electricity GraphQL rollout, so it may use a different query
 *   entirely, not just a different slug on this one. See config.php's
 *   `octopus.flex_campaign_slugs`.
 * - **Not confirmed, and NOT implemented as a guess**: there is no `joinSavingSessionsEvent`
 *   mutation in the live schema at all — confirmed absent by introspecting every Mutation
 *   field. The closest real candidates are `joinOctoplusCampaign(accountNumber: String)`
 *   (no event-specific code argument at all — looks like generic Octoplus rewards-program
 *   enrollment, not a specific timed session) and `addCampaignToAccount(input:
 *   {accountNumber, campaign, startDate, expiryDate})` (a bare `campaign` string with no
 *   date/time — looks like enrolling in a whole named scheme, not a specific announced
 *   occurrence). Neither one's semantics for "join this specific event by its `code`" are
 *   confirmed, and firing either one blind against a real account risks an unintended
 *   enrollment rather than a clean failure — `joinSession()` below deliberately throws
 *   rather than guessing. The reliable way to confirm this is capturing the real request
 *   (browser DevTools, Network tab, filter "graphql") while opting into a real session via
 *   Octopus's own app/website.
 */
class OctopusFlexClient
{
    private const BASE_URL = 'https://api.octopus.energy/v1/graphql/';

    private ?string $token = null;

    /** @param array{power_down: string, fill_your_boots: string} $campaignSlugs kind => Octopus campaign slug; an empty slug skips that kind entirely (not yet configured/confirmed) */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $accountNumber,
        private readonly ?string $mpan,
        private readonly array $campaignSlugs,
    ) {
    }

    /**
     * @return array<int, array{kind: string, code: string, start: DateTimeImmutable, end: DateTimeImmutable, alreadyJoined: bool}>
     *         sorted by start time, filtered to sessions starting on $today or $tomorrow (local)
     */
    public function getAvailableSessions(DateTimeImmutable $today, DateTimeImmutable $tomorrow, DateTimeZone $timezone): array
    {
        if ($this->mpan === null || $this->mpan === '') {
            throw new OctopusFlexException('Octopus account MPAN is required (settings.php) — confirmed live that customerFlexibilityCampaignEvents\' supplyPointIdentifier argument is non-null');
        }

        $todayStr = $today->format('Y-m-d');
        $tomorrowStr = $tomorrow->format('Y-m-d');

        $sessions = [];
        foreach ($this->campaignSlugs as $kind => $slug) {
            if ($slug === '') {
                continue;
            }
            foreach ($this->queryCampaignEvents($slug) as $event) {
                $start = new DateTimeImmutable($event['startAt']);
                $localDate = $start->setTimezone($timezone)->format('Y-m-d');
                if ($localDate !== $todayStr && $localDate !== $tomorrowStr) {
                    continue;
                }
                $sessions[] = [
                    'kind' => $kind,
                    'code' => (string) $event['code'],
                    'start' => $start,
                    'end' => new DateTimeImmutable($event['endAt']),
                    'alreadyJoined' => (bool) ($event['isEventParticipant'] ?? false),
                ];
            }
        }
        usort($sessions, fn($a, $b) => $a['start'] <=> $b['start']);
        return $sessions;
    }

    /** @return array<int, array{code: string, startAt: string, endAt: string, isEventParticipant: ?bool}> */
    private function queryCampaignEvents(string $slug): array
    {
        $query = 'query($accountNumber: String!, $mpan: String!, $campaignSlug: String!) {
            customerFlexibilityCampaignEvents(accountNumber: $accountNumber, supplyPointIdentifier: $mpan, campaignSlug: $campaignSlug, first: 10) {
                edges { node { code startAt endAt isEventParticipant } }
            }
        }';
        $result = $this->graphql($query, [
            'accountNumber' => $this->accountNumber,
            'mpan' => $this->mpan,
            'campaignSlug' => $slug,
        ], "customerFlexibilityCampaignEvents:$slug");
        $edges = $result['data']['customerFlexibilityCampaignEvents']['edges'] ?? [];
        return array_column($edges, 'node');
    }

    /**
     * Deliberately unimplemented — see this class's own doc comment. There is no confirmed
     * (or even plausible-and-safe-to-guess) mutation for "join this specific event by its
     * code" in the live schema: `joinSavingSessionsEvent` doesn't exist at all, and the two
     * real candidates found (`joinOctoplusCampaign`, `addCampaignToAccount`) don't take an
     * event code/date and look like they enrol in something else entirely. Guessing here
     * risks a real, hard-to-notice wrong side effect on a real account — worse than a clean
     * failure — so this throws unconditionally until the real mutation is confirmed (ideally
     * via a captured real request, see the class doc comment). opt-in.php's existing
     * error handling already surfaces this correctly: preparation still gets calculated,
     * saved as an override, and pushed to the inverter(s) — only the final "tell Octopus"
     * step is blocked.
     */
    public function joinSession(string $kind, string $code): void
    {
        throw new OctopusFlexException(sprintf(
            'Octopus\'s join-session mutation has not been confirmed yet (see CLAUDE.md\'s '
            . '"Octopus opt-in sessions" section and OctopusFlexClient\'s own doc comment) — '
            . 'opt in manually via the Octopus app for the %s event %s for now.',
            $kind,
            $code,
        ));
    }

    private function authenticate(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $query = 'mutation($apiKey: String!) { obtainKrakenToken(input: {APIKey: $apiKey}) { token } }';
        $result = $this->graphql($query, ['apiKey' => $this->apiKey], 'obtainKrakenToken', skipAuth: true);
        $token = $result['data']['obtainKrakenToken']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new OctopusFlexException('Octopus GraphQL authentication succeeded but returned no token');
        }
        return $this->token = $token;
    }

    /**
     * The one choke point every GraphQL call goes through — logged via
     * Store::saveApiLogEntry() exactly like FoxessClient::post(), so these calls show up
     * in the same api-log.php page/table, distinguished by their $operationLabel (e.g.
     * `graphql:customerFlexibilityCampaignEvents:free_electricity`) rather than a URL
     * path, since every GraphQL call hits the same URL. Single retry on transient network
     * failure only, no backoff — same rule OctopusClient::httpGet()/FoxessClient::post()
     * already follow (spec §12).
     */
    protected function graphql(string $query, array $variables, string $operationLabel, bool $skipAuth = false, bool $isRetry = false): array
    {
        $headers = ['Content-Type: application/json'];
        if (!$skipAuth) {
            // "JWT " prefix is the standard Kraken/Octopus convention — see this class's
            // own doc comment for why it hasn't actually been exercised against a real,
            // successfully-authenticated token yet.
            $headers[] = 'Authorization: JWT ' . $this->authenticate();
        }

        $body = json_encode(['query' => $query, 'variables' => $variables]);
        $ch = curl_init(self::BASE_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
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
                return $this->graphql($query, $variables, $operationLabel, $skipAuth, true);
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
            throw new OctopusFlexException(sprintf(
                'Octopus GraphQL (%s) error: %s',
                $operationLabel,
                $decoded['errors'][0]['message'] ?? 'unknown',
            ));
        }

        return $decoded;
    }
}
