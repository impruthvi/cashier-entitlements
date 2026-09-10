<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests;

use Impruthvi\CashierEntitlements\CashierEntitlementsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [CashierEntitlementsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('entitlements.type_enum', 'App\\Enums\\LicenseType');
    }
}
