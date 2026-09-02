<?php
// Copy to config.php and fill in real values. config.php is gitignored.
return [
    'octopus' => [
        'product_code' => 'AGILE-24-10-01',        // confirm current live product code
        'tariff_code'  => 'E-1R-AGILE-24-10-01-C',  // region-specific GSP letter suffix, e.g. -C for London (A-P, not X — see CLAUDE.md)
        // Only used when export_price_mode (settings.php) is 'api' instead of the
        // default 'fixed' — Octopus's half-hourly outgoing (export/sale) tariff.
        'export_product_code' => 'AGILE-OUTGOING-19-05-13',
        'export_tariff_code'  => 'E-1R-AGILE-OUTGOING-19-05-13-C',
    ],
    'foxess' => [
        // api_key / device_sn are no longer set here — enter them via settings.php,
        // stored in data/scheduler.sqlite. base_url is the only non-secret bit left.
        'base_url' => 'https://www.foxesscloud.com',
        // FoxESS's v2 scheduler/enable endpoint hard-rejects any push of more than 8
        // groups (errno 40257) — confirmed live by bisecting a real failing payload, not
        // documented anywhere by FoxESS. Not a business setting, a technical safety limit
        // on this specific API version — see CLAUDE.md's "FoxESS scheduler endpoint" note
        // and doc/foxess-scheduler-api-migration-plan.md before changing it. Safe to raise
        // (or lower) here, without a deploy of new logic, if a future API version's real
        // enforced limit turns out to differ from 8 — Runner.php reads this value fresh
        // each run, it isn't cached anywhere.
        'max_scheduler_groups' => 8,
    ],
    // Battery specs (capacity, max charge/discharge power, SoC floors) are no longer
    // configured here — set them via settings.php's "Battery" section instead, same
    // reason FoxESS credentials moved to settings.php: editable without a deploy, and
    // less likely to be forgotten at some too-conservative placeholder value. See
    // CLAUDE.md's "Battery config moved to settings".
    'strategy' => [
        'cheap_slots_to_charge'     => 6,  // upper cap on half-hour slots to force-charge, see ScheduleBuilder
        'expensive_slots_to_export' => 4,  // number of half-hour slots to force-discharge/export
        'timezone' => 'Europe/London',
    ],
    'cost_basis' => [
        // What you actually pay for electricity — used as the "worth charging below this" threshold.
        // Only ever charge from the grid when the Agile rate is cheaper than this. Not a real tariff
        // fetch — 'octopus_product' mode is an unimplemented stub (see CostBasisProvider), so 'fixed'
        // is the only mode that currently does anything.
        'mode' => 'fixed',
        'fixed_pence_per_kwh' => 24.50, // current standing tariff rate — update when tariff changes
    ],
    'notify' => [
        'alert_email' => 'you@example.com', // optional, for failure notifications
    ],
];
