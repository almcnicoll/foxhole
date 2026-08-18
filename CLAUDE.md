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
history.php            # generation-vs-forecast history, day/week/month/year (password-walled)
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
  ScheduleBuilder.php   # rates + cost basis -> FoxESS scheduler groups
  FoxessClient.php      # signs + sends requests to the FoxESS OpenAPI
  HistoryFetcher.php    # backfills/catches up historic_generation from FoxESS's report/query endpoint
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
  Holds `foxess_api_key`, `foxess_device_sns` (newline-separated — see
  "Multi-inverter support" below), `system_password_hash`,
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
8. Read `foxess_api_key`/`foxess_device_sns` from `Store` (via `getSetting()`)
   — throws `FoxessPushException` with a pointer to `settings.php` if the key
   is empty or the device list is empty.
9. `pushToDevices()` — one `FoxessClient` per configured device serial
   number, each signing and POSTing to `/op/v1/device/scheduler/enable`
   independently. See "Multi-inverter support" below for why this loop lives
   in `Runner.php` rather than inside `FoxessClient` itself.
10. On success, `saveSchedule()` persists the new schedule and logs a
    summary. On any failure, log at ERROR and best-effort email
    `notify.alert_email` — both happen inside `runScheduler()` itself, so
    they're identical regardless of which entry point called it.

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
- **`index.php`** — reads the latest `rate_slots` + `schedule_groups`,
  resolves each half-hour slot's mode by checking which schedule group (if
  any) its local start time falls in (`slotWorkMode()` in `index.php`), and
  renders one unified table, split into two side-by-side columns
  (`renderSlotTable()` called twice — 00:00–11:30 and 12:00–23:30, split by
  local hour, not array index, since a partial day's slots aren't always
  exactly 48) via the `.slot-columns` flex layout in `Layout.php`.
  Deliberately merges prices and schedule into a single view rather than
  further-separate tables — that merge *is* the "quick glance" the UI exists
  for. Each row also gets a `row-{mode}` class (subtle background tint —
  green/red/grey for charge/discharge/self-use) alongside the existing
  per-cell `.badge`, and the Import/Export cells get a `.currency` class
  (monospace, right-aligned). A "Today's energy plan" section — `<h3>`, not
  `<h2>`; day summary from `settings.schedule_summary` + each group's stored
  explanation — renders *above* the slot tables, and the "Run now" button
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

**No live battery SoC *in the scheduling algorithm* — the "spare energy"
question is still decided by battery capacity/power maths, not tracked
state.** `real/query` is now called (see `FoxessClient::getBatterySoc()` and
"Dashboard battery display" below), but only for the dashboard — `Runner.php`
never reads it, and `ScheduleBuilder` still doesn't track a running kWh
balance across the day. Both charge and discharge slot counts are still
capped by the plain `cheap_slots_to_charge`/`expensive_slots_to_export`
config values (see "cheap_slots_to_charge is a plain config cap" above) —
there's no check that discharge slots don't promise to export more energy
than was actually charged. In practice this mostly self-corrects (Agile's
daily shape means cheap import and expensive export rarely swap places), and
FoxESS's own firmware will just discharge whatever's actually available
rather than erroring — but if this ever needs tightening, feeding
`getBatterySoc()`'s result into `runScheduler()` and simulating a running
balance through the day is the natural next step. Flagged in roadmap.MD.

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
not a bad schedule payload. `errno 41811` ("User permissions do not allow
this operation") is a *different*, account/permission-layer error — TonyM1958's
FoxESS-Cloud wiki documents it, but its actual trigger is thinly documented
in the community. One concrete cause traced live in this project: `device_sn`
being set to an API client ID from FoxESS Cloud's API Management page rather
than the actual inverter serial number — the account legitimately has no
write permission over a "device" that isn't a real device it owns. Worth
checking first if this recurs, before assuming it's account-level.

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

**Storage is a genuinely accumulating table, unlike everything else in this app.**
`historic_generation` (`src/Store.php`) is deliberately *not* replace-on-every-fetch like
`rate_slots`/`schedule_groups`/`solar_forecast` — see "Data storage" above for why those
three are disposable. This table exists specifically to answer "what happened over time",
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
