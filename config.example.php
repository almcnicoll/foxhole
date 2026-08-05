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
    ],
    'battery' => [
        'capacity_kwh'      => 10.0,
        'max_charge_kw'     => 3.0,
        'max_discharge_kw'  => 3.0,
        'min_soc_on_grid'   => 15,   // percent, respects inverter's own floor
        'reserve_soc'       => 15,   // percent never discharged below
    ],
    'strategy' => [
        'cheap_slots_to_charge'     => 6,  // upper cap on half-hour slots to force-charge, see ScheduleBuilder
        'expensive_slots_to_export' => 4,  // number of half-hour slots to force-discharge/export
        'timezone' => 'Europe/London',
    ],
    'cost_basis' => [
        // What you actually pay for electricity — used as the "worth charging below this" threshold.
        // Only ever charge from the grid when the Agile rate is cheaper than this.
        'mode' => 'fixed', // 'fixed' now; 'octopus_product' once a time-banded tariff (e.g. Flux) is live
        'fixed_pence_per_kwh' => 24.50, // current standing tariff rate — update when tariff changes
        // Used only when mode = 'octopus_product', same shape as the octopus config above:
        'product_code' => null,
        'tariff_code'  => null,
    ],
    'notify' => [
        'alert_email' => 'you@example.com', // optional, for failure notifications
    ],
];
