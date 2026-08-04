# Roadmap

Future changes for the FoxESS Agile scheduler, beyond the v1 build in
[doc/foxess-agile-scheduler-spec.md](doc/foxess-agile-scheduler-spec.md) and
the v2 SQLite + web UI addition on top of it. Not committed work — a list to
prioritise from. Update this file as items are picked up, dropped, or
reprioritised.

## Before first live cron run (do these, not optional)

- [ ] Visit `settings.php` (default password `foxhole`) and set the real
      FoxESS `api_key`/`device_sn`, and change the password to something
      real while you're there.
- [ ] Fill in `config.php`: real Octopus tariff code for your region (see
      [CLAUDE.md](CLAUDE.md#decisions-made-while-building-things-the-spec-left-open)
      re: the region-letter fix) and `cost_basis.fixed_pence_per_kwh`.
- [ ] Run `php run.php --dry-run` and sanity-check the printed schedule
      against a day of real rates.
- [ ] Do one manual live push, then check `index.php` (or
      `FoxessClient::getSchedule()`/the FoxESS app) to confirm `fdSoc`/`fdPwr`
      actually behave as this build assumes — that mapping is inferred, not
      confirmed against official docs (see CLAUDE.md).
- [ ] Set the cron entry (spec §10) and confirm `logs/scheduler.log` is
      actually being written on the real host — file permissions on shared
      hosts sometimes differ from local testing.
- [ ] Confirm the host actually serves `.htaccess` (Apache/`mod_authz_core`
      or `mod_access_compat`) — that's what's currently blocking web access
      to `data/scheduler.sqlite` (API key + password hash) and `logs/`. If
      the host is nginx or anything else, that protection silently does
      nothing and needs a different mechanism before going live.
- [ ] Put the site behind HTTPS at the host level — the login/settings forms
      submit the system password and API key in cleartext otherwise. This
      app doesn't force an HTTPS redirect itself (see CLAUDE.md).

## Near-term

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
4. **Multi-inverter support.** No current need (single inverter). Would mean
   the `settings` table gaining a device selector and `run.php` looping —
   not worth building until there's a second inverter.
5. **Login hardening** (rate limiting / lockout on `login.php`, CSRF token on
   `settings.php`). Skipped deliberately for a single-user tool behind a
   password wall — revisit if this is ever exposed somewhere less trusted,
   or if the "no CSRF token" choice starts to feel wrong once there's more
   than one form on the site.

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
