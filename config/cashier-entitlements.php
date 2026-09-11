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
];
