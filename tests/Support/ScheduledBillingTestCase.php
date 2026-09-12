<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

class ScheduledBillingTestCase extends BillingIntegrationTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cashier-entitlements.schedule', [
            'owner_type' => 'organization', 'sweep' => '0 * * * *',
            'recover' => '*/5 * * * *', 'sweep_limit' => 2, 'stale_after' => 3600,
        ]);
    }
}
