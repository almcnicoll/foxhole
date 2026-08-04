# CLAUDE.md

Guidance for Claude Code (or any future maintainer) working in this repo.

## What this is

A small PHP app that once a day fetches tomorrow's Octopus Agile half-hourly
electricity rates, computes a battery charge/discharge schedule from them,
and pushes that schedule to a FoxESS inverter. Runs as a one-shot cron job on
shared hosting — no daemon, no framework, no Composer dependencies (cURL and
JSON are both PHP core extensions).

Full spec: [doc/foxess-agile-scheduler-spec.md](doc/foxess-agile-scheduler-spec.md).
Read it before making structural changes — this file covers what the spec
doesn't: decisions made while building, and things confirmed against live
services that the spec left open.

## Layout

```
config.php            # real credentials, gitignored — copy of config.example.php
config.example.php    # template, safe to commit
run.php               # entry point, invoked by cron (supports --dry-run)
src/
  Logger.php           # timestamped file logger, rotates past 2MB
  Exceptions.php        # OctopusFetchException / ScheduleBuildException / FoxessPushException
  OctopusClient.php     # fetches + caches half-hourly rates from api.octopus.energy
  CostBasisProvider.php # resolves the "worth charging below this" reference price
  ScheduleBuilder.php   # rates + cost basis -> FoxESS scheduler groups
  FoxessClient.php      # signs + sends requests to the FoxESS OpenAPI
tests/
  self_check.php        # standalone assert-style test for ScheduleBuilder/CostBasisProvider
logs/scheduler.log
data/last_rates.json      # raw cache of the most recent Octopus fetch
data/last_schedule.json   # last schedule successfully pushed, used to skip no-op pushes
```

No autoloader — `run.php` and `tests/self_check.php` just `require_once` each
`src/` file in dependency order. Keep it that way unless the file count grows
enough to justify one; it hasn't.

## Request flow (`run.php`)

1. Load `config.php`.
2. `OctopusClient::fetchRatesForDate()` — GET rates for tomorrow (local
   midnight-to-midnight, converted to UTC for the API query), cache raw JSON
   to `data/last_rates.json`. Throws `OctopusFetchException` on transport
   failure *or* on fewer than 48 slots (rates not fully published yet).
3. `CostBasisProvider::getCostBasis()` — 48 values (currently flat, `fixed`
   mode) to compare Agile rates against.
4. `ScheduleBuilder::build()` — pure price-threshold logic, no I/O. Produces
   `{groups: [...]}`.
5. Diff against `data/last_schedule.json`; skip the FoxESS call if unchanged.
6. `FoxessClient::pushSchedule()` — signs and POSTs to
   `/op/v1/device/scheduler/enable`.
7. On success, persist the new `last_schedule.json` and log a summary. On any
   failure, log at ERROR, best-effort email `notify.alert_email`, exit 1 so
   cron surfaces the failure.

`--dry-run` runs everything through step 4, prints the computed schedule, and
exits — no network call to FoxESS, no state written.

## Decisions made while building (things the spec left open)

**FoxESS scheduler endpoint: v1, not v0 or v3.** Cross-checked the live
FoxESS OpenAPI docs, the `foxesscommunity.com` forums, and existing
implementations (`TonyM1958/FoxESS-Cloud`, `nickw444/ha-foxess-cloud`). v0 is
confirmed by multiple community reports to corrupt backend scheduler state on
some inverters (recovery requires waiting ~3h then re-writing a plain SelfUse
schedule). v1 (`/op/v1/device/scheduler/enable` / `/op/v1/device/scheduler/get`)
is what the community has since standardized on. A "v2/v3 batch" variant
showed up in one low-confidence source but couldn't be corroborated
independently — not used. **If you're revisiting this, re-check the live
docs at foxesscloud.com/public/i18n/en/OpenApiDocument.html** — FoxESS has
changed this endpoint before and could again.

**Request signing** (`FoxessClient::post()`) matches the spec exactly:
`md5(path . "\r\n" . token . "\r\n" . timestamp_ms)`, headers
`Token`/`Timestamp`/`Signature`/`Lang`/`Content-Type`. Confirmed against
multiple independent third-party implementations, not just the spec — this
part is solid. `errno 40256` from FoxESS means a missing/stale auth header,
not a bad schedule payload.

**`fdSoc` / `fdPwr` field semantics are a best-effort guess, not confirmed.**
The spec's own example payload was marked "confirm exact field names against
current docs." Research didn't turn up an authoritative field-by-field spec
for these two. Current interpretation, inferred from the one example in the
spec: `fdSoc` is a SoC ceiling (charge) or floor (discharge) for whichever
force mode is active, `fdPwr` is the power limit in watts for that mode. See
`ScheduleBuilder::periodsToGroups()`. **Before relying on this in production,
verify against a real `scheduler/get` read-back after a manual test push** —
don't trust the inference blindly.

**Octopus region letter is `C` for London, not `X`.** The spec's config
comment (`-X for London`) is wrong — verified live against
`api.octopus.energy/v1/products/AGILE-24-10-01/`. Region letters are GSP
codes A–P (no X). Fixed in `config.example.php`. If a user is outside London,
look up their own tariff code via the same products endpoint rather than
guessing the letter.

**`cheap_slots_to_charge` is a plain config cap, not dynamically computed.**
The spec's parenthetical about deriving it from `capacity_kwh / max_charge_kw`
describes how to *choose* the config value, not a runtime calculation —
`ScheduleBuilder` just uses the config number as an upper bound. Don't add a
dynamic recompute unless the spec is revised to actually ask for it.

**Charge/discharge slot selection never overlaps.** Discharge candidates
(`ScheduleBuilder::build()`) explicitly exclude any slot already claimed for
charging before picking the most-expensive M. The spec doesn't call this out
explicitly, but without it a slot could theoretically get emitted with two
conflicting groups.

**Single retry on transient network failure**, no backoff, applied in both
`OctopusClient::httpGet()` and `FoxessClient::post()` — per spec §12's "no
retry/backoff beyond a single retry." Only retries cURL-level failures
(timeouts, DNS, connection refused), not HTTP error statuses.

## Config & secrets

`config.php` is real and gitignored; `config.example.php` is the committed
template. If you add a new config key, update both files and the shape
described in the spec's §4.

## Running

```bash
php -l run.php src/*.php          # syntax check
php tests/self_check.php          # ScheduleBuilder / CostBasisProvider logic
php run.php --dry-run             # full pipeline against live Octopus API, no FoxESS write
```

`tests/self_check.php` is plain PHP with a local `check()` helper — not
`assert()`, because `assert()`'s behaviour depends on `zend.assertions` in
`php.ini` and shared hosts often disable it in production, which would make
an assert-based test silently do nothing. If you add logic with a branch or
loop worth protecting, extend this file rather than reaching for a test
framework.

There's no live-fire test against a real FoxESS device in this repo — that
needs real credentials and touches a physical inverter, so it's a manual
step for whoever deploys this, not something to script here.

## Extension points

- **Cost basis modes**: `CostBasisProvider` — `octopus_product` mode (for
  when a time-banded tariff like Flux goes live) is stubbed with a `TODO`,
  deliberately not implemented against guessed API shapes. See spec §13 and
  [roadmap.MD](roadmap.MD).
- **Scheduling algorithm**: `ScheduleBuilder::build()` is pure
  price-threshold logic by design (spec §7). A smarter optimiser (solar
  forecast, load-aware) would replace this method's body without touching
  its inputs/outputs — `run.php` and `FoxessClient` don't need to change.

## Out of scope (spec §12 — don't build these without a scope conversation)

Web dashboard, solar forecasting, multi-inverter support, historical
analytics, retry/backoff beyond one attempt.
