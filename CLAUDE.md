# CLAUDE.md

Guidance for Claude Code (or any future maintainer) working in this repo.

## What this is

A small PHP app that once a day fetches tomorrow's Octopus Agile half-hourly
electricity rates, computes a battery charge/discharge schedule from them,
and pushes that schedule to a FoxESS inverter. Runs as a one-shot cron job on
shared hosting — no daemon, no framework, no Composer dependencies (cURL,
JSON, and SQLite are all PHP core/bundled extensions).

It also has a minimal password-walled web UI: a dashboard showing the latest
fetched prices and the resolved charge/discharge schedule, and a settings
page for FoxESS credentials and the system password.

Full spec: [doc/foxess-agile-scheduler-spec.md](doc/foxess-agile-scheduler-spec.md).
The spec covers v1 (cron-only, config-file credentials, no UI); the SQLite
storage and web UI described below are a v2 addition on top of it, done at
the user's request. Read the spec before making structural changes to the
scheduling logic — this file covers what the spec doesn't: decisions made
while building, and things confirmed against live services that were left
open.

## Layout

```
config.php            # non-secret tunables, gitignored — copy of config.example.php
config.example.php    # template, safe to commit
run.php               # cron entry point (supports --dry-run)
index.php             # dashboard (password-walled)
login.php / logout.php
settings.php          # FoxESS credentials + system password form (password-walled)
src/
  Logger.php           # timestamped file logger, rotates past 2MB
  Exceptions.php        # OctopusFetchException / ScheduleBuildException / FoxessPushException
  Store.php             # SQLite connection + settings/rates/schedule/password persistence
  Auth.php              # session-based login gate, built on Store's password check
  Layout.php             # shared HTML header/footer for the web pages
  OctopusClient.php     # fetches half-hourly rates from api.octopus.energy (fetch/parse only, no storage)
  CostBasisProvider.php # resolves the "worth charging below this" reference price
  ScheduleBuilder.php   # rates + cost basis -> FoxESS scheduler groups
  FoxessClient.php      # signs + sends requests to the FoxESS OpenAPI
tests/
  self_check.php        # standalone assert-style test for ScheduleBuilder/CostBasisProvider/Store
logs/
  scheduler.log
  .htaccess              # deny all web access
data/
  scheduler.sqlite       # settings (incl. FoxESS creds + password hash), rate_slots, schedule_groups
  .htaccess               # deny all web access
```

No autoloader — every entry point just `require_once`s the `src/` files it
needs. Keep it that way unless the file count grows enough to justify one;
it hasn't.

No routing framework for the web pages either — `index.php`/`settings.php`/etc.
are plain scripts meant to sit at the same directory level `run.php` already
deploys to (a typical shared host serves the whole app directory as-is, no
separate "public" docroot in the spec's target environment). Don't introduce
a `public/` subfolder without checking that assumption still holds for
wherever this actually gets deployed.

## Data storage: SQLite, no ORM

`src/Store.php` is the entire data layer — plain `PDO` + hand-written SQL,
three tables, `CREATE TABLE IF NOT EXISTS` run on every connection instead of
a migration system (the schema is small and stable enough that idempotent
DDL is simpler than tracking migrations).

- **`settings`** — plain key/value (`key TEXT PRIMARY KEY, value TEXT`).
  Holds `foxess_api_key`, `foxess_device_sn`, `system_password_hash`. A
  key/value table was chosen over typed columns because the set of "small
  bits of config the UI can edit" is exactly the kind of thing that grows
  over time (see roadmap) — a new setting is a new row, not a migration.
- **`rate_slots`** — the latest Octopus fetch. `saveRateSlots()` deletes and
  re-inserts on every call, mirroring the old `last_rates.json`
  latest-fetch-only semantics. It is *not* an accumulating history table,
  even though nothing would break if it were — don't repurpose it as one
  without deciding on retention first (see roadmap's history/reporting item).
- **`schedule_groups`** — the latest schedule actually pushed to FoxESS.
  Same replace-not-append pattern. This is also what `run.php` diffs the
  freshly-computed schedule against to decide whether to skip a no-op push.

`Store::db()` takes an optional, "sticky" path override specifically so
`tests/self_check.php` can point the whole module at a throwaway SQLite file
— call `db($somePath)` once and every other Store function's internal
no-arg `db()` call reuses that connection. **Never run the test suite
against the real `data/scheduler.sqlite`** — `saveRateSlots`/`saveSchedule`
truncate on every call, so doing so would wipe the live dashboard data.

## Request flow (`run.php`)

1. Load `config.php` (non-secret tunables only — see Config & secrets below).
2. `OctopusClient::fetchRatesForDate()` — GET rates for tomorrow (local
   midnight-to-midnight, converted to UTC for the API query). Throws
   `OctopusFetchException` on transport failure *or* on fewer than 48 slots
   (rates not fully published yet).
3. `saveRateSlots()` — persisted to SQLite immediately, even in `--dry-run`
   (it's just a record of what was fetched, and it's what powers the
   dashboard).
4. `CostBasisProvider::getCostBasis()` — 48 values (currently flat, `fixed`
   mode) to compare Agile rates against.
5. `ScheduleBuilder::build()` — pure price-threshold logic, no I/O. Produces
   `{groups: [...]}`.
6. `--dry-run` stops here and prints the computed schedule — no FoxESS
   credentials are even read.
7. Diff the computed groups against `getLatestSchedule()`; skip the FoxESS
   call if unchanged.
8. Read `foxess_api_key`/`foxess_device_sn` from `Store` (via `getSetting()`)
   — throws `FoxessPushException` with a pointer to `settings.php` if either
   is empty.
9. `FoxessClient::pushSchedule()` — signs and POSTs to
   `/op/v1/device/scheduler/enable`.
10. On success, `saveSchedule()` persists the new schedule and logs a
    summary. On any failure, log at ERROR, best-effort email
    `notify.alert_email`, exit 1 so cron surfaces the failure.

## Web UI & auth

Three pages, no JS framework, inline CSS via `src/Layout.php`:

- **`login.php`** — single password field, checked via
  `Store::verifySystemPassword()`. No password has been set until someone
  visits `settings.php` and sets one — until then the literal password is
  `foxhole` (`Store::DEFAULT_SYSTEM_PASSWORD`). There is deliberately no
  brute-force protection (no lockout, no rate limit, no captcha) — this is a
  single-user hobby tool, not proportionate to add without being asked. If
  this ever gets exposed somewhere less trusted than "my own inverter's
  control panel," add one.
- **`index.php`** — reads the latest `rate_slots` + `schedule_groups`,
  resolves each half-hour slot's mode by checking which schedule group (if
  any) its local start time falls in (`slotWorkMode()` in `index.php`), and
  renders one unified table. Deliberately merges prices and schedule into a
  single view rather than two separate tables — that merge *is* the
  "quick glance" the UI exists for.
- **`settings.php`** — FoxESS `api_key`/`device_sn` (pre-filled from
  `Store`, plain text — the user themself set them, no reason to hide them
  from themself) and an optional password change (blank = unchanged,
  8-char minimum, must be confirmed twice). No CSRF token — same reasoning
  as the brute-force point above.

Auth is a native PHP session (`src/Auth.php`, `session_start()` +
`$_SESSION['authed']`) — no token store, no "remember me," nothing custom.
`requireLogin()` at the top of a page redirects to `login.php` if there's no
session.

**Password sent in cleartext if the host isn't serving HTTPS** — this app
doesn't force a redirect to HTTPS itself (shared-host TLS setups vary too
much to assume one). Put it behind HTTPS at the host level; don't rely on
this app for that.

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

**FoxESS credentials live in SQLite, not `config.php`.** User-requested
change from the original spec, so they're editable from `settings.php`
without touching a file on the server. `config.php` keeps everything that
isn't secret and isn't meant to be UI-editable (Octopus product/tariff
codes, battery/strategy tunables, `foxess.base_url`, notification email).

**`data/` and `logs/` get a deny-all `.htaccess`.** Necessary now in a way it
wasn't for the old JSON files: `data/scheduler.sqlite` holds the FoxESS API
key and the password hash, and on the flat "whole directory is the docroot"
deployment this app targets, anything not explicitly blocked is
web-servable as a raw file download. This is an Apache-specific mitigation
(`mod_authz_core`/`mod_access_compat`, both essentially universal on cPanel
hosts) — if this ever gets deployed on nginx or similar, it needs an
equivalent `location` block instead; `.htaccess` does nothing there.

## Config & secrets

`config.php` is real and gitignored; `config.example.php` is the committed
template — both hold only non-secret tunables now (FoxESS `api_key`/
`device_sn` moved to `data/scheduler.sqlite`, managed via `settings.php`).
If you add a new config key, update both files and the shape described in
the spec's §4. If you add a new *secret*, it belongs in the `settings` table
via `Store`, not in `config.php`.

## Running

```bash
php -l run.php src/*.php *.php     # syntax check
php tests/self_check.php           # ScheduleBuilder / CostBasisProvider / Store logic
php run.php --dry-run              # full pipeline against live Octopus API, no FoxESS write, no DB creds needed
php -S localhost:8000              # serve the UI locally — visit /login.php, default password "foxhole"
```

`tests/self_check.php` is plain PHP with a local `check()` helper — not
`assert()`, because `assert()`'s behaviour depends on `zend.assertions` in
`php.ini` and shared hosts often disable it in production, which would make
an assert-based test silently do nothing. If you add logic with a branch or
loop worth protecting, extend this file rather than reaching for a test
framework. It uses a throwaway SQLite file (see the `Store::db()` note
above) — never point it at the real database.

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
- **More settings-table config**: if more of `config.php` ends up needing a
  UI (see roadmap), it follows the same pattern `foxess_api_key` already
  does — `getSetting()`/`setSetting()`, no schema change needed for a plain
  scalar value.

## Out of scope (spec §12, still true post-UI — don't build these without a scope conversation)

Solar generation forecasting, multi-inverter support, historical cost
analytics/reporting (note: `rate_slots`/`schedule_groups` are replace-only,
*not* an accumulating history — see Data storage above), retry/backoff
beyond one attempt.
