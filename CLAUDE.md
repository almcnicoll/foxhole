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
run.php               # cron entry point (CLI-only, supports --dry-run)
run-now.php           # manual trigger for the same pipeline (login-only, POST-only)
index.php             # dashboard (password-walled)
login.php / logout.php
settings.php          # FoxESS credentials + system password form (password-walled)
src/
  Logger.php           # timestamped file logger, rotates past 2MB
  Exceptions.php        # OctopusFetchException / ScheduleBuildException / FoxessPushException
  Runner.php             # runScheduler(): the fetch -> build -> (push) pipeline, shared by run.php and run-now.php
  Store.php             # SQLite connection + settings/rates/schedule/password persistence
  Auth.php              # session-based login gate, built on Store's password check
  Layout.php             # shared HTML header/footer for the web pages
  OctopusClient.php     # fetches half-hourly rates from api.octopus.energy (fetch/parse only, no storage)
  PriceProvider.php     # resolves import/export prices, per-side API-vs-fixed (settings.php)
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
  Holds `foxess_api_key`, `foxess_device_sn`, `system_password_hash`,
  `{import,export}_price_mode`, `{import,export}_price_fixed_pence`,
  `schedule_summary` (the latest day-level explanation sentence — see
  "Cost-optimising ScheduleBuilder" below). A key/value table was chosen
  over typed columns because the set of "small bits of config/state the app
  needs a single current value for" is exactly the kind of thing that grows
  over time (see roadmap) — a new entry is a new row, not a migration.
- **`rate_slots`** — the latest fetch, both `import_rate_pence` (purchase)
  and `export_rate_pence` (sale, nullable — see `PriceProvider` below).
  `saveRateSlots()` deletes and re-inserts on every call, mirroring the old
  `last_rates.json` latest-fetch-only semantics. It is *not* an accumulating
  history table, even though nothing would break if it were — don't
  repurpose it as one without deciding on retention first (see roadmap's
  history/reporting item). This table's disposability is also why its one
  schema change so far (splitting `rate_pence` into two columns) was handled
  as a guarded `DROP TABLE` + recreate in `Store::db()` rather than a real
  migration — there's never anything in it worth preserving across a schema
  change.
- **`schedule_groups`** — the latest schedule actually pushed to FoxESS,
  plus a per-group `explanation` (nullable TEXT, added the same
  disposable-table way as `rate_slots`' column change above). Same
  replace-not-append pattern. This is also what `run.php` diffs the
  freshly-computed schedule against to decide whether to skip a no-op push
  — `getLatestSchedule()`'s `groups` key deliberately excludes `explanation`
  so that diff stays about what's actually sent to FoxESS, not wording.

`Store::db()` takes an optional, "sticky" path override specifically so
`tests/self_check.php` can point the whole module at a throwaway SQLite file
— call `db($somePath)` once and every other Store function's internal
no-arg `db()` call reuses that connection. **Never run the test suite
against the real `data/scheduler.sqlite`** — `saveRateSlots`/`saveSchedule`
truncate on every call, so doing so would wipe the live dashboard data.

## Request flow (`src/Runner.php`'s `runScheduler()`)

The actual fetch → build → (push) pipeline lives in one function,
`runScheduler(bool $dryRun): array`, shared by two entry points with two
different trust gates — see below. It never calls `exit()`; it always
returns `['ok' => bool, 'dryRun' => bool, 'message' => string, 'schedule' => ?array]`
and lets the caller decide what to do with that (an exit code for cron, an
on-screen message for the UI).

1. Load `config.php` (non-secret tunables only — see Config & secrets below).
2. `PriceProvider::resolveImport()` — tries **tomorrow** first (local
   midnight-to-midnight); if that throws `OctopusFetchException` (transport
   failure, or zero slots because Agile hasn't published at all yet), logs
   an INFO line and retries for **today** instead. Whichever date wins
   becomes `$targetDate` for the rest of the run. See "Either side of
   midnight" below for why this exists, and "Partial-day data is normal, not
   a failure" for why the trigger is *zero* slots, not *fewer than 48*.
3. `saveRateSlots()` — persisted to SQLite immediately, even in `--dry-run`
   (it's just a record of what was fetched, and it's what powers the
   dashboard). Export prices are aligned to import's slots by timestamp, not
   array position — see "Partial-day data is normal" below for why that
   matters.
4. `CostBasisProvider::getCostBasis()` — 48 values (currently flat, `fixed`
   mode) to compare Agile rates against.
5. `ScheduleBuilder::build()` — price/arbitrage-threshold logic, no I/O.
   Produces `{groups: [...], explanations: [...], summary: '...'}`. See
   "Cost-optimising ScheduleBuilder" below for the actual selection rules.
6. `--dry-run` stops here and returns the computed schedule — no FoxESS
   credentials are even read.
7. Diff the computed groups against `getLatestSchedule()`; skip the FoxESS
   call if unchanged.
8. Read `foxess_api_key`/`foxess_device_sn` from `Store` (via `getSetting()`)
   — throws `FoxessPushException` with a pointer to `settings.php` if either
   is empty.
9. `FoxessClient::pushSchedule()` — signs and POSTs to
   `/op/v1/device/scheduler/enable`.
10. On success, `saveSchedule()` persists the new schedule and logs a
    summary. On any failure, log at ERROR and best-effort email
    `notify.alert_email` — both happen inside `runScheduler()` itself, so
    they're identical regardless of which entry point called it.

**Two entry points, two trust gates, same pipeline:**

- **`run.php`** — CLI-only (`PHP_SAPI !== 'cli'` check, first thing in the
  file, before `$argv` is even read, since `$argv` doesn't exist outside
  CLI). Discovered this needed enforcing when a browser hit to
  `https://almcnicoll.co.uk/foxhole/run.php` produced a fatal error
  (`in_array('--dry-run', null)` — `$argv` is `null` under FastCGI — throws a
  `TypeError` on PHP 8). The deeper issue: without the guard, that URL was
  reachable with **no authentication at all** and would trigger a real push
  to the inverter. It's a thin wrapper now — parse `--dry-run`, call
  `runScheduler()`, translate the result to stdout/stderr and an exit code.
- **`run-now.php`** — the "Run now" button on the dashboard. Gated by
  `requireLogin()` instead of the CLI check (same session/password system as
  the rest of the UI), and POST-only (a GET — a stray link, a crawler,
  browser prefetch — doesn't trigger a live push). Always a real run, never
  `--dry-run` — redirects back to `index.php?ran=1&ok=…&msg=…` with the
  result, which `index.php` renders as a banner. No CSRF token, same
  reasoning as `settings.php` below — but this one has real-world
  consequences (an actual inverter push) that a settings change doesn't, so
  it's the first thing to add a token to if this ever gets hardened.

**Cron setup** (spec §10's crontab line assumes raw cron access; on Plesk-
style panel hosting, "Scheduled Tasks" in the domain's control panel does the
same job — action type "Run a PHP script" or "Run a command", pointing at
`php /path/to/run.php` on whatever schedule you pick, typically ~17:00 UK
time to clear Octopus's ~16:00 publish time with some buffer). To trigger a
run outside that schedule, use the dashboard's "Run now" button — that's the
supported way now; there's still no browser-based way to hit `run.php`
directly, by design (see above).

## Web UI & auth

No JS framework, inline CSS via `src/Layout.php`:

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
  "quick glance" the UI exists for. Below that, a "Why these decisions?"
  section lists the day summary (`settings.schedule_summary`) and each
  group's stored explanation, in the same order as the schedule table reads
  top-to-bottom. Also has the "Run now" button (a plain POST form to
  `run-now.php`) and, after that redirects back, a result banner read from
  `?ran=1&ok=…&msg=…` — no session flash-message plumbing,
  just query-string state, which is enough for a once-in-a-while manual
  action.
- **`settings.php`** — FoxESS `api_key`/`device_sn` (pre-filled from
  `Store`, plain text — the user themself set them, no reason to hide them
  from themself), import/export price source (API vs. fixed pence/kWh, one
  `<select>` + one `<input>` per side, no JS toggling — the fixed-price input
  is simply ignored server-side when mode is `api`), and an optional password
  change (blank = unchanged, 8-char minimum, must be confirmed twice). No
  CSRF token — same reasoning as the brute-force point above.

Auth is a native PHP session (`src/Auth.php`, `session_start()` +
`$_SESSION['authed']`) — no token store, no "remember me," nothing custom.
`requireLogin()` at the top of a page redirects to `login.php` if there's no
session.

**Password sent in cleartext if the host isn't serving HTTPS** — this app
doesn't force a redirect to HTTPS itself (shared-host TLS setups vary too
much to assume one). Put it behind HTTPS at the host level; don't rely on
this app for that.

## Decisions made while building (things the spec left open)

**Either side of midnight: tomorrow-first, today-as-fallback.** User-requested
change from the original "always target tomorrow" behaviour. `runScheduler()`
tries tomorrow, and on any `OctopusFetchException` (almost always "not
published yet" — Agile publishes ~16:00) retries the whole fetch for today
instead. This is what makes "Run now" actually useful for daytime testing
(previously it just failed with "not published yet" any time before ~16:00,
which was most of the day), and means a missed cron run gets caught up
automatically next time cron *does* fire, rather than silently doing nothing
until the following evening.

**Partial-day data is normal, not a failure.** Originally assumed "today can't
fail the completeness check — it was 'tomorrow' as of yesterday's publish, so
it's always complete by the time today exists." That assumption was wrong,
and caused real, repeated failures in production (`"Octopus returned 46 of 48
slots"`, on both "today" and "tomorrow", days in a row). Investigated with
live requests before touching any code: Octopus's own `count`/`next` fields
confirm this isn't pagination, and a direct query with no date filter showed
their published horizon for a tariff can genuinely end mid-afternoon on
"today" — the last hour or so of a day can still be missing well after that
day has started. This is just how the live feed behaves, not something in
our control. Fix: `OctopusClient` no longer throws on `count($slots) < 48` —
it logs a WARN and returns whatever it has (down to zero). `PriceProvider`
throws `OctopusFetchException` only when a fetch comes back completely empty
(genuinely nothing usable, e.g. tomorrow before publish) — that's the one
case that still triggers the tomorrow→today fallback above. A partial day
(e.g. 46/48) is used as-is: `ScheduleBuilder` never assumed exactly 48 slots
to begin with, so the missing hour simply stays on `SelfUse` until a later
run has full data for it.

One consequence worth knowing: import and export are fetched independently,
and now routinely have *different* slot counts (import might be a same-day
partial 46; fixed-mode export always generates a clean 48 covering the whole
day). `Runner.php` aligns them by matching each import slot's timestamp
against export's, not by raw array position/count — and matches by
`getTimestamp()` specifically, not a formatted string, because import's
slots are UTC (from Octopus) while fixed-mode export's are built in the
configured local timezone; the same instant formats differently between the
two, so a naive string comparison silently fails every lookup. If any import
slot has no matching export entry, export is dropped for that run (logged as
a WARN) rather than risking a misaligned zip.

One cosmetic side effect worth knowing about: `schedule_groups.for_date` only
updates when a push actually happens — if a same-day fallback push and a
later proper tomorrow-targeted push produce byte-identical groups (a real
possibility, since FoxESS's own schedule payload has no date field, just
recurring hour/minute windows), the second run's diff-against-`getLatestSchedule()`
sees no change and skips, leaving `for_date` on the dashboard one day
"behind" even though the inverter's actual applied schedule is correct
either way. Not worth solving — it's a label, not a functional bug.

**Export price failures don't block a push; import price failures do.**
`PriceProvider::resolveImport()`/`resolveExport()` share the same API-vs-fixed
logic, but `Runner.php` treats their failures very differently: import is on
the critical path (`ScheduleBuilder` can't run without it) so an
`OctopusFetchException` there aborts the run exactly like before this feature
existed. Export *does* now feed real scheduling decisions (arbitrage and
discharge timing — see "Cost-optimising ScheduleBuilder" below), but a
missing export price is still treated as "degrade gracefully," not "abort":
its failure is caught, logged as a WARN, and passed to `ScheduleBuilder` as
`null` for that run. `ScheduleBuilder` falls back to cost-basis-only charging
and import-price-based discharge selection when that happens — the same
behaviour the app had before export prices existed at all. A day without
export data still gets a sensible schedule; it just can't arbitrage or chase
the export peak until export data is available again.

**Export defaults to fixed 12p, import defaults to live Agile.** Matches
reality for most FoxESS+Agile owners: dynamic import, flat export rate. Both
are fully overridable independently via `settings.php` — flip either mode
without touching the other. The Agile Outgoing product/tariff codes
(`AGILE-OUTGOING-19-05-13` / `E-1R-AGILE-OUTGOING-19-05-13-C` for London) are
confirmed live and wired up in `config.php`, but only get called if someone
actually switches export to `api` mode.

**Cost-optimising `ScheduleBuilder`: a greedy, explainable heuristic, not a
solver.** User-requested upgrade from pure "cheapest N below cost basis /
priciest M overall" threshold logic to something that reasons about import
*and* export price together. Deliberately still a greedy heuristic, not an
LP/global optimiser: the output has to come with a plain-English explanation
per decision (see below), and an optimiser's output is much harder to narrate
honestly than "we picked this because X" rules. Four rules, each a fairly
direct translation of a specific ask:

- *Charge when cheap* (unchanged): below `cost_basis` for that slot.
- *Charge for arbitrage*: **or** below the day's best export rate, even if
  above cost basis — if you can buy at 11p and the best you'd ever be paid to
  sell is 12p, that's a profitable trade regardless of what your "normal"
  rate is. This is a new, independent OR-condition alongside cost basis, not
  a replacement for it — see `chargeCandidates` in `ScheduleBuilder::build()`.
- *Full battery before the peak*: when there are more charge candidates than
  `cheap_slots_to_charge` allows, candidates before today's single most
  expensive import slot are used up before candidates after it (two sorted
  lists, pre-peak concatenated before post-peak, then sliced to the cap —
  see the `$preIndexes`/`$postIndexes` split). Cheapest-first is still the
  tiebreaker *within* each half; this only matters when the cap would
  otherwise force a choice between an equally-cheap pre- and post-peak slot.
- *Sell at the export peak, but only if there is one*: discharge slots are
  ranked by export rate descending — **if** export price actually varies
  today (`max - min > 0`). If it's flat (the common case: default export
  mode is a fixed 12p), there's no "best time to sell," so discharge falls
  back to the original behaviour — ranked by import price descending, i.e.
  offsetting the most expensive grid-import slots instead. This is the literal
  reading of "if export rate is variable ... force-sell when highest": the
  variability check is what decides which ranking key is used, not just
  whether export data exists at all.

**No live battery SoC — the "spare energy" question is decided by battery
capacity/power maths, not tracked state.** The FoxESS API isn't queried for
the inverter's actual current charge level anywhere in this app (the spec's
`real/query` endpoint that could provide it is unused). `ScheduleBuilder`
doesn't track a running kWh balance across the day either. Both charge and
discharge slot counts are still capped by the plain `cheap_slots_to_charge`/
`expensive_slots_to_export` config values (see "cheap_slots_to_charge is a
plain config cap" above) — there's no check that discharge slots don't
promise to export more energy than was actually charged. In practice this
mostly self-corrects (Agile's daily shape means cheap import and expensive
export rarely swap places), and FoxESS's own firmware will just discharge
whatever's actually available rather than erroring — but if this ever needs
tightening, fetching real SoC via `real/query` and simulating a running
balance through the day is the natural next step. Flagged in roadmap.MD.

**Explanations are generated from the same reason data used to select slots,
not re-derived from raw numbers after the fact.** `ScheduleBuilder::build()`
threads the *actual* reason (`cost_basis`, `arbitrage`, or which rate series
drove discharge selection) through to `explainPeriods()`, rather than having
the explanation function independently re-check thresholds. This matters:
if explanation and selection logic ever drifted apart (e.g. selection using
`exportIsVariable` but explanation re-deriving "was export used" from
`$exportRates !== null`), the sentence shown could describe a rule that
wasn't actually the one applied — an early draft had exactly this bug for
the flat-export case. One sentence per emitted group (`explanations`, same
order as `groups`) plus one day-level `summary` about the import peak.
Persisted in `schedule_groups.explanation` (one column, nullable, dropped
alongside the rest of that row on every push — same disposable-table pattern
as `rate_slots`) and `settings.schedule_summary` (a single value, reusing the
existing key/value table rather than adding new schema for one string).
`getLatestSchedule()['groups']` deliberately excludes explanation text — it's
what the no-op-push diff compares, and wording drift shouldn't trigger a
re-push when the actual schedule hasn't changed. `index.php` renders both
under "Why these decisions?" below the price table.

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
- **Scheduling algorithm**: `ScheduleBuilder::build()` is a greedy,
  explainable price/arbitrage heuristic by design (see "Cost-optimising
  ScheduleBuilder" above), not a global optimiser — spec §7 wants
  price-threshold logic, and explanations need to be narratable. A smarter
  version (solar forecast, load-aware, real SoC tracking) would replace this
  method's body without touching its inputs/outputs — `run.php` and
  `FoxessClient` don't need to change.
- **More settings-table config**: if more of `config.php` ends up needing a
  UI (see roadmap), it follows the same pattern `foxess_api_key` already
  does — `getSetting()`/`setSetting()`, no schema change needed for a plain
  scalar value.

## Out of scope (spec §12, still true post-UI — don't build these without a scope conversation)

Solar generation forecasting, multi-inverter support, historical cost
analytics/reporting (note: `rate_slots`/`schedule_groups` are replace-only,
*not* an accumulating history — see Data storage above), retry/backoff
beyond one attempt.
