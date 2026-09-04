# Foxhole

A small PHP app that fetches [Octopus Agile](https://octopus.energy/agile/) half-hourly
electricity rates, works out a battery charge/discharge schedule from them, and pushes
that schedule to a [FoxESS](https://www.fox-ess.com/) inverter — so the battery charges
when power is cheap and discharges (or sells back) when it's expensive, without any
manual scheduling in the FoxESS app.

Runs as a one-shot cron job on ordinary shared hosting: no daemon, no framework, no
Composer dependencies — cURL, JSON and SQLite are all bundled PHP extensions. A small
password-walled web UI sits on top for keeping an eye on it.

## Features

- **Automatic scheduling** against real Octopus Agile import/export prices (or a fixed
  price per side, independently configurable), fetched and stored permanently as they're
  published.
- **Three pluggable scheduling algorithms**, switchable per-install from the "Schedulers"
  page, each with a live preview before you commit to it:
  - **Classic** — a fast, explainable price-threshold heuristic (charge below cost basis
    or for arbitrage, discharge at the peak).
  - **Forecast-weighted** — solar-generation- and battery-SoC-aware, simulates the day
    ahead before choosing slots.
  - **Modelling** — an exact DP/Bellman solver over a discretised state-of-charge grid;
    genuinely optimises the whole rolling window rather than applying heuristics.
- **Dashboard** with the latest prices, resolved schedule (with a plain-English
  explanation per slot), live battery state of charge, and a "Run now" button.
- **Manual overrides** ("Fill your boots" / "Power down") for a planned event — an EV
  charge, a known high-usage evening — that need the battery to behave differently for a
  window, feeding into every scheduler (including as hard constraints for the DP solver).
- **Generation/usage history** vs. forecast, browsable by day/week/month/year, backfilled
  from FoxESS's own report data.
- **Multi-inverter support** — one schedule, pushed to as many configured devices as you
  like.
- **API call log** — every FoxESS API call, request/response, most recent first, with
  automatic redaction of old request/response bodies.

## Requirements

- PHP 8.1+ with `curl`, `json`, and `pdo_sqlite` (all bundled in a standard PHP build).
- An [Octopus Energy](https://octopus.energy/) account on an Agile tariff (or just a
  fixed price, if you'd rather not use the live API).
- A FoxESS inverter with Cloud API access (an API key from the FoxESS Cloud app).
- A way to run a scheduled task once a day — real cron, a hosting panel's "Scheduled
  Tasks" feature, or (if neither is available) a URL-based cron alternative is built in.

## Getting started

```bash
git clone https://github.com/almcnicoll/foxhole.git
cd foxhole
cp config.example.php config.php   # edit: Octopus tariff codes, timezone, etc.
php -S localhost:8000              # serve the UI locally
```

Visit `http://localhost:8000/login.php` — the default password is `foxhole` until you set
a real one. Head to `settings.php` first to enter your FoxESS API key, device serial
number(s), battery specs, and price sources.

Once configured, try a dry run (fetches real prices, computes a schedule, prints it —
never touches FoxESS or needs credentials):

```bash
php run.php --dry-run
```

When you're happy with the output, set up the actual cron job (see
[`roadmap.MD`](roadmap.MD) for a pre-flight checklist) — typically once a day, a little
after Octopus publishes tomorrow's Agile rates (~16:00 UK time):

```
17:00  php /path/to/foxhole/run.php
```

If your host can only trigger scheduled tasks by hitting a URL (no PHP CLI access), use
`cron.php?token=...` instead — see `settings.php` for the token.

## Documentation

- [`doc/foxess-agile-scheduler-spec.md`](doc/foxess-agile-scheduler-spec.md) — the
  original design spec.
- [`CLAUDE.md`](CLAUDE.md) — the maintainer's notes: every non-obvious decision, live-API
  finding, and bug fix made while building this, kept up to date as the single source of
  architectural truth for the project.
- [`roadmap.MD`](roadmap.MD) — planned/possible future work.

## Running the tests

```bash
php -l run.php src/*.php *.php     # syntax check
php tests/self_check.php           # logic tests (uses a throwaway SQLite file)
```

## License

No license file yet — all rights reserved by default. Ask before reusing this beyond
personal/educational purposes.
