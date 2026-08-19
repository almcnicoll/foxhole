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
run.php               # cron entry point (CLI-only, supports --dry-run/--classic/--intelligent)
run-now.php           # manual trigger for the same pipeline (login-only, POST-only)
cron.php              # web-triggerable cron alternative (secret-token-gated, GET) for hosts with no CLI cron
index.php             # dashboard (password-walled)
login.php / logout.php
settings.php          # FoxESS credentials + system password form (password-walled)
schedulers.php         # pick/preview the active scheduler (password-walled), see "Pluggable schedulers" below
history.php            # generation-vs-forecast history, day/week/month/year (password-walled)
api-log.php            # every FoxESS API call, most recent first (password-walled), see "API call log" below
history-fetch.php      # manual trigger for the generation history backfill/catch-up (login-only, POST-only)
assets/
  style.css            # the only stylesheet — every page links this, no per-page CSS
src/
  Logger.php           # timestamped file logger, rotates past 2MB
  Exceptions.php        # OctopusFetchException / ScheduleBuildException / FoxessPushException
  Runner.php             # runScheduler(): the fetch -> build -> (push) pipeline, shared by run.php, run-now.php, and cron.php
  Store.php             # SQLite connection + settings/rates/schedule/password persistence
  Auth.php              # session-based login gate, built on Store's password check
  Layout.php             # shared HTML header/footer for the web pages
  AssetVersion.php       # ASSET_VERSION cache-busting constant, see "Asset versioning"
  OctopusClient.php     # fetches half-hourly rates from api.octopus.energy (fetch/parse only, no storage)
  PriceProvider.php     # resolves import/export prices, per-side API-vs-fixed (settings.php)
  CostBasisProvider.php # resolves the "worth charging below this" reference price
  ScheduleBuilder.php   # rates + cost basis -> FoxESS scheduler groups (the "classic" scheduler)
  IntelligentScheduleBuilder.php # solar/usage/SoC-aware scheduler (the "forecast-weighted" scheduler)
  ModellingScheduleBuilder.php # exact DP/Bellman solver over discretised SoC bins (the "modelling" scheduler)
  HalfHourlyUsageEstimator.php # half-hour-by-half-hour usage forecast sampled from historic_generation.usage_kwh
  Schedulers.php        # pluggable scheduler registry — see "Pluggable schedulers" below
  FoxessClient.php      # signs + sends requests to the FoxESS OpenAPI
  HistoryFetcher.php    # backfills/catches up historic_generation (generation + usage) from FoxESS's report/query endpoint
tests/
  self_check.php        # standalone assert-style test for ScheduleBuilder/CostBasisProvider/Store
logs/
  scheduler.log
  .htaccess              # deny all web access
data/
  scheduler.sqlite       # settings (incl. FoxESS creds + password hash), price_slots, schedule_groups
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
`CREATE TABLE IF NOT EXISTS` run on every connection instead of a migration
system (the schema is small and stable enough that idempotent DDL is
simpler than tracking migrations).

- **`settings`** — plain key/value (`key TEXT PRIMARY KEY, value TEXT`).
  Holds `foxess_api_key`, `foxess_device_sns` (newline-separated — see
  "Multi-inverter support" below), `system_password_hash`,
  `{import,export}_price_mode`, `{import,export}_price_fixed_pence`,
  `scheduler_id` (see "Pluggable scheduler architecture"). A key/value table
  was chosen over typed columns because the set of "small bits of
  config/state the app needs a single current value for" is exactly the
  kind of thing that grows over time (see roadmap) — a new entry is a new
  row, not a migration.
- **`price_slots`** — every known import/export price slot, permanently
  (`import_rate_pence`, `export_rate_pence` nullable — see `PriceProvider`
  below), keyed by `slot_from`. Replaced the old disposable `rate_slots`
  table (GitHub issue #4, "Date-time-aware scheduling") — see that section
  further down for the full story of why and how. `Store::upsertPriceSlots()`
  upserts by `slot_from` rather than replacing wholesale; import always
  overwrites, export only overwrites when the incoming value is non-null, so
  a run that couldn't resolve export prices can never erase an already-known
  one for the same slot.
- **`schedule_groups`** — the latest schedule actually pushed to FoxESS, one
  row set *per calendar date* (`for_date`), plus a per-group `explanation`
  (nullable TEXT, added the same disposable-table way `rate_slots` — this
  table's own previous incarnation — got its export column: a guarded
  `DROP TABLE` + recreate in `Store::db()`, not a real migration, since
  there's never anything in it worth preserving across a schema change).
  Each date's rows are replaced wholesale on every recompute for that date,
  not appended — the table is per-date-disposable, not a full history (see
  "Date-time-aware scheduling" for why that's still the right call even
  though prices themselves are now permanent). `schedule_groups.groups`
  (via `getScheduleForDate()`) deliberately excludes `explanation` from what
  the no-op-push diff compares, so wording drift alone never triggers a
  re-push.
- **`schedule_summaries`** — one day-level summary sentence per known date
  (`for_date TEXT PRIMARY KEY, summary TEXT`), upserted per date. Replaced
  the old single global `schedule_summary` setting, which only ever fit one
  "target date" per run — see "Date-time-aware scheduling".

`Store::db()` takes an optional, "sticky" path override specifically so
`tests/self_check.php` can point the whole module at a throwaway SQLite file
— call `db($somePath)` once and every other Store function's internal
no-arg `db()` call reuses that connection. **Never run the test suite
against the real `data/scheduler.sqlite`** — `saveSchedule()` truncates
each date's rows on every call, so doing so would wipe live dashboard data.

## Request flow (`src/Runner.php`'s `runScheduler()`)

The actual fetch → build → (push) pipeline lives in one function,
`runScheduler(bool $dryRun): array`, shared by two entry points with two
different trust gates — see below. It never calls `exit()`; it always
returns `['ok' => bool, 'dryRun' => bool, 'message' => string, 'schedule' => ?array]`
and lets the caller decide what to do with that (an exit code for cron, an
on-screen message for the UI).

1. Load `config.php` (non-secret tunables only — see Config & secrets below).
2. `PriceProvider::resolveImport()`/`resolveExport()` — attempts **both**
   today and tomorrow (local midnight-to-midnight) every run, not "tomorrow,
   falling back to today" (see "Date-time-aware scheduling" below for why
   that changed). A day whose `OctopusFetchException` fires (transport
   failure, or zero slots because Agile hasn't published yet — the usual
   case for tomorrow before ~16:00) is just skipped for that run; only
   *neither* day producing anything at all is a hard failure. See
   "Partial-day data is normal, not a failure" for why the trigger is *zero*
   slots for a day, not *fewer than 48*.
3. `Store::upsertPriceSlots()` — each day's slots persisted immediately as
   they're fetched, even in `--dry-run` (it's just a record of what was
   fetched, and it's what powers the dashboard/Schedulers preview). Export
   prices are aligned to import's slots by timestamp, not array position —
   see "Partial-day data is normal" below for why that matters.
4. `Store::getPriceSlotsFrom($today)` — every currently-known slot from
   local midnight today onward (may include slots from earlier runs, not
   just this one), split into calendar-day chunks.
5. `CostBasisProvider::getCostBasis()` — one call per day chunk (currently
   flat, `fixed` mode) to compare that day's Agile rates against.
6. `resolveSchedulerId()` + `buildMultiDaySchedule()` (`src/Schedulers.php`,
   see "Pluggable scheduler architecture" and "Date-time-aware scheduling"
   below) — resolves which scheduler is selected (`schedulers.php`, or a CLI
   override) and calls its `build()` once per known day, carrying
   `IntelligentScheduleBuilder`'s projected `finalSocPercent` from each day
   into the next. Produces one `{groups: [...], explanations: [...],
   summary: '...'}` per date. See "Cost-optimising ScheduleBuilder" below
   for `ScheduleBuilder`'s own per-day selection rules specifically
   (`IntelligentScheduleBuilder`'s are documented in its own class doc
   comment).
7. `--dry-run` stops here and returns the computed per-date schedules — no
   FoxESS credentials are even read.
8. `saveSchedule()`/`upsertScheduleSummary()` per date, then
   `ScheduleBuilder::buildPushWindow()` derives exactly what actually gets
   pushed: from the start of the current hour through 24h ahead or the end
   of known pricing, whichever is sooner (see "Date-time-aware scheduling").
   Diffed against the last *pushed* window (not any single date's raw plan)
   to decide whether to skip a no-op push (currently disabled, see below).
9. Read `foxess_api_key`/`foxess_device_sns` from `Store` (via `getSetting()`)
   — throws `FoxessPushException` with a pointer to `settings.php` if the key
   is empty or the device list is empty.
10. `pushToDevices()` — one `FoxessClient` per configured device serial
    number, each signing and POSTing to `/op/v1/device/scheduler/enable`
    independently. See "Multi-inverter support" below for why this loop
    lives in `Runner.php` rather than inside `FoxessClient` itself.
11. On success, logs a summary of what was pushed and to how many devices
    (`saveSchedule()`/`upsertScheduleSummary()` already ran in step 8, before
    the push, so the dashboard/Schedulers page has a record even if the push
    itself fails or is only partial). On any failure, log at ERROR and
    best-effort email `notify.alert_email` — both happen inside
    `runScheduler()` itself, so they're identical regardless of which entry
    point called it.

**Three entry points, three trust gates, same pipeline:**

- **`run.php`** — CLI-only (`PHP_SAPI !== 'cli'` check, first thing in the
  file, before `$argv` is even read, since `$argv` doesn't exist outside
  CLI). Discovered this needed enforcing when a browser hit to
  `https://almcnicoll.co.uk/foxhole/run.php` produced a fatal error
  (`in_array('--dry-run', null)` — `$argv` is `null` under FastCGI — throws a
  `TypeError` on PHP 8). The deeper issue: without the guard, that URL was
  reachable with **no authentication at all** and would trigger a real push
  to the inverter. It's a thin wrapper now — parse `--dry-run`/
  `--classic`/`--intelligent`, call `runScheduler()`, translate the result to
  stdout/stderr and an exit code.
- **`run-now.php`** — the "Run now" button on the dashboard. Gated by
  `requireLogin()` instead of the CLI check (same session/password system as
  the rest of the UI), and POST-only (a GET — a stray link, a crawler,
  browser prefetch — doesn't trigger a live push). Always a real run, never
  `--dry-run` — redirects back to `index.php?ran=1&ok=…&msg=…` with the
  result, which `index.php` renders as a banner. No CSRF token, same
  reasoning as `settings.php` below — but this one has real-world
  consequences (an actual inverter push) that a settings change doesn't, so
  it's the first thing to add a token to if this ever gets hardened.
- **`cron.php`** — user-requested alternative to `run.php` for hosts where
  cron can't invoke the PHP CLI at all (only "hit a URL" scheduling is
  available). Gated by a random per-install secret (`settings table's
  cron_token`, 48 hex chars, generated on first view of `settings.php` and
  shown/regeneratable there) checked with `hash_equals()` against a
  `?token=…` query parameter — a scripted cron client can't do the
  session/password login `run-now.php` uses, so the token *is* the
  authentication. Deliberately GET, not POST-only like `run-now.php`: that
  restriction exists specifically to stop an unauthenticated stray
  hit/crawler/prefetch, which isn't a concern here since nothing happens
  without the correct secret. Always a real run, like `run-now.php`. Known,
  accepted caveat of any secret-in-a-URL design: the token can end up in
  host access logs, so treat it like the system password — regenerate if it
  ever leaks, don't paste it anywhere public.

**Cron setup** (spec §10's crontab line assumes raw cron access; on Plesk-
style panel hosting, "Scheduled Tasks" in the domain's control panel does the
same job — action type "Run a PHP script" or "Run a command", pointing at
`php /path/to/run.php` on whatever schedule you pick, typically ~17:00 UK
time to clear Octopus's ~16:00 publish time with some buffer). If the host's
scheduler genuinely can't invoke the PHP CLI (only a "call this URL"
scheduler, e.g. a plain `wget`/`curl` cron job), use `cron.php?token=…`
instead — see above. To trigger a run outside that schedule, use the
dashboard's "Run now" button — that's the supported interactive way; there's
still no *unauthenticated* browser-based way to hit `run.php` directly, by
design (see above).

## Web UI & auth

No JS framework, one shared stylesheet (`assets/style.css`, linked by
`src/Layout.php` — not inlined, not per-page) styled with CSS custom
properties for the royal-purple accent palette, light/dark aware via
`@media (prefers-color-scheme: dark)`. Functional colours (charge/discharge/
self-use row tints, success/warning/error alerts, SoC red/amber/green) stay
their own hues regardless of theme — they carry meaning, so they're not
tinted purple along with everything else.

- **`login.php`** — single password field, checked via
  `Store::verifySystemPassword()`. No password has been set until someone
  visits `settings.php` and sets one — until then the literal password is
  `foxhole` (`Store::DEFAULT_SYSTEM_PASSWORD`). There is deliberately no
  brute-force protection (no lockout, no rate limit, no captcha) — this is a
  single-user hobby tool, not proportionate to add without being asked. If
  this ever gets exposed somewhere less trusted than "my own inverter's
  control panel," add one.
- **`index.php`** — reads `Store::getPriceSlotsFrom($today)` (every known
  price slot from local midnight today onward — may span into tomorrow, see
  "Date-time-aware scheduling") plus `getScheduleForDate()` for every date
  present, merged via `scheduleToAbsoluteIntervals()` into one list of real
  `DateTimeImmutable` intervals. `slotWorkMode(DateTimeImmutable $instant,
  array $absoluteIntervals)` resolves a slot's mode by comparing the actual
  instant against that merged list — deliberately *not* a minute-of-day
  comparison (the pre-issue-#4 version), since "14:00" means something
  different on two different calendar days once more than one can be shown
  at once. Renders one unified table **per known date**, each split into two
  side-by-side columns (`renderSlotTable()` called twice per date — before
  and after local noon, split by local hour, not array index, since a
  partial day's slots aren't always exactly 48) via the `.slot-columns` flex
  layout in `Layout.php`. Deliberately merges prices and schedule into a
  single view rather than further-separate tables — that merge *is* the
  "quick glance" the UI exists for. Each row also gets a `row-{mode}` class
  (subtle background tint — green/red/grey for charge/discharge/self-use)
  alongside the existing per-cell `.badge`, and the Import/Export cells get
  a `.currency` class (monospace, right-aligned). An "Energy plan" section —
  `<h3>`, not `<h2>`; one `<h4>` sub-heading per known date, each with that
  date's own summary (`getScheduleSummary()`) and stored per-group
  explanations — renders *above* the slot tables, and the "Run now" button
  renders *below* it (both user-requested orderings; the button used to sit
  above everything). After a run redirects back, a result banner reads
  `?ran=1&ok=…&msg=…` — no session flash-message plumbing, just query-string
  state, which is enough for a once-in-a-while manual action. It's styled as
  `.alert-success`/`.alert-warning`/`.alert-error`: warning isn't a field
  `runScheduler()` actually returns, it's inferred in `index.php` from the
  message text containing "unchanged" (a successful no-op push reads as
  informational, not a full success) — a small heuristic, not a new
  Runner.php return value, since the message text already carries this
  distinction and duplicating it as a real field would be redundant.

  Top-right of the page header: one battery indicator per configured device
  serial (`renderBatteryStatus()`, native `<progress>` element — no chart
  library, no custom SVG, "native platform feature" was enough), passed into
  `renderHeader()`'s optional `$headerExtra` slot rather than hardcoded into
  the shared header, so other pages can use the same slot later without
  index.php-specific code leaking into `Layout.php`. Fill colour is a
  `soc-red`/`soc-amber`/`soc-green` class computed server-side in PHP, not
  pure CSS — there's no CSS-only way to threshold a `<progress>` element's
  own value into colour bands, so `renderBatteryStatus()` does the
  arithmetic and `style.css` just maps each class to a colour via
  `::-webkit-progress-value`/`::-moz-progress-bar` (the two vendor
  pseudo-elements that actually control fill colour — there's no
  unprefixed standard one). Bands split `getBatteryConfig()['min_soc_on_grid']`
  (the bottom of "red") to 100% (the top of "green") into equal thirds —
  `min_soc_on_grid` specifically, not `reserve_soc`: both represent some
  kind of floor and happen to be equal in the current config, but
  `min_soc_on_grid` is described as the general system floor while
  `reserve_soc` is specifically about how far force-discharge is allowed to
  drain the battery, which reads as the more apt "minimum SoC" for a
  general-purpose indicator.
- **`settings.php`** — FoxESS `api_key`/`device_sns` (pre-filled from
  `Store`, plain text — the user themself set them, no reason to hide them
  from themself; `device_sns` is a `<textarea>`, one serial per line — see
  "Multi-inverter support" below), battery hardware specs (see "Battery
  config moved to settings" below), import/export price source (API vs.
  fixed pence/kWh, one `<select>` + one `<input>` per side, no JS toggling —
  the fixed-price input is simply ignored server-side when mode is `api`),
  and an optional password change (blank = unchanged, 8-char minimum, must
  be confirmed twice). No CSRF token — same reasoning as the brute-force
  point above. Its fieldsets are laid out via `.settings-grid` (`style.css`,
  a plain `display: grid; grid-template-columns: repeat(auto-fit,
  minmax(320px, 1fr))`) instead of one long vertical stack — no JS, each
  fieldset just flows into however many columns the viewport fits, matching
  the app's "simplest thing that works" bias over a JS tab widget.
- **`schedulers.php`** — see "Pluggable schedulers" below for the
  architecture; this page is just its UI. One `.settings-grid` box per
  registered scheduler (`SCHEDULER_DEFINITIONS`, `src/Schedulers.php`),
  each showing its name, description, an "Active" badge (`.badge-active`)
  plus a thicker border (`.scheduler-card-active`) if it's the one
  `resolveSchedulerId()` currently resolves to, a "Use this scheduler"
  button otherwise (POST-redirect-GET, same pattern as settings.php's
  cron-token regenerate — just writes the `scheduler_id` setting, no push),
  and every scheduler's *current recommended schedule for every known
  date* (`getPriceSlotsFrom($today)` — no fresh Octopus call; this page
  previews, it doesn't re-run the pipeline), computed via the same
  `buildMultiDaySchedule()` `Runner.php` uses, but never pushed. Each
  scheduler's build is wrapped in its own try/catch so one throwing (e.g.
  no rates fetched yet) shows an inline error in that box rather than
  breaking the whole page.

Auth is a native PHP session (`src/Auth.php`, `session_start()` +
`$_SESSION['authed']`) — no token store, no "remember me," nothing custom.
`requireLogin()` at the top of a page redirects to `login.php` if there's no
session.

**Password sent in cleartext if the host isn't serving HTTPS** — this app
doesn't force a redirect to HTTPS itself (shared-host TLS setups vary too
much to assume one). Put it behind HTTPS at the host level; don't rely on
this app for that.

## Decisions made while building (things the spec left open)

**Either side of midnight: tomorrow-first, today-as-fallback, later superseded
by fetching both.** User-requested change from the original "always target
tomorrow" behaviour: `runScheduler()` tried tomorrow, and on any
`OctopusFetchException` (almost always "not published yet" — Agile publishes
~16:00) retried the whole fetch for today instead. This is what first made
"Run now" useful for daytime testing (previously it just failed with "not
published yet" any time before ~16:00, which was most of the day). GitHub
issue #4 ("Date-time-aware scheduling") later replaced the either/or with
attempting *both* every run (see "Date-time-aware scheduling" below) — the
daytime-testing and missed-cron-catch-up benefits this decision was for
still hold, just without having to pick exactly one date per run anymore.

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

A cosmetic side effect this used to have — `schedule_groups.for_date` only
updating when a push actually happened, so a same-day-fallback push
followed by a later proper tomorrow-targeted push could leave the
dashboard's `for_date` label a day "behind" even though the inverter's
applied schedule was correct either way — no longer applies: GitHub issue
#4's rework has `saveSchedule()`/`upsertScheduleSummary()` run unconditionally
for every known date on every run (see "Date-time-aware scheduling"), not
gated on whether a push happened for one specific target date.

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
honestly than "we picked this because X" rules. Five rules, each a fairly
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
- *Clear space ahead of each cheap charging window, cheapest window first*:
  user-requested — force-charging correctly happened during a negative-price
  window, but nothing discharged beforehand to make more room for it.
  `findChargingWindows()` finds maximal runs of charge *candidates* (the full
  eligible set, not the capped `$chargeIndexes`) that actually got at least
  one slot selected, ranks them by the average rate of the slots that were
  actually selected within each, and reserves one discharge slot immediately
  before each window's true start — cheapest window first — until
  `expensive_slots_to_export` runs out. Anchoring on the *candidate* window
  rather than the capped selection matters: for a wide cheap block, the cap
  can select from the middle of it, so anchoring on `min($chargeIndexes)`
  directly either lands the reservation inside the still-cheap block itself
  or misses the window's true edge — caught in review before this shipped.
  Reservations share the existing discharge cap rather than a separate
  budget (no new config value) and can consume all of it if there are enough
  cheap windows — by design, not a bug to guard against.
- *Sell at the export peak, but only if there is one*: with whatever
  discharge budget remains after the above, discharge slots are
  ranked by export rate descending — **if** export price actually varies
  today (`max - min > 0`). If it's flat (the common case: default export
  mode is a fixed 12p), there's no "best time to sell," so discharge falls
  back to the original behaviour — ranked by import price descending, i.e.
  offsetting the most expensive grid-import slots instead. This is the literal
  reading of "if export rate is variable ... force-sell when highest": the
  variability check is what decides which ranking key is used, not just
  whether export data exists at all.

**Pluggable scheduler architecture (GitHub issue #2).** User-requested: "a
pluggable architecture where the user can select which scheduling system to
use." Before this, the two scheduling algorithms that already existed —
`ScheduleBuilder` (plain price-threshold heuristic) and
`IntelligentScheduleBuilder` (solar/usage/SoC-aware simulation, added later —
see below) — were wired together through one ad-hoc boolean setting,
`intelligent_scheduler_enabled`, read directly in `Runner.php`. That worked
for exactly two options but didn't generalise, had no user-facing name or
description for either one, and had no way to preview a scheduler's output
before switching to it.

`src/Schedulers.php` is the fix: `SCHEDULER_DEFINITIONS` is an ordered array
of `id => ['name' => ..., 'description' => ...]` — the one place that lists
which schedulers exist and how they're described to the user —
`resolveSchedulerId(?string $override = null)` resolves which one is active
(CLI override, e.g. run.php's `--classic`/`--intelligent` -> the stored
`scheduler_id` setting -> the legacy `intelligent_scheduler_enabled` boolean,
read once as a migration fallback for an install that saved that setting
before this registry existed, same "read the old key once" pattern as
`foxess_device_sns` and battery config's own migration fallbacks -> a
hardcoded default), and `buildScheduleWithScheduler()` dispatches to
whichever class the resolved id maps to. `Runner.php`'s `runScheduler()` and
`reapplyOverrides()` both call through this registry now, instead of each
separately duplicating an `if (intelligent) ... else ...` branch — which
they used to (`reapplyOverrides()` had drifted enough to *always* use
`ScheduleBuilder` regardless of the setting at one point, precisely the kind
of bug a shared dispatch point makes harder to reintroduce).

New user-facing area: **`schedulers.php`** ("Schedulers" in the nav) shows
every registered scheduler as its own boxed card — name, description, an
"Active" badge on whichever `resolveSchedulerId()` currently resolves to,
and a "Use this scheduler" button on the others that just writes the
`scheduler_id` setting (no push, takes effect on the next real run) — plus
each one's *current recommended schedule*, computed from the latest
already-fetched rate slots but never pushed to FoxESS, so you can compare
what each algorithm would actually do before switching. `settings.php`'s old
"Use the intelligent scheduler" checkbox is gone — selection lives on
`schedulers.php` now, per the issue's explicit ask that it be "from a new
area called 'Schedulers'," not folded into general settings.

Deliberately still a plain array + a small `switch`-shaped dispatch
function, not a class-per-scheduler interface: the two original
implementations already existed with genuinely different `build()`
signatures (`IntelligentScheduleBuilder` takes two more parameters, for
solar forecast and current SoC, that `ScheduleBuilder` has no use for)
before this registry did, and forcing a shared interface would mean either
widening one's signature to accept inputs it ignores or narrowing the
other's to lose real ones — busywork, not a real abstraction. Same "greedy
heuristic, not a solver" philosophy as the schedulers themselves (the third
scheduler, added later, breaks that philosophy on purpose — see "Modelling
scheduler" below — and for exactly the same "genuinely different inputs"
reason gets its own parallel dispatch path rather than being folded into
`buildScheduleWithScheduler()`/`buildMultiDaySchedule()`).

**No live battery SoC in `ScheduleBuilder` (the "classic" scheduler) — the
"spare energy" question there is still decided by battery capacity/power
maths, not tracked state.** This is no longer true of every scheduler:
`IntelligentScheduleBuilder` (the "forecast-weighted" one) *does* read
`FoxessClient::getBatterySoc()` (via `Runner.php`, when that scheduler is
selected) and simulate a running kWh balance across the day — see its own
class doc comment. `ScheduleBuilder` still doesn't, and both charge and
discharge slot counts there are still capped by the plain
`cheap_slots_to_charge`/`expensive_slots_to_export` config values (see
"cheap_slots_to_charge is a plain config cap" above) — there's no check that
its discharge slots don't promise to export more energy than was actually
charged. In practice this mostly self-corrects (Agile's daily shape means
cheap import and expensive export rarely swap places), and FoxESS's own
firmware will just discharge whatever's actually available rather than
erroring.

**Date-time-aware scheduling (GitHub issue #4).** User-reported: prices and
schedules were only ever handled one "target date" at a time (`Runner.php`
tried tomorrow, fell back to today), which caused real problems around the
turn of the day — a run could only ever see one day's prices, and the
FoxESS push either represented a spliced today+tomorrow 24h cycle or, when
tomorrow wasn't published yet, pushed today's decisions as if they'd recur
into tomorrow morning too, hours they were never actually computed for.
Three changes, confirmed with the user before building:

1. *Store all prices permanently, with full date/time.* `rate_slots`
   (disposable, replaced wholesale on every fetch) was replaced outright by
   `price_slots` (permanent, upserted by `slot_from`) — see "Data storage"
   above. Confirmed with the user: no separate table kept alongside the old
   one, since there's no distinct "latest fetch" need left once permanent
   storage exists (unlike `solar_forecast` vs `historic_generation`, which
   answer genuinely different questions — forward-looking "current plan" vs
   backward-looking "what happened" — price data doesn't have that
   duality, it's just one growing timeline).
2. *Schedule as far ahead as pricing is available.* `Runner.php` now
   attempts **both** today's and tomorrow's Octopus fetch every run
   (`upsertPriceSlots()`-ing whichever succeed), instead of tomorrow with a
   today fallback. `Store::getPriceSlotsFrom($today)` returns everything
   currently known, split into calendar-day chunks, and — this is the
   single biggest risk-reduction decision in the whole feature, confirmed
   with the user — **`ScheduleBuilder`/`IntelligentScheduleBuilder` run
   completely unchanged, once per calendar day**, via
   `Schedulers.php`'s `buildMultiDaySchedule()`, rather than either
   scheduler's internals being rewritten to reason across a multi-day
   array. Config caps like `cheap_slots_to_charge` stay exactly what they
   were tuned as: a per-day cap, not a per-window one. Accepted limitation:
   each day's arbitrage/peak logic only sees that day's own prices, not
   across the day boundary — reasonable for a first version, not a
   blocking gap. `IntelligentScheduleBuilder::build()` gained an additive
   `finalSocPercent` return key specifically so `buildMultiDaySchedule()`
   can carry each day's projected end-of-day SoC into the next day's
   `$currentSocPercent`, instead of every day independently assuming the
   real live reading (only actually true for day one). `index.php`'s chart
   and slot tables, and `schedulers.php`'s previews, all show every known
   day this way too — see their own entries above.
3. *The actual FoxESS push covers the current hour through 24h ahead, or
   the end of known pricing, whichever is sooner.* FoxESS's schedule format
   has no date field, only recurring hour/minute-of-day, so this is still
   fundamentally a ≤24h window — `ScheduleBuilder::buildPushWindow()`
   replaces the old `spliceForPush()` (which only ever handled the
   today+tomorrow special case) with a version that combines however many
   calendar days currently have a computed schedule into one absolute
   timeline, then slices `[start of current hour, min(+24h, latest known
   `slot_to`))`. Already-elapsed hours of today are naturally excluded
   (the window starts at the current hour, not midnight), and a day with
   no published pricing yet is naturally never pushed past its own known
   horizon — neither needs special-casing "today vs tomorrow" anymore,
   since everything works in real instants throughout. One correctness
   subtlety worth knowing if touching this again: converting the sliced
   window back to FoxESS's hour/minute-of-day fields can't reuse the old
   `intervalsToGroups()` (which assumes "minutes since a conceptual
   midnight", true only when the window itself starts at midnight) —
   `buildPushWindow()` uses a new `absoluteIntervalsToGroups()` that reads
   each interval's real local hour/minute directly off its
   `DateTimeImmutable`, which is what actually makes a non-midnight-aligned
   window (the normal case — most runs happen mid-day) come out correct.
   Recomputing every known day fresh each run, rather than reading a
   previously-*stored* "today" plan back out to splice as the old version
   did, turned out to be safe and simpler: both schedulers are
   deterministic given the same inputs, and `IntelligentScheduleBuilder`
   *should* re-adapt to a changed real battery SoC between runs — that's a
   feature, not drift to guard against.

**Modelling scheduler (GitHub issue #5): an exact DP solver, not a third
heuristic.** User-requested: a third scheduler that actually solves for the
lowest-cost charge/discharge sequence via dynamic programming over a
discretised battery-SoC grid, rather than another set of hand-tuned rules.
Confirmed with the user before building: three discrete per-slot actions
only (force-charge/force-discharge at rated power/idle — no intermediate
power levels); the sub-3-historical-days usage fallback is flat 8am–20:00
only, zero elsewhere; the new tunables it needs
(`round_trip_efficiency_pct`, `modelling_soc_bin_kwh`,
`modelling_min_end_soc_pct`) live in `settings.php`, not `config.php` —
consistent with "a value worth tuning without a deploy shouldn't live
somewhere the user can forget about it" (see "Battery config moved to
settings" above), even though these are new rather than migrated values.

*Rolling window, not per-calendar-day — its own dispatch path, not a branch
inside `buildMultiDaySchedule()`.* Issue #4 deliberately made
`ScheduleBuilder`/`IntelligentScheduleBuilder` run once per **calendar
day**, unchanged, specifically to avoid rewriting either heuristic to
reason across a multi-day array. This issue's own spec asks for the
opposite for itself: "the rest of today plus overnight, up to 48 slots" — a
rolling 24h window from *now*, which routinely crosses a midnight boundary.
Forcing that into the existing per-day loop would contradict the issue
directly, so `Schedulers.php` gives it a parallel pair of functions instead
of a third scheduler branch inside `buildMultiDaySchedule()`:
`buildModellingScheduleForRun()` computes the same rolling window
`ScheduleBuilder::buildPushWindow()` already computes for the actual push —
`[start of current hour, min(+24h, latest known price horizon)]` — slices
`getPriceSlotsFrom()`'s results to it, builds the half-hourly usage forecast
for whichever calendar date(s) the window touches, and calls
`buildModellingSchedule()`, which runs `ModellingScheduleBuilder::build()`
**once** over the whole window (its output is absolute-instant intervals,
the same `{start, end, workMode, explanation}` shape
`buildPushWindow()`/issue #4 already established for anything that can
cross a calendar-date boundary — see "Date-time-aware scheduling" above),
then clips those intervals at each touched date's midnight boundary and
converts each date's slice via `ScheduleBuilder::absoluteIntervalsToGroups()`
(changed from `private` to `public` specifically for this reuse) so the
result drops into the exact same `$scheduleByDate` shape every other caller
(`saveSchedule()`, `buildPushWindow()`, `index.php`, `schedulers.php`)
already expects. `Runner.php`'s `runScheduler()` and `reapplyOverrides()`,
and `schedulers.php`'s preview loop, each get one
`if ($schedulerId === 'modelling') { buildModellingScheduleForRun(...) }
else { buildMultiDaySchedule(...) }` branch at the point they already called
`buildMultiDaySchedule()` — the same "special-case per scheduler at the call
site" pattern `Runner.php` already used for gathering live SoC/solar only
when the forecast-weighted scheduler is selected, just extended to also
cover this one.

*New data: half-hourly household usage history, from FoxESS's `loads`
variable.* Nothing in this app tracked household consumption before this —
`historic_generation` only had `generation_kwh`/`forecast_kwh`. Researched
live (same "check community references before building" pattern as
`fdSoc`/`fdPwr` and the SoC field names elsewhere in this file): FoxESS's
`report/query` endpoint — the same one `FoxessClient::getGenerationReport()`
already calls — also supports a `loads` variable for hourly household
consumption, same response shape as `generation`. Known caveat, forum-
confirmed, not independently verified against this project's own hardware:
OpenAPI `loads` can undercount vs the FoxESS mobile app's own "load today"
figure by a meaningful margin. Treated as "good enough for a usage
*shape*, not a billing-accuracy figure" — the DP only needs relative
half-hour-to-half-hour proportions, not an absolute kWh figure it depends
on being exact. `FoxessClient::getUsageReport()` is a deliberately separate
method from `getGenerationReport()` (own call, own `variables: ['loads']`)
rather than one shared multi-variable call, to keep zero risk to the
already-working, never-re-fetched generation history while adding this.
`historic_generation` gained a third nullable `usage_kwh` column via a
guarded `ALTER TABLE ... ADD COLUMN` (checked via `pragma_table_info`,
never a drop-and-recreate — this table is real, accumulated history, unlike
the disposable ones elsewhere in this app) and `Store::upsertHistoricUsage()`
mirrors `upsertHistoricGeneration()` exactly, written independently so it
never clobbers the generation/forecast columns on the same row.
`HistoryFetcher::fetchGenerationHistory()`'s existing forward-catch-up/
backward-backfill control flow is completely untouched and stays the sole
source of truth for the backfill horizon; a best-effort, independently
try/caught usage fetch+store call rides along inside the same per-day loop
iterations — a usage-fetch failure or "no data for this day" can never
affect the generation loop's own control flow or backfill-horizon logic.

*Half-hourly usage forecast, sampled from real history
(`src/HalfHourlyUsageEstimator.php`, new — deliberately separate from the
existing `UsageEstimator`, which stays untouched and still backs the other
two schedulers' flat daily estimate).* Per the issue's literal sampling
rule: for the target date's day-type (weekday/Saturday/Sunday, via ISO `N` —
matched exactly, not "weekday vs weekend"), tier 1 is the same ISO week
number in each previous year that has stored `usage_kwh` data
(nearest-year-first), tier 2 is the most recent 28 days before the target
date with data (most-recent-first); take the first 30 candidates found,
tier 1 before tier 2, average matching half-hour-of-day across them (each
historical hour splits into two equal half-hours, since hourly is
`report/query`'s native resolution — the same accepted limitation
`historic_generation` already documents for generation). Fewer than 3
candidates with data anywhere: flat fallback using the *existing*
`UsageEstimator::estimateDailyKwh()` (so the summer/winter kWh/month
settings stay the single source of truth for "how much does this house
use") spread flat across 8am–20:00 only, zero elsewhere — per the
confirmed answer above, not an interpolated shape. ISO week-of-year
arithmetic is done properly (Jan 4th is always in ISO week 1, computed
arithmetically) rather than via a format-string that assumes the reference
date itself is in week 1 — a real bug in this feature's own test fixture
during development, caught because the test's expected numbers didn't
match until the fixture's own week arithmetic was fixed.

*`ModellingScheduleBuilder`: the DP core.* Grid = SoC bins from the
existing reserve-SoC floor to full capacity, step `modelling_soc_bin_kwh`;
starting bin = nearest bin to the current live SoC reading (or the reserve
floor if unavailable, same fallback `IntelligentScheduleBuilder` already
uses). Three transitions per slot, reusing/extending the same physics
`IntelligentScheduleBuilder`'s natural-trajectory simulation already
validates rather than inventing new formulas: force-charge draws at the
configured rated charge power (capped by remaining headroom to full
capacity), force-discharge supplies at rated discharge power (capped by
headroom down to the reserve floor), idle follows solar-minus-usage,
floored at `min_soc_on_grid` — not `reserve_soc` (the same floor
distinction CLAUDE.md already documents elsewhere: `min_soc_on_grid` is the
general system floor, `reserve_soc` is specifically how far *forced*
discharge may drain the battery). Round-trip efficiency is applied once, on
the charge side only — a standard, defensible simplification, not literal
battery physics, and worth revisiting if real-world figures ever justify a
split. Cost per transition is net grid flow × that slot's import price (net
import) or export price (net export, i.e. a negative cost/credit). A
forward Bellman recursion fills a `cost`/`backpointer` grid across the
whole window in one pass; the terminal state prefers the minimum-cost
end-of-horizon bin that meets `modelling_min_end_soc_pct` — ties on that
cost broken in favour of the *higher* SoC, a safe default with no
downside, rather than an arbitrary pick — falling back to the global
minimum-cost end state (flagged in the summary, not thrown) on the rare
horizon too tight to meet it exactly; reconstruction then walks the
backpointers from that terminal bin back to the known starting SoC. (An
earlier version credited SoC held above the floor at a reference price
here, specifically to stop wasteful selling — see "A real bug found only
after deployment" below for the full story of why that approach was
eventually abandoned in favour of gating the sale itself, in
`transitionForceDischarge()`, rather than discouraging it after the fact
at the terminal boundary.)
Contiguous same-action slots are merged into absolute intervals the same
way the other schedulers merge contiguous slots, just over real instants
instead of a single day's array indices, since a merged run can itself span
midnight here. Explanations are cost/price-driven per interval, same
narration style as the other two schedulers' `explainPeriods()`; the DP's
`totalCostPence` (an addition beyond what the issue asked for, added purely
to make a genuine "beats an always-idle baseline" test possible — see
`tests/self_check.php`) isn't surfaced in the UI, just used internally for
this correctness check.

*Still "exact/deterministic, cheap to compute," per the issue's own
framing — genuinely a solver, unlike the other two.* This is the one
scheduler in this codebase that isn't a "greedy heuristic, not a solver" —
see the issue's own explicit ask and the "Pluggable scheduler architecture"
note above. It re-solves the whole rolling window fresh on every run from
whatever the current live SoC reading is (or the reserve floor, if
unavailable) — satisfying the issue's "re-evaluate on a rolling basis"
requirement for free, since nothing needs to persist state between runs to
make that work, same reasoning issue #4 already established for why
recomputing fresh each run is safe for the other two schedulers.

*A real bug found only after deployment, against real Octopus rates: the
DP would force-discharge a fully-charged battery down to the configured
minimum end-of-horizon SoC purely to sell the excess at a flat, low fixed
export rate — even with nothing to offset and that stored energy plainly
worth more than the export price once you account for what it costs to buy
back.* This took three iterations to actually fix correctly — the first
two attempts both introduced a new, real side effect of their own, caught
by continuing to verify against real Octopus data (and, critically, by the
user rejecting the first attempt's reasoning on economic grounds rather
than by symptom) rather than declaring victory once the reported case
looked clean:

1. *First attempt: value held-back SoC at the terminal boundary.*
   `pickTerminalBin()` originally scored candidate end-of-horizon bins on
   raw cost alone, treating any SoC held above `modelling_min_end_soc_pct`
   as worth precisely nothing — so *any* non-negative export price looked
   like free money, and the optimiser happily "spent down" every kWh it
   wasn't strictly required to keep. The fix credited SoC held above the
   floor at the horizon's own cheapest import rate when choosing the
   terminal bin — the classic finite-horizon battery-arbitrage
   countermeasure. It worked for the reported case, but was wrong in a way
   that only showed up once solar was reintroduced (next point).
2. *Second attempt: gate the sale itself, not just the terminal state.*
   The terminal credit only ever looks at the *final* state, so a path
   that sells cheap mid-horizon and gets "topped back up for free" by
   **forecast** solar a few slots later could still look better in the raw
   ledger than never selling at all — a bet on solar forecast accuracy,
   not a price the DP actually knows, and rejected by the user on exactly
   that basis: force-discharge should only ever be justified by a price
   the optimiser can already see. Fixed in `transitionForceDischarge()`
   directly: discharge may only exceed what a slot's own `usage - solar`
   needs when `exportPrice` is at least as good as the horizon's cheapest
   import rate; otherwise it's capped at exactly that need, same as
   self-consumption offsetting, never manufacturing a deliberate export.
   This also exposed a smaller sibling gap: when the cap forces zero
   discharge and there's a solar surplus, the transition must degenerate
   to *exactly* self-use's own absorption formula (crediting the surplus
   into the battery), not just "discharge nothing" — an earlier draft of
   this same fix capped the discharge amount correctly but kept the plain
   `usage - solar - energyOut` formula regardless, silently failing to
   credit the surplus and so still coming out fractionally cheaper than
   self-use, quietly winning what should have been an exact tie.
3. *Third discovery: the terminal credit itself was now not just
   redundant but actively harmful.* With sale prices properly gated at the
   point of sale, the terminal credit from step 1 was still active — and
   it turned out to cut both ways: crediting held-back SoC doesn't just
   discourage cheap sales, it also *rewards paying for real grid import
   instead of drawing on the (already-owned, already-free) battery*,
   whenever some slot's import price undercut the credited reference —
   purely to inflate an ending-SoC valuation that was never going to be
   genuinely spent or sold on anything. Caught live: a fully-charged
   battery was choosing a no-op `ForceCharge` (headroom already zero, so
   it draws straight from the grid instead of the battery) during a cheap
   afternoon window, paying real money it didn't need to, specifically to
   avoid drawing down SoC that self-use would have drawn down for free.
   User clarification prompted the fix: self-use already sells any solar
   surplus the battery can't absorb, and already covers usage from stored
   energy for free — nothing left needs to *fight* for SoC by paying to
   avoid using it. `pickTerminalBin()`'s credit was removed entirely,
   reverting to plain minimum-cost-among-feasible-bins (with a same-cost
   tiebreak toward the *higher* SoC, a safe default with no downside);
   step 2's gate in `transitionForceDischarge()` was already sufficient on
   its own to stop the original bug, since self-use's own natural
   absorption/drawdown already handles both halves of "sell genuine
   surplus" and "cover usage from stored energy" correctly without any
   terminal accounting fiction. Re-running the full regression suite after
   removing the credit outright — including the tests written for both
   earlier attempts — confirmed every one still passed unchanged,
   confirming the credit truly had become redundant, not just harmful.

One more refinement folded in along the way: the gate's reference price is
the horizon's cheapest import rate *inflated by round-trip efficiency*
(`min(import) / efficiency`), not the bare rate — recharging 1kWh of
*usable* capacity draws more than 1kWh from the grid, so a sale priced to
just match the bare cheapest import rate is actually a guaranteed loss on
the round trip once that's accounted for, not a break-even trade.

All of this is covered by regression tests built at the production-default
`soc_bin_kwh` (0.1) and against the real Octopus rates/solar totals that
first surfaced each issue — smaller or flatter synthetic fixtures were
tried first for two of these and found not to reproduce the bug at all
(would have passed on the broken code too, proving nothing). Worth
remembering for any future DP bug here: a regression test is only as good
as its price/solar shape and horizon length — a fixture has to actually
create the multi-step trade-off the bug depends on, or it's not testing
anything.

**Overrides feed into the DP as hard constraints, not a post-hoc overlay
(user-requested).** Fill-your-boots/Power-down overrides (`overrides`
table, `override.php`) previously only ever reached a computed schedule
via `ScheduleBuilder::applyOverrides()` — called from `Runner.php` *after*
whichever scheduler had already produced its plan, painting the override's
own forced periods over whatever was there. That's still exactly how
`ScheduleBuilder`/`IntelligentScheduleBuilder` get theirs; it's a fine fit
for a greedy heuristic that doesn't globally optimise a SoC trajectory in
the first place. It's a poor fit for the modelling scheduler specifically:
an override applied only after the DP has already solved the whole horizon
means the DP's own SoC-trajectory optimisation for every *other* slot was
computed in ignorance of a charge/discharge the schedule will actually
contain — the combined result can be internally inconsistent (e.g. the DP
might independently have planned a charge for a slot the override is about
to force into a discharge instead).

Fixed by giving `ModellingScheduleBuilder::build()` an optional
`$forcedActionsByIndex` parameter (same length as `$importSlots`; null or
one of the three action names per slot). A forced slot doesn't get special
transition math — the cost/SoC-update formulas are identical either way —
it just restricts the action loop to the single compulsory choice for that
slot, so the Bellman recursion still finds the genuinely cheapest way to
handle every *other* slot given the SoC trajectory that choice produces.
That's the literal meaning of "reflect the overrides and optimise around
them": the override is data the solver sees before it solves, not a patch
applied to its answer.

`Schedulers.php`'s new `buildForcedActionsFromOverrides()` is what
populates this from real overrides — called from
`buildModellingScheduleForRun()`, so every existing call site
(`Runner.php`'s `runScheduler()`/`reapplyOverrides()`, `schedulers.php`'s
preview) picks it up with no changes of its own. It shares its mode
mapping (fill_your_boots: prep=ForceDischarge, event=ForceCharge;
power_down: prep=ForceCharge, event=SelfUse) with `applyOverrides()` via a
new `ScheduleBuilder::overrideModesFor()` static helper — extracted
specifically so the two can't silently drift apart on what an override
actually means. Working across however many calendar dates the rolling
window touches (unlike `applyOverrides()`, which only ever deals with one
date at a time and works in bare minute-of-day integers) means resolving
each override's H:i strings against real dates into absolute instants,
then marking a slot forced whenever an override window overlaps it *at
all* — not only when fully contained. The DP's own half-hour granularity
is coarser than an override's free-form `<input type="time">` boundaries,
so a slot straddling an override's edge is conservatively treated as
compulsory for its whole half hour.

**The post-hoc `applyOverrides()` pass still runs afterward for every
scheduler, including modelling — deliberately kept, not made redundant by
the above.** It does two things the DP-level constraint can't: replaces
the DP's own explanation for a forced slot (which would otherwise narrate
it as a normal cost-driven choice — "the lowest-cost point... to charge"
— genuinely misleading for a slot that wasn't actually chosen for cost
reasons at all) with the honest override-specific wording, and trims the
final output to the override's exact minute boundaries rather than the
DP's coarser half-hour ones. The two passes are complementary: the DP-level
constraint gets the *optimisation* right, the post-hoc pass gets the
*precision and narration* right. Confirmed live: a saved override run
through the full pipeline (DP forces the slot, then the post-hoc pass
relabels it) produces a single clean group with the correct override
explanation, not a duplicate or conflicting one.

**A real bug found via live verification, not by inspection: SQLite TEXT
comparison of ISO 8601 datetime strings isn't chronologically correct
unless every value shares the same UTC offset.** `price_slots.slot_from` is
always stored in UTC (`OctopusClient` only ever returns UTC
`DateTimeImmutable`s), but `getPriceSlotsFrom($from)` was originally
comparing against `$from->format(DATE_ATOM)` in whatever timezone the
caller passed — during BST (`+01:00`), local midnight's UTC-offset string
representation ("...T23:00:00+00:00", the previous calendar date) sorted as
*earlier* than the local-midnight cutoff string ("...T00:00:00+01:00")
even though they're the same instant, silently dropping the first hour of
"today" from every query. Confirmed live against real data before
diagnosing, then fixed by normalising `$from` to UTC before comparison —
see `getPriceSlotsFrom()`'s own doc comment, and the regression test in
`tests/self_check.php` (a fixed `+01:00` `DateTimeZone`, not a real DST
transition, so it doesn't depend on which calendar date is actually in
DST). Worth remembering for *any* future SQLite query comparing a
`DATE_ATOM`-formatted parameter against stored TEXT timestamps in this
codebase — normalise both sides to the same offset first, always.

**Dashboard battery display: `SoC`/`SoC_1` field names are a best-effort
guess, not confirmed against FoxESS's own docs** — same caveat as `fdSoc`/
`fdPwr` above. Confirmed via community reference implementations
(`TonyM1958/FoxESS-Cloud`'s `battery_vars`), not official documentation:
single-battery inverters report the variable `SoC`, multi-battery ones report
`SoC_1`/`SoC_2`/etc per battery. `getBatterySoc()` requests both `SoC` and
`SoC_1` and returns whichever the device actually sends — reasonable enough
for the common case (one battery per inverter, which is what both of the
configured devices are), but wouldn't surface a second/third battery on a
multi-battery inverter individually. `index.php` calls this once per
configured device serial on every dashboard load — a live network call, not
cached — and shows "unavailable" rather than breaking the page if it fails
or times out; the rest of the dashboard is local SQLite reads and shouldn't
depend on FoxESS being reachable. No caching layer for v1, consistent with
this app's general "simplest thing that works" bias — worth adding if
dashboard load time or FoxESS API quota ever becomes a real problem, but
1,440 calls/day/device makes that unlikely for a personal dashboard.

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
alongside the rest of that date's rows on every recompute for that date —
same per-date-disposable pattern as the rest of `schedule_groups`) and, one
per known date, `schedule_summaries` (see "Date-time-aware scheduling" for
why that replaced the old single global `settings.schedule_summary` value).
`getScheduleForDate()['groups']` deliberately excludes explanation text —
it's what the no-op-push diff compares, and wording drift shouldn't trigger
a re-push when the actual schedule hasn't changed. `index.php` renders both
under "Energy plan", one `<h4>` sub-section per known date.

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
not a bad schedule payload. `errno 41811` ("User permissions do not allow
this operation") is a *different*, account/permission-layer error — TonyM1958's
FoxESS-Cloud wiki documents it, but its actual trigger is thinly documented
in the community. One concrete cause traced live in this project: `device_sn`
being set to an API client ID from FoxESS Cloud's API Management page rather
than the actual inverter serial number — the account legitimately has no
write permission over a "device" that isn't a real device it owns. Worth
checking first if this recurs, before assuming it's account-level.

**`pushSchedule()` clears the existing schedule before pushing the real one.**
User-reported, confirmed live: stale slots from a previous push have been observed
persisting and blocking the desired new ones on the inverter, even though the push call
nominally sends a full replacement `groups` array. Community reports confirm pushing an
empty `groups: []` array is FoxESS's own documented way to clear everything, so
`FoxessClient::pushSchedule()` now does that as a separate call immediately before the
real push — two calls per device per push, both going through `post()` so both get
logged (see "API call log" below) and get the existing single-retry handling. The clear
call is deliberately *not* best-effort: if it fails, the whole push fails, same as if the
one real push call had failed — silently skipping it on failure would risk exactly the
stale-slot bug this exists to fix. `post()` changed from `private` to `protected`
specifically so `tests/self_check.php` can subclass it to verify the two-call sequence
(order, and that a failed clear aborts before the real push) without touching the network
— the existing `pushToDevices()` tests, which subclass the public `pushSchedule()`
itself, didn't need this and are unaffected.

**API call log (GitHub issue #3).** User-requested: every FoxESS API call logged —
datetime, request body, endpoint, response code, response body — visible in a new
"API log" area, most recent first, expandable, with the collapsed row colour-coded
success/warning/error.

Logged from inside `FoxessClient::post()` itself (`Store::saveApiLogEntry()`), not at
each call site — `post()` is the one choke point every request (scheduler push/get,
`real/query` for SoC, `report/query` for generation history) already goes through, so
logging there can't be forgotten by a future call site the way logging at each of the
half-dozen places `FoxessClient` gets instantiated could be. This is the one place
`FoxessClient` now depends on `Store.php` — previously a self-contained API client with
zero persistence concerns of its own. A retried call (single retry on transport failure,
per spec §12) logs as two rows, one per actual network round-trip, since each one really
did happen. A transport-level failure (no HTTP response at all) has no status code to
record — `status_code` is stored `NULL` for that row, with the cURL error message
standing in for a response body.

**`api_log` is a genuinely accumulating table, like `historic_generation` — rows are
never deleted, only their bodies get redacted.** Per the issue's own retention rule:
"anything older than seven days should have its request and response bodies cleared —
only datetime, endpoint and status code should remain." `saveApiLogEntry()` runs that
redaction (`UPDATE ... SET request_body = NULL, response_body = NULL WHERE called_at <
cutoff`) immediately before every insert, rather than on a separate cron/cleanup step —
same reasoning as everywhere else redaction-on-write is used in this app: the rule runs
every single time this table is touched at all, so there's no separate scheduled job to
forget to set up or to silently stop running.

**Row-level colour needs more than the raw HTTP status, because FoxESS wraps logical
errors inside HTTP 200.** A naive "colour by status code" would show green for most real
failures, since FoxESS's own API returns `{errno, msg, result}` inside a 200 response for
business-level errors (bad auth, wrong permissions, etc.) rather than a non-2xx status —
only a genuine transport failure or an unexpected non-200 HTTP response gets a non-200
status at all. `api-log.php`'s `apiLogLevel()` therefore parses the stored response body
for `errno` when the status is 200, downgrading the specific, already-established "Device
offline" case (`errno` 41935, same string check `Runner.php`'s `isOfflineFailure()` uses)
to a warning rather than an error, since that's routine for a battery-less inverter
overnight, not a real problem. This is a display-time heuristic in `api-log.php`, not a
new column `saveApiLogEntry()` writes — same "derive it at render time rather than
storing a redundant field" pattern index.php's `$ranClass` warning-detection already
uses. One accepted consequence: once a body is redacted past the 7-day window, `errno`
is no longer recoverable and the colour falls back to the coarser status-code-only
judgement — a known, accepted trade-off of the retention rule, not a bug.

**Nav gets `flex-wrap` rather than a redesign.** The issue flagged that adding "API log"
might make the nav unwieldy — with `Dashboard`/`Schedulers`/`Override`/`History`/`API log`/
`Settings`/`Log out` now seven items, a narrow viewport could genuinely overflow. Fixed
with one property (`nav { flex-wrap: wrap; }`, `style.css`) rather than a hamburger menu
or JS-driven collapse — confirmed via the mobile viewport preset that this alone stops the
nav overflowing horizontally. If more nav items get added later and this still isn't
enough, that's the point to revisit with an actual collapsing menu, not before.

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
codes, `strategy` tunables, `foxess.base_url`, notification email).

**Battery config moved to settings.** User-reported bug (GitHub issue #1,
"Maximum discharge levels not set"): force-discharge slots weren't shifting
as much power as they should. Root cause turned out not to be a code bug at
all — `max_discharge_kw` was set in `config.php` (a file only editable via
SSH/FTP on the live host) to a conservative placeholder mirrored from
`max_charge_kw`, rather than the inverter's actual rated maximum discharge
power, and the user had simply forgotten it was there to check. Confirmed
against FoxESS community documentation (forum posts + TonyM1958's
FoxESS-Cloud) while investigating: `fdPwr` is the scheduler's hard ceiling
on force-discharge power, defaults to 0 if omitted entirely, and the
community's own guidance is to set it to the inverter's true rated max —
FoxESS's own house-load-aware logic does the real-time limiting, so a
conservative value here only ever leaves power on the table, never protects
anything.

The fix (issue retitled/redirected to be about config discoverability, not
code — see the issue for the full before/after) was to move the whole
`battery` config section (`capacity_kwh`, `max_charge_kw`,
`max_discharge_kw`, `min_soc_on_grid`, `reserve_soc`) out of `config.php`
into the settings table, editable from settings.php's new "Battery"
section, same reasoning and pattern as the FoxESS-credentials move above:
a value worth tuning without a deploy shouldn't live somewhere the user can
forget about it. `Store::getBatteryConfig(array $legacyConfig = [])` is the
single read path every caller (`Runner.php`, `index.php`) now goes through;
`$legacyConfig` is `config.php`'s old `battery` array, read once per key as
a migration fallback for whatever hasn't been saved via settings.php yet —
same "read the old location once, so an upgrade doesn't reset a working
install to defaults" pattern as `foxess_device_sns`' fallback to the old
singular `foxess_device_sn` key. `config.example.php` no longer has a
`battery` section at all; a live `config.php` that still has one keeps
working (via the fallback) until the user visits settings.php and saves
over it, at which point the settings-table value wins for good.

While in there, `settings.php`'s previously one-column stack of fieldsets
was also reorganised into the `.settings-grid` responsive grid described
above — the Battery section was one more fieldset to add to an already
fairly long single-column page, and "tidy it up while adding a section"
was explicitly the ask, not a separate cleanup.

**Multi-inverter support: one setting holding a newline-separated list, not a
devices table.** User has two inverters and wants the same schedule pushed to
both. `foxess_device_sns` replaced the old singular `foxess_device_sn` —
still just one row in the existing key/value `settings` table (newline-
separated serials), not a new table with add/remove rows. A real devices
table would make sense at "manage a fleet" scale; at "a couple of inverters
in one household" scale it's pure ceremony for what's fundamentally a short
list of strings. Old single-value installs migrate for free: `settings.php`'s
display falls back to the legacy `foxess_device_sn` key the first time it
renders with nothing under the new key, so an existing value shows up as a
starting point instead of a blank box (see the fallback in `settings.php`).

`FoxessClient` itself is untouched — still scoped to exactly one device, same
signing/request logic as before. The looping lives in `Runner.php`'s
`pushToDevices()`, which constructs one client per configured serial and
**always attempts every device, even after an earlier one fails** — a bad
serial or a permission error on inverter #1 shouldn't stop inverter #2 from
getting a real, working push. Failures are collected per-device (labelled by
serial number) rather than the loop bailing on the first exception; if
*any* device failed, the whole run is still reported as failed (logged,
alerted, non-zero exit) — a schedule "mostly" applied isn't treated as
success, but every device that *can* be updated still gets updated. This
loop is unit-tested (`tests/self_check.php`) using anonymous subclasses that
override `FoxessClient::pushSchedule()` directly — `pushSchedule` is public
and not `final`, so this needs no changes to `FoxessClient` and never touches
the network.

**Generation history: `report/query`, not `history/query` — hourly kWh, not raw power.**
User-requested addition: keep an actual-generation-vs-forecast record over time, viewable
by day/week/month/year. FoxESS's OpenAPI has two endpoints that could plausibly supply
this — researched live (community docs, since FoxESS's own docs are thin here, same
caveat as everywhere else in this file): `/op/v0/device/history/query` returns raw power
samples at 5-minute resolution and needs the caller to integrate kW→kWh by hand;
`/op/v0/device/report/query` (`dimension=day`) returns the total FoxESS's own app already
computes — up to 24 **hourly** kWh values, one call per device per day. Used the report
endpoint: the brief cared about summed energy, not sample-level granularity ("half-hour
would be sufficient, but work with whatever resolution works best from the API"), and
hourly-already-summed is both simpler and more reliable than reimplementing kW→kWh
integration ourselves. This is also why the history page's day view is hourly (24 rows),
not half-hourly (48) — that's the API's native resolution, not a choice this app made.
`FoxessClient::getGenerationReport()` wraps this endpoint; note its request body uses
`sn` (singular), unlike the scheduler endpoints' `deviceSN` or `real/query`'s `sns` array
— confirmed against `TonyM1958/FoxESS-Cloud` (the same community reference this file
already leans on for the scheduler endpoint and SoC field names).

The same `report/query` endpoint also has a `loads` variable for hourly household
consumption — `FoxessClient::getUsageReport()` (GitHub issue #5, added alongside the
modelling scheduler) wraps it as its own separate call, stored in `historic_generation`'s
`usage_kwh` column. See "Modelling scheduler" below for the full story, including a known
community-reported undercount caveat for `loads` specifically.

**Storage is a genuinely accumulating table** — `price_slots` and `api_log` (see
"Date-time-aware scheduling" and "API call log" respectively) are the only other two, as
of this writing; everything else stays disposable (`schedule_groups`/`solar_forecast`,
still per-date/per-fetch replace — see "Data storage" above). `historic_generation`
(`src/Store.php`) is deliberately *not* replace-on-every-fetch, same reasoning as those
two. This table exists specifically to answer "what happened over time",
so it's append/upsert-only, one row per local clock hour, kept indefinitely. Generation
and forecast share the row (`generation_kwh`, `forecast_kwh`, both nullable) but are
written independently by different callers on different schedules — `upsertHistoricGeneration()`/
`upsertHistoricForecast()` each touch only their own column, so writing one never clobbers
the other. A separate table from `solar_forecast` on purpose even though they overlap in
spirit: `solar_forecast` stays latest-fetch-only because it answers "what's the plan right
now" for the dashboard; duplicating the data into a second, real history table was simpler
than making one table serve both jobs.

**Backfill walks backward, oldest-first *of each batch*, and stops permanently once it
hits the data horizon.** `HistoryFetcher::fetchGenerationHistory()` is called from
`Runner.php` on every real (non-dry-run) scheduled run, and from `history-fetch.php`'s
"Fetch history now" button (dashboard-adjacent, login-gated, POST-only — same pattern as
`run-now.php`). Two bounded passes per call, so one invocation stays fast:
- *Forward catch-up*: from the latest stored day through today (today's not-yet-elapsed
  hours are never written — see `storeDay()` — so a run partway through the day only
  trusts hours strictly before the current local hour, same reasoning as the "partial-day
  data" handling elsewhere in this file).
- *Backward backfill*: one day further back than anything stored, up to
  `HISTORY_BACKWARD_BACKFILL_MAX_DAYS_PER_CALL` (20) per call. The moment FoxESS reports no
  data at all for a day, that's recorded as `settings.history_backfill_exhausted_before`
  and never probed again — this is the literal reading of "historic data won't change so
  once we have data up to point x we never need to go back earlier than that". A single
  cron day (or button click) only advances the boundary by one call's worth of days;
  mash the button (or just wait) to walk further back faster.

**A day, once stored, is never re-fetched — so a transient per-device error must never get
silently written as an undercount.** With two configured inverters, each day's fetch is
per-device; `combineDeviceGenerationResults()` (`src/HistoryFetcher.php`, unit-tested in
isolation) distinguishes three outcomes per device — real data, `null` ("FoxESS has
nothing for this device on this day" — the normal case for a second inverter added to the
account after an older one), and a thrown request error — and only the error case blocks
the whole day from being written at all (retried next call). A device reporting `null`
contributes 0 rather than blocking, which is what lets an older, single-inverter
install's backfill walk back past the date a second inverter joined the account. Only when
*every* device comes back `null` does the day itself count as "no data" — the actual
backfill-horizon signal.

**Forecast history is captured prospectively, never backfilled.** Forecast.Solar (see
`SolarForecastClient`) only exposes *historic* forecasts on a paid tier, so there's nothing
to fetch retroactively. Instead, every time `Runner.php` fetches (or already has) a live
solar forecast, it also upserts each bucket into `historic_generation.forecast_kwh` — the
record of "what did we predict" builds up one real forecast fetch at a time, going
forward only. This is why `forecast_kwh` reads null for any date before this feature
shipped, or any date the app happened not to be running with solar forecasting enabled —
that's expected, not a bug to chase.

**A plausibility ceiling guards against a known FoxESS firmware quirk.** Community
references (`TonyM1958/FoxESS-Cloud`'s `fix_values` workaround) document a 32-bit
energy-total overflow that occasionally corrupts `chargeEnergyToTal`/`dischargeEnergyToTal`
into absurd values. Not confirmed to affect `generation` specifically, but given this table
is never re-fetched once written, `HISTORY_MAX_PLAUSIBLE_HOURLY_KWH` (50 kWh — far beyond
any residential single-hour output) silently zeroes anything above it rather than risk a
permanent corrupted spike. Cheap insurance, not a precise fix for a fully-understood bug.

**History page uses DataTables — the one deliberate exception to this app's
zero-JS-dependency norm.** `history.php` loads jQuery + DataTables from a CDN, scoped to
that page only (every other page stays exactly as dependency-free as before). Explicitly
requested by name, and it *is* the standard tool for a sortable/searchable/paginated table
— hand-rolling that would be reinventing a well-established wheel for no benefit. CDN
rather than vendored: this is a single-user hobby app on shared hosting with no build
step, so a `<script src="https://...">` is the lowest-ceremony way to add a library that
needs no configuration of its own here. `assets/style.css` has a small dark-mode override
block for DataTables' own chrome (search box, pagination buttons) since its stock skin is
light-only and would otherwise clash with this app's dark mode.

**Chart/day-view resolution and date navigation are timezone-safe by construction, same as
everywhere else in this app.** `history.php`'s date navigation uses `<input type="date">`
(a calendar date only, no time-of-day component — nothing for a browser timezone to even
shift) and resolves `?date=`/period boundaries entirely against `strategy.timezone` from
`config.php`, never the browser's or server's local zone. See the "Either side of
midnight"/override-related notes elsewhere in this file for the same principle applied to
schedule times.

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
template — both hold only non-secret, not-meant-to-be-UI-editable tunables
now (FoxESS `api_key`/`device_sn` and the whole `battery` section moved to
`data/scheduler.sqlite`, managed via `settings.php` — see "Battery config
moved to settings" above). If you add a new config key that's fine to leave
in a file only editable via SSH/FTP on the live host, update both files and
the shape described in the spec's §4. If you add a new *secret*, or a value
someone might reasonably want to tune without a deploy, it belongs in the
`settings` table via `Store`, not in `config.php`.

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

## Deploying

The live host pulls from git via a Plesk "Git" webhook — hitting the webhook URL tells
Plesk to pull and deploy the latest commit on the tracked branch. **After every `git push`
to this repo (from an interactive session or an autonomous one), check for a
`.deploy-webhook-url` file at the repo root; if it exists, trigger the deploy immediately
after the push, no separate confirmation needed** — the user has already authorised this
standing behaviour. If the file is missing, don't guess at a URL or skip silently — tell
the user a push happened but no deploy was triggered, and ask them to create
`.deploy-webhook-url` (repo root, gitignored, the webhook URL as its only line) if they
want pushes to auto-deploy.

The file holds a bare URL, nothing else:

```
https://shared-uk.man-1.vm.plesk-server.com:8443/modules/git/public/web-hook.php?uuid=...
```

To trigger it:

```bash
curl -sk -X POST "$(cat .deploy-webhook-url)"
```

Two details that matter, both confirmed live against this specific webhook — don't drop
either if you're reimplementing this call:
- **POST, not GET** — a GET returns without triggering a pull.
- **TLS verification must be disabled** (`curl -k`, or the cURL-extension equivalent
  `CURLOPT_SSL_VERIFYPEER`/`CURLOPT_SSL_VERIFYHOST` set to disable) — the deploy host's
  certificate doesn't validate (self-signed or otherwise unrecognised by the calling
  environment's CA bundle), confirmed as an accepted, known condition of this specific
  endpoint, not a transient error worth retrying with verification on. Skipping
  verification means the request can't distinguish the real deploy host from an
  on-path attacker — acceptable here only because the URL itself is a long random
  secret (the query-string `uuid`) treated the same way `cron.php`'s token is elsewhere
  in this file: don't paste it anywhere public, regenerate via Plesk if it ever leaks.
  This tradeoff is specific to this one webhook call — don't disable TLS verification
  anywhere else in this codebase without the same explicit reasoning.

The webhook URL contains a bearer-style secret (anyone with it can trigger a deploy), so
treat `.deploy-webhook-url` like `config.php` — never commit it, never paste its contents
into a chat, PR, issue, or log.

## Asset versioning

`src/AssetVersion.php` defines a single constant, `ASSET_VERSION`, appended as a `?v=...`
query string to every `<link>`/`<script>` tag that points at a **locally-served** static
asset — currently just `assets/style.css` via `src/Layout.php`. This is cache-busting: a
browser (or an intermediate cache) that already has an older copy of `style.css` cached
has no other reason to ever re-fetch it, since the URL never otherwise changes between
deploys. This was found live: a stale cached `style.css` from before `--color-generation`
existed left the history chart's generation bars rendering as plain black (a browser's
fallback for an unresolved CSS `var()`), while `--color-solar`, present in that older
cached copy too, kept working fine.

**`ASSET_VERSION` is a hand-maintained constant, not derived from `filemtime()` or
similar — bump it (a plain increment is fine) yourself, every time `assets/style.css`
actually changes, or whenever a locally-served JS file is added or changed.** This is
deliberate: the live host deploys via a Plesk git-pull webhook (see "Deploying" above),
and nothing about that pipeline guarantees a file's mtime reflects when its *content* last
changed rather than just when the last deploy happened to touch the filesystem — a
hand-bumped version is correct by construction instead of depending on that. If you add a
new locally-served CSS or JS file (as opposed to an inline `<style>`/`<script>`, which
needs no cache-busting — it's part of the HTML response itself), append `?v=<?=
ASSET_VERSION ?>` to it too, and add it to this section's list.

Third-party CDN assets (e.g. `history.php`'s DataTables/jQuery `<link>`/`<script>` tags)
do **not** use `ASSET_VERSION` — their version is already baked into the CDN URL path
(`.../1.13.11/...`), and appending our own query string would only defeat that CDN's own
shared cache for no benefit.

## Extension points

- **Cost basis modes**: `CostBasisProvider` — `octopus_product` mode (for
  when a time-banded tariff like Flux goes live) is stubbed with a `TODO`,
  deliberately not implemented against guessed API shapes. See spec §13 and
  [roadmap.MD](roadmap.MD).
- **Scheduling algorithm**: see "Pluggable schedulers" below — there are
  three today. `ScheduleBuilder` and `IntelligentScheduleBuilder` are both
  greedy, explainable heuristics by design, not a global optimiser (spec §7
  wants price-threshold logic, and explanations need to be narratable);
  `ModellingScheduleBuilder` (GitHub issue #5, see "Modelling scheduler"
  below) deliberately breaks that pattern and is a genuine DP/Bellman
  solver. A new scheduler with a `build()` signature compatible with the
  existing per-calendar-day dispatch just needs a new class, one entry in
  `SCHEDULER_DEFINITIONS`, and one branch in `buildScheduleWithScheduler()`
  (`src/Schedulers.php`) — `run.php`, `Runner.php`'s control flow, and
  `schedulers.php` need no change, since all of them just iterate the
  registry. A scheduler that needs a genuinely different shape of inputs or
  horizon (as the modelling one does — a rolling window rather than one
  calendar day at a time) instead gets its own parallel dispatch path, same
  as `buildModellingSchedule()`/`buildModellingScheduleForRun()` did — see
  "Modelling scheduler" for why that isn't shoehorned into the registry's
  existing per-day contract.
- **More settings-table config**: if more of `config.php` ends up needing a
  UI (see roadmap), it follows the same pattern `foxess_api_key` already
  does — `getSetting()`/`setSetting()`, no schema change needed for a plain
  scalar value.

## Out of scope (spec §12 — mostly superseded by since-built features, kept for what's still true)

Spec §12 originally listed solar generation forecasting, multi-inverter support, and
historical cost/price reporting as out of scope — all three are now implemented (solar
forecast, multi-inverter `foxess_device_sns`, and `price_slots`' permanent per-slot price
history respectively; `schedule_groups` itself stays per-date replace, not full history —
see "Data storage" above). What's still genuinely out of scope: retry/backoff beyond one
attempt, and any further analytics/reporting UI beyond what `history.php`/`price_slots`
already provide — don't build more of that without a scope conversation first.
