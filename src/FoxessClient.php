<?php

require_once __DIR__ . '/Exceptions.php';

// Signs and sends requests to the FoxESS OpenAPI. Uses the v1 scheduler
// endpoints, not v0 — community reports (foxesscommunity.com) describe v0
// scheduler writes corrupting backend state on some inverters. See CLAUDE.md
// for the research trail behind this choice.
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

    /** Push a computed schedule. $groups is the array built by ScheduleBuilder. */
    public function pushSchedule(array $groups): array
    {
        return $this->post('/op/v1/device/scheduler/enable', [
            'deviceSN' => $this->deviceSn,
            'groups' => $groups,
        ]);
    }

    /** Read back the currently applied schedule — useful to verify a push landed. */
    public function getSchedule(): array
    {
        return $this->post('/op/v1/device/scheduler/get', ['deviceSN' => $this->deviceSn]);
    }

    /** Low-risk read-only call, useful for testing the signature logic in isolation. */
    public function testSignature(): array
    {
        return $this->post('/op/v1/device/real/query', ['sns' => [$this->deviceSn]]);
    }

    private function post(string $path, array $body, bool $isRetry = false): array
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
