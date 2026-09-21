<?php

declare(strict_types=1);

return [
    // Opt in to native application and background refresh. Dry-run remains read-only.
    'enabled' => false,
    'provider_context' => 'platform',
    'live_mode' => false,
    'subscription_type' => 'default',
    'connection' => null,
    // Choose exactly one: ['max_stale_age' => 3600] or ['retain_last_known' => true].
    'freshness' => [],
    // Usage period per numeric feature: 'lifetime', 'calendar_day', 'calendar_month' or 'billing:<price_id>'.
    'meters' => [],
    // Consult the audited override ledger during local resolution. Costs one extra query per resolve.
    'overrides' => false,
    // Where applied entitlements are projected: 'native' only, or also 'masterix'.
    'driver' => 'native',
    // Application plan key => Masterix plan key. Required for every plan the catalog can resolve.
    'masterix' => ['plans' => []],
    // Scheduled convergence. Both entries need 'owner_type'; a null expression schedules nothing.
    // Cron expressions only, so the schedule is explicit rather than inferred from a method name.
    'schedule' => [
        'owner_type' => null,
        'sweep' => null,
        'sweep_limit' => 100,
        'stale_after' => null,
        'recover' => null,
    ],
];
