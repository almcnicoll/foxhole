# Roadmap

Future changes for the FoxESS Agile scheduler, beyond the v1 build in
[doc/foxess-agile-scheduler-spec.md](doc/foxess-agile-scheduler-spec.md). Not
committed work — a list to prioritise from. Update this file as items are
picked up, dropped, or reprioritised.

## Before first live cron run (do these, not optional)

- [ ] Fill in real values in `config.php`: FoxESS `api_key`, `device_sn`,
      real Octopus tariff code for your region (see
      [CLAUDE.md](CLAUDE.md#decisions-made-while-building-things-the-spec-left-open)
      re: the region-letter fix), and `cost_basis.fixed_pence_per_kwh`.
- [ ] Run `php run.php --dry-run` and sanity-check the printed schedule
      against a day of real rates.
- [ ] Do one manual live push, then call `FoxessClient::getSchedule()` (or
      the FoxESS app) to confirm `fdSoc`/`fdPwr` actually behave as this
      build assumes — that mapping is inferred, not confirmed against
      official docs (see CLAUDE.md).
- [ ] Set the cron entry (spec §10) and confirm `logs/scheduler.log` and
      `logs/cron.log` are actually being written on the real host — file
      permissions on shared hosts sometimes differ from local testing.

## Near-term

- **Flux cost-basis mode.** `CostBasisProvider`'s `octopus_product` branch is
  a stub (throws on purpose). Implement once Flux is actually live on the
  account: fetch Flux's own rate structure, confirm whether it's genuinely
  banded (a handful of daily rates) vs. half-hourly, and map it onto 48
  slots. Don't build this speculatively before the tariff switch happens.
- **Verify FoxESS scheduler endpoint periodically.** FoxESS has changed this
  API before. If pushes start failing with unexpected errno values, check
  the live docs again before assuming it's a bug in this code.

## Candidate improvements (from spec §12's explicit out-of-scope list)

Ordered roughly by how much value they'd add relative to effort — not a
commitment, just a starting point for prioritisation:

1. **Basic history/cost reporting.** Even just appending each day's pushed
   schedule + rates to a running log would let you sanity-check the
   strategy is actually saving money, before investing in anything fancier.
   Doesn't need a database — flat files are fine at this volume (one run/day).
2. **Solar-generation-aware scheduling.** Spec explicitly calls this out as
   the natural next step for `ScheduleBuilder` — e.g. don't force-charge from
   the grid during slots where solar forecast already covers load. Needs a
   forecast source (Octopus doesn't provide one); Solcast or a similar API
   would be the likely dependency.
3. **Load-aware scheduling.** Similar idea, using historical/forecast
   household load instead of (or alongside) solar forecast.
4. **Web dashboard.** Only worth it once there's history to look at (#1).
   A read-only page over the JSON files already being written would cover
   most of the value without needing a database.
5. **Multi-inverter support.** No current need (single inverter). Would mean
   `config.php`'s `foxess` key becoming a list and `run.php` looping — not
   worth building until there's a second inverter.

## Explicitly not planned

- Retry/backoff beyond the single retry already built in — spec §12 rules
  this out deliberately; revisit only if transient failures become a
  recurring problem in the logs.
- A general-purpose plugin/strategy framework for scheduling algorithms.
  `ScheduleBuilder::build()` is one method with one job; if a second
  algorithm is ever actually needed, that's the point to extract an
  interface, not before.
