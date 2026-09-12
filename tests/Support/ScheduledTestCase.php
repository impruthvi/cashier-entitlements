<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

/** Schedule registration happens at boot, so its configuration must exist before the app starts. */
class ScheduledTestCase extends CashierTestCase
{
    /** @var array<string, mixed> */
    public static array $schedule = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cashier-entitlements.schedule', static::$schedule);
    }
}
