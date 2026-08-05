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

- [x] **Today/Tomorrow fix** — done. `schedule_groups` now keeps one row set
  per `for_date` (`Store::saveSchedule()`/`getScheduleForDate()`,
  `pruneOldSchedules()` drops dates before today). When a run computes
  tomorrow's plan and today's own plan is already stored,
  `Runner.php` splices them via `ScheduleBuilder::spliceForPush()` (reuses
  `applyOverrides()`'s interval-subtraction) before pushing: today's plan
  for whatever's left of today, tomorrow's plan for everything else, as one
  set of recurring groups. The no-op push diff now compares against the
  last *actually pushed* (spliced) groups, stored in `settings.last_pushed_groups_json`,
  not the raw per-date plan — the splice boundary moves every run even when
  nothing else changed. See CLAUDE.md and `tests/self_check.php` for
  details.

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
   forecast source (Octopus doesn't provide one); Forecast.Solar works well and has a free tier. A sample call would be `https://api.forecast.solar/estimate/51.7391942816367/-1.1726518586423484/20/31/8?time=Europe%2FLondon`, and the full API can be read here: https://api.forecast.solar/swagger.yaml. The sample call has the correct details for my setup, so seed the database with those values (the API reference will tell you what each one means).
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
