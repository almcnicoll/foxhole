# Roadmap

Future changes for the FoxESS Agile scheduler, beyond the v1 build in
[doc/foxess-agile-scheduler-spec.md](doc/foxess-agile-scheduler-spec.md) and
the v2 SQLite + web UI addition on top of it. Not committed work — a list to
prioritise from. Update this file as items are picked up, dropped, or
reprioritised.

## Before first live cron run (do these, not optional)

- [ ] Visit `settings.php` (default password `foxhole`) and set the real
      FoxESS `api_key` and device serial number(s) — one per line if you
      have more than one inverter — and change the password to something
      real while you're there. Use the inverter's actual serial number, not
      an API client ID from FoxESS Cloud's API Management page (confirmed
      live: entering a client ID there produces `errno 41811`, "User
      permissions do not allow this operation" — see CLAUDE.md).
- [ ] Fill in `config.php`: real Octopus tariff code for your region (see
      [CLAUDE.md](CLAUDE.md#decisions-made-while-building-things-the-spec-left-open)
      re: the region-letter fix) and `cost_basis.fixed_pence_per_kwh`.
- [ ] Run `php run.php --dry-run` and sanity-check the printed schedule
      *and its explanations* against a day of real rates — the scheduling
      algorithm changed substantially (arbitrage, export-peak discharge,
      pre-peak charging preference) and is worth a skim before it's making
      real decisions with real money.
- [ ] Do one manual live push, then check `index.php` (or
      `FoxessClient::getSchedule()`/the FoxESS app) to confirm `fdSoc`/`fdPwr`
      actually behave as this build assumes — that mapping is inferred, not
      confirmed against official docs (see CLAUDE.md).
- [ ] Set the cron entry (spec §10) and confirm `logs/scheduler.log` is
      actually being written on the real host — file permissions on shared
      hosts sometimes differ from local testing.
- [x] Confirm the host actually serves `.htaccess` — checked live on
      almcnicoll.co.uk (Plesk, Apache+PHP-FPM): `data/scheduler.sqlite` and
      `logs/scheduler.log` both return 403. Re-check this if the hosting
      setup ever changes (e.g. a move to pure nginx, where `.htaccess` is
      silently ignored).
- [ ] Copy `config.php` onto the live host — it's gitignored on purpose, so
      a fresh git deploy/pull won't have it; confirmed 404 on
      `almcnicoll.co.uk/foxhole/config.php` (2026-08-04), meaning it's
      missing there entirely right now, not just inaccessible.
- [ ] Put the site behind HTTPS at the host level — the login/settings forms
      submit the system password and API key in cleartext otherwise. This
      app doesn't force an HTTPS redirect itself (see CLAUDE.md).
- [ ] Confirm your actual export/sale tariff rate at `settings.php` — it
      defaults to a fixed 12p/kWh, which may not match your real contract.
      If you're on Agile Outgoing instead of a flat export rate, switch
      export price source to "Octopus Agile (API)" there.

## Near-term

- **Today/Tomorrow fix** — needed before cron is scheduled to run more than
  once a day (the plan is every ~4h). FoxESS's scheduler endpoint has no
  date field, only recurring hour/minute windows (see CLAUDE.md) — the
  inverter just matches time-of-day against whatever was last pushed, with
  no idea which calendar day it's actually in. `schedule_groups` currently
  holds exactly one plan, replaced whole on every push
  (`Store::saveSchedule()`'s `DELETE FROM ...`, see CLAUDE.md's "Data
  storage" section) — fine for a once-daily cron run, but wrong under a
  more frequent one: when Octopus's rates for the next day land (~16:00-
  17:00) and a run computes and pushes that plan, the push today currently
  overwrites the recurring schedule for the *whole day*, including hours
  still left in *today* — silently replacing today's already-correct,
  already-running decisions with tomorrow's for the rest of today.

  Roughly:
  1. Keep at least two schedules in storage at once, keyed by an explicit
     `for_date` rather than the current single-row "latest" table.
     `schedule_groups`'s schema and `Store::saveSchedule()`/
     `getLatestSchedule()` all assume one live plan; needs to become "one
     plan per date, at least two retained" (today's + tomorrow's — prune
     once a date has fully passed).
  2. A push must be a *splice*, not a straight overwrite, whenever both a
     stored today-plan and freshly-computed tomorrow data exist: today's
     plan for the remaining hours of today, followed by tomorrow's plan
     from midnight — encoded as one set of recurring hour/minute groups,
     since that's all the device understands. Example: a push at 18:00 on
     2026-01-01 should push 2026-01-01's own plan for 18:00-23:30 plus
     2026-01-02's plan for 00:00-17:30 — not all the way to 23:30. The
     splice only needs to reach as far as the *next* scheduled run (~4h
     out), since that run will recompute and re-push the boundary anyway;
     pushing a full 24h of "tomorrow" would bake in decisions a later run
     this cycle might legitimately want to revise (e.g. an override added
     for later in the day).
  3. Because runs happen every ~4h, the inverter should always be within
     one run-interval of running the *correct* day's schedule — worth an
     explicit check (or a self_check assertion) that a run's splice point
     never falls in the past by the time the next run is actually due.

  Touches `Store.php` (schema + functions), `Runner.php` (splice logic
  before push), and probably reuses `ScheduleBuilder::applyOverrides()`'s
  interval-subtraction machinery — splicing today's tail onto tomorrow's
  head is the same kind of interval overlay overrides already do, just
  keyed by date instead of override kind. The no-op push diff in
  `runScheduler()` (`$schedule['groups'] == getLatestSchedule()['groups']`)
  will also need to compare against the *spliced* result, not a single
  date's plan, or it'll misfire.

- **Flux cost-basis mode.** `CostBasisProvider`'s `octopus_product` branch is
  a stub (throws on purpose). Implement once Flux is actually live on the
  account: fetch Flux's own rate structure, confirm whether it's genuinely
  banded (a handful of daily rates) vs. half-hourly, and map it onto 48
  slots. Don't build this speculatively before the tariff switch happens.
- **Verify FoxESS scheduler endpoint periodically.** FoxESS has changed this
  API before. If pushes start failing with unexpected errno values, check
  the live docs again before assuming it's a bug in this code.

## Candidate improvements

Ordered roughly by how much value they'd add relative to effort — not a
commitment, just a starting point for prioritisation:

1. **History/cost reporting.** `rate_slots` and `schedule_groups` currently
   replace on every run (deliberately — see CLAUDE.md). Making them
   append-only (stop the `DELETE FROM ...` in `Store::saveRateSlots()`/
   `saveSchedule()`, add a date filter to the dashboard queries) is a small
   change now that SQLite is already in place — cheaper than it would have
   been against the old flat JSON files. Worth doing once you actually want
   to look back and check the strategy is saving money.
2. **Solar-generation-aware scheduling.** Spec explicitly calls this out as
   the natural next step for `ScheduleBuilder` — e.g. don't force-charge from
   the grid during slots where solar forecast already covers load. Needs a
   forecast source (Octopus doesn't provide one); Solcast or a similar API
   would be the likely dependency.
3. **Load-aware scheduling.** Similar idea, using historical/forecast
   household load instead of (or alongside) solar forecast.
4. **Live battery SoC awareness.** `ScheduleBuilder` now reasons about both
   import and export price (arbitrage, export-peak discharge, pre-peak
   charging — see CLAUDE.md), but has no idea how full the battery actually
   is: charge/discharge slot counts are still plain config caps, not bounded
   by a real energy balance. FoxESS's `real/query` endpoint (mentioned in the
   spec, unused so far) could supply actual current SoC; the natural next
   step is fetching it at the start of a run and simulating a running kWh
   balance through the day, so discharge can't promise to sell more than the
   battery will actually hold. Worth doing once the price-only version has
   been live long enough to see whether this gap matters in practice.
5. **Login hardening** (rate limiting / lockout on `login.php`, CSRF token on
   `settings.php` and `run-now.php`). Skipped deliberately for a single-user
   tool behind a password wall — revisit if this is ever exposed somewhere
   less trusted. `run-now.php` is the higher-priority one of the two if this
   gets picked up: a forged request there triggers a real inverter push, not
   just a settings change.

## Explicitly not planned

- Retry/backoff beyond the single retry already built in — spec §12 rules
  this out deliberately; revisit only if transient failures become a
  recurring problem in the logs.
- A general-purpose plugin/strategy framework for scheduling algorithms.
  `ScheduleBuilder::build()` is one method with one job; if a second
  algorithm is ever actually needed, that's the point to extract an
  interface, not before.
- An ORM, query builder, or migration framework for the SQLite layer — three
  tables and plain PDO is enough; revisit only if the schema outgrows
  hand-written `CREATE TABLE IF NOT EXISTS` (unlikely for what this app does).
