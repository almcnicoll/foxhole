# Spec: Octopus Agile → FoxESS Charge Scheduler

## 1. Purpose

A small, self-contained PHP application that runs on shared hosting (no daemon, no Home Assistant). Once a day it:

1. Fetches tomorrow's Octopus Agile half-hourly unit rates.
2. Computes a battery charge/discharge schedule from those rates.
3. Pushes that schedule to the FoxESS inverter via the FoxESS OpenAPI scheduler endpoint.

No web UI is required for v1. Config is a single PHP file. Execution is triggered by cron.

## 2. Environment assumptions

- Shared hosting with PHP 8.1+, cURL extension, cron access (typical cPanel-style host).
- No persistent process — everything runs as a one-shot script per cron invocation.
- Outbound HTTPS to `api.octopus.energy` and `www.foxesscloud.com` must be permitted by the host.
- Storage: flat JSON/log files on disk. No database required for v1 (can be added later if history/analytics are wanted).

## 3. Directory structure

```
/scheduler
  config.php          # all credentials & tunables, gitignored
  config.example.php  # template with placeholder values
  src/
    OctopusClient.php
    FoxessClient.php
    ScheduleBuilder.php
    Logger.php
  run.php             # entry point, invoked by cron
  logs/
    scheduler.log
  data/
    last_rates.json    # cached rates from the most recent successful fetch
    last_schedule.json # last schedule sent to FoxESS, for diffing/debugging
```

## 4. Configuration (`config.php`)

```php
<?php
return [
    'octopus' => [
        'product_code' => 'AGILE-24-10-01',        // confirm current live product code
        'tariff_code'  => 'E-1R-AGILE-24-10-01-X',  // region-specific, e.g. -X for London
    ],
    'foxess' => [
        'api_key'   => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx', // from FoxESS Cloud > API Management
        'device_sn' => 'xxxxxxxxxxxxx',
        'base_url'  => 'https://www.foxesscloud.com',
    ],
    'battery' => [
        'capacity_kwh'      => 10.0,
        'max_charge_kw'     => 3.0,
        'max_discharge_kw'  => 3.0,
        'min_soc_on_grid'   => 15,   // percent, respects inverter's own floor
        'reserve_soc'       => 15,   // percent never discharged below
    ],
    'strategy' => [
        'cheap_slots_to_charge'   => 6,  // upper cap on half-hour slots to force-charge, see §6
        'expensive_slots_to_export' => 4, // number of half-hour slots to force-discharge/export
        'timezone' => 'Europe/London',
    ],
    'cost_basis' => [
        // What Al actually pays for electricity — used as the "worth charging below this" threshold.
        // Only ever charge from the grid when the Agile rate is cheaper than this.
        'mode' => 'fixed', // 'fixed' now; 'octopus_product' once Flux is live
        'fixed_pence_per_kwh' => 24.50, // current standing tariff rate — update when tariff changes
        // Used only when mode = 'octopus_product' (e.g. Flux import rate), same shape as the octopus config above:
        'product_code' => null,
        'tariff_code'  => null,
    ],
    'notify' => [
        'alert_email' => 'you@example.com', // optional, for failure notifications
    ],
];
```

Claude Code: generate `config.example.php` with the same keys and obviously fake placeholder values; `config.php` itself should be added to `.gitignore`.

## 5. Component: OctopusClient

Responsibility: fetch and cache Agile rates for the target day.

- Endpoint: `GET https://api.octopus.energy/v1/products/{product_code}/electricity-tariffs/{tariff_code}/standard-unit-rates/`
- No authentication required.
- Query params `period_from` / `period_to` (ISO 8601, UTC) to scope to the target day.
- Returns 48 half-hour slots with `value_inc_vat` (pence/kWh) and `valid_from`/`valid_to`.
- Agile rates for the next day are typically published from ~16:00 UK time — if the run is scheduled before publication and the API returns fewer than 48 slots, log a warning and abort without pushing a partial schedule.
- Cache the raw response to `data/last_rates.json` for debugging.

## 6. Component: CostBasisProvider

Responsibility: resolve the reference price ("what Al actually pays") that Agile rates get compared against.

- `mode = 'fixed'`: return `fixed_pence_per_kwh` from config as a flat value applied to all 48 slots.
- `mode = 'octopus_product'` (for when the Flux tariff is live): fetch that tariff's rates the same way `OctopusClient` fetches Agile rates, and return a per-slot value instead of a flat one. Flux's own rates are time-banded rather than fully dynamic, so this may mean mapping a small number of daily bands onto the 48 half-hour slots rather than 48 distinct values — worth checking the actual Flux rates endpoint shape before assuming it matches Agile's structure exactly.
- Both modes return the same shape to the caller: an array of 48 values (pence/kWh) aligned to the same slots as the Agile rates, so `ScheduleBuilder` doesn't need to know which mode is active.

This is the extension point for the tariff switch — when Al moves to Flux, only `config.php` and this provider's fetch logic need to change, not `ScheduleBuilder`.

## 7. Component: ScheduleBuilder

Responsibility: turn 48 Agile rate slots + the cost basis into a FoxESS-compatible schedule.

Algorithm (v1):

1. Get the cost basis for each of the 48 slots from `CostBasisProvider`.
2. Filter to slots where `agile_rate < cost_basis` for that slot — these are the only candidates for grid charging, regardless of how cheap or expensive the day is overall.
3. From that filtered set, sort ascending by rate and take up to `cheap_slots_to_charge` slots (a cap derived from how long a full charge takes: roughly `capacity_kwh / max_charge_kw`, rounded up to half-hour slots — treat the config value as an upper bound, not a target to always fill). If fewer slots pass the filter than the cap, charge only those — don't force a charge just to hit a slot count.
4. Mark the selected slots as `ForceCharge` periods, respecting `max_charge_kw` and `battery.capacity_kwh`.
5. Take the most expensive M slots (`expensive_slots_to_export`) → mark as `ForceDischarge` periods, respecting `max_discharge_kw` and `reserve_soc`. (Cost-basis filtering doesn't apply to discharge in v1 — only the "buy below cost" rule was requested; discharge/export slot selection is unchanged.)
6. All other slots default to `SelfUse` (i.e. don't send an explicit period for them — FoxESS leaves untouched periods on whatever the existing default/self-use behaviour is, per community reports on the `/op/v3/device/scheduler/enable` endpoint).
7. Merge contiguous same-mode slots into single start/end periods rather than sending 30-minute fragments (fewer, cleaner periods; also avoids exceeding any max-period-count limits).
8. Output a `groups` array shaped for the FoxESS scheduler payload (see §8).

Leave a clear extension point here — Al may want a smarter optimiser later (e.g. accounting for forecast solar generation or load) but v1 should be pure price-threshold logic.

## 8. Component: FoxessClient

### Auth / signing

FoxESS signs every request with MD5 of `path + "\r\n" + token + "\r\n" + timestamp`:

```php
$path = '/op/v1/device/scheduler/enable'; // or whichever endpoint
$timestamp = (string) round(microtime(true) * 1000); // ms
$signature = md5($path . "\r\n" . $apiKey . "\r\n" . $timestamp);

$headers = [
    'Token: ' . $apiKey,
    'Timestamp: ' . $timestamp,
    'Signature: ' . $signature,
    'Lang: en',
    'Content-Type: application/json',
];
```

Notes for implementation:
- Timestamp must be freshly generated immediately before each request (signature has a short validity window — generate and send within the same call, don't reuse across retries without regenerating).
- Use the `\r\n` literally as two characters in the string being hashed, not as an escaped/interpreted sequence — this has tripped people up in other languages.

### Endpoints needed

- `POST /op/v1/device/scheduler/enable` — push the computed schedule. **Use the v1 (or v3, whichever is current/stable) scheduler endpoint, not v0** — community reports indicate the older v0 scheduler endpoint has caused backend corruption requiring a wait-and-reset. Claude Code should check the current FoxESS OpenAPI docs (https://www.foxesscloud.com/public/i18n/en/OpenApiDocument.html) at build time to confirm the current recommended endpoint version before wiring this up.
- `GET /op/v0/device/scheduler/get` — (optional, useful for a dry-run/verify step) read back current schedule to confirm it applied.
- `GET /op/v0/device/real/query` — (optional) simple low-risk endpoint useful for testing the signature logic in isolation before touching the scheduler.

### Payload shape (example, confirm exact field names against current docs)

```json
{
  "deviceSN": "xxxxxxxxxxxxx",
  "groups": [
    {
      "enable": 1,
      "startHour": 2, "startMinute": 0,
      "endHour": 5, "endMinute": 0,
      "workMode": "ForceCharge",
      "minSocOnGrid": 15,
      "fdSoc": 100,
      "fdPwr": 3000
    }
  ]
}
```

## 9. Entry point (`run.php`)

Sequence:

1. Load config.
2. Fetch tomorrow's Octopus rates (abort + log + alert on failure or incomplete data).
3. Build schedule via `ScheduleBuilder`.
4. Diff against `data/last_schedule.json` — if identical, skip the FoxESS call entirely (avoid burning API quota unnecessarily).
5. Push schedule to FoxESS.
6. On success: write schedule to `data/last_schedule.json`, log summary.
7. On failure: log full error, optionally email `alert_email`, exit non-zero so cron failure is visible.

Support a `--dry-run` CLI flag that does everything except the FoxESS write, printing the computed schedule instead — useful for testing on the shared host without touching the live inverter.

## 10. Cron

Single daily job, scheduled after Agile rates for the next day are reliably published (e.g. 17:00 UK time, with some buffer past the usual ~16:00 publication):

```
0 17 * * * /usr/bin/php /home/user/scheduler/run.php >> /home/user/scheduler/logs/cron.log 2>&1
```

## 11. Error handling & logging

- Simple `Logger` class writing timestamped lines to `logs/scheduler.log`, rotate/truncate if it grows past a size threshold.
- Distinguish three failure classes in logs: Octopus fetch failure, schedule build failure (e.g. incomplete rate data), FoxESS push failure (including auth/signature errors, which the community reports as a common source of `errno 40256`).
- Rate limit awareness: FoxESS caps at 1,440 calls/day/inverter — this system's usage (a handful of calls per day) is far under that, so no throttling logic is needed, but log the call count per run for visibility.

## 12. Out of scope for v1

- Web dashboard / UI.
- Solar generation forecasting.
- Multi-inverter support.
- Historical analytics / cost reporting.
- Automatic retry/backoff beyond a single retry on transient network failure.

## 13. Open items for Claude Code to confirm during build

- Exact current FoxESS scheduler endpoint version (v0 vs v1 vs v3) and exact payload field names — verify against live docs, not just this spec, as FoxESS has changed this endpoint before.
- Confirm Octopus product/tariff codes are account/region-specific and must be set correctly in config (not detectable automatically without also calling the account endpoint with an API key).
- Flux-mode `CostBasisProvider` is speculative until the tariff is actually live and its current rate structure can be checked — build the `fixed` mode fully now, stub the `octopus_product` mode with a clear TODO rather than guessing at Flux's exact API shape.
