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
 * as this app's `fdSoc`/`fdPwr` FoxESS fields — researched against the official Octopus
 * GraphQL docs and the most mature third-party integration
 * (`BottlecapDave/HomeAssistant-OctopusEnergy`), but a live spike against a real account
 * (attempted while building this) failed on an invalid API key before any of the
 * campaign/join specifics could be checked. **Verify all of the below against a real
 * account before relying on this in production** — same "don't trust inference blindly"
 * rule this codebase already applies elsewhere:
 *
 * - Confirmed: auth is `obtainKrakenToken(input: {APIKey: $key})` — the input field is
 *   `APIKey`, not `apiKey` (confirmed live: the API's own error message corrects the
 *   casing). Confirmed: `customerFlexibilityCampaignEvents(accountNumber, supplyPointIdentifier, campaignSlug, first)`
 *   returning `edges.node.{code, startAt, endAt}` is the right shape for the Free
 *   Electricity/Power Up campaign specifically (`campaignSlug: "free_electricity"`).
 * - Not confirmed: the campaign slug for Power Down/Saving Sessions (Power Down predates
 *   the Free Electricity GraphQL rollout and may use a different query entirely, not just
 *   a different slug on this same query) — see config.php's `octopus.flex_campaign_slugs`.
 * - Not confirmed: whether `supplyPointIdentifier` (MPAN) is actually required, or the
 *   account number alone is sufficient — asked for in settings.php but optional, passed
 *   as null when not configured.
 * - Not confirmed: the real join mutation name/input shape for either campaign. Best
 *   guess below is `joinSavingSessionsEvent(input: {accountNumber, eventCode})`, per the
 *   Home Assistant integration's own service parameters (event_code) — may need
 *   splitting into two different mutations once confirmed.
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
     * @return array<int, array{kind: string, code: string, start: DateTimeImmutable, end: DateTimeImmutable}>
     *         sorted by start time, filtered to sessions starting on $today or $tomorrow (local)
     */
    public function getAvailableSessions(DateTimeImmutable $today, DateTimeImmutable $tomorrow, DateTimeZone $timezone): array
    {
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
                ];
            }
        }
        usort($sessions, fn($a, $b) => $a['start'] <=> $b['start']);
        return $sessions;
    }

    /** @return array<int, array{code: string, startAt: string, endAt: string}> */
    private function queryCampaignEvents(string $slug): array
    {
        $query = 'query($accountNumber: String!, $mpan: String, $campaignSlug: String!) {
            customerFlexibilityCampaignEvents(accountNumber: $accountNumber, supplyPointIdentifier: $mpan, campaignSlug: $campaignSlug, first: 10) {
                edges { node { code startAt endAt } }
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
     * Joins the given event. Idempotent by design: if Octopus reports the account is
     * already enrolled (matched loosely on the error text, since the exact error shape
     * isn't confirmed — see this class's own doc comment), that's treated as success
     * rather than a failure, so a double-click or two open tabs can't surface a spurious
     * warning for something that's already true.
     */
    public function joinSession(string $kind, string $code): void
    {
        $mutation = 'mutation($accountNumber: String!, $eventCode: String!) {
            joinSavingSessionsEvent(input: {accountNumber: $accountNumber, eventCode: $eventCode}) {
                event { code }
            }
        }';
        try {
            $this->graphql($mutation, [
                'accountNumber' => $this->accountNumber,
                'eventCode' => $code,
            ], "joinSavingSessionsEvent:$kind");
        } catch (OctopusFlexException $e) {
            if (self::looksAlreadyJoined($e->getMessage())) {
                return;
            }
            throw $e;
        }
    }

    private static function looksAlreadyJoined(string $message): bool
    {
        return stripos($message, 'already') !== false;
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
            $headers[] = 'Authorization: ' . $this->authenticate();
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
