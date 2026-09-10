<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use Impruthvi\CashierEntitlements\Tests\TestCase;
use Laravel\Pennant\PennantServiceProvider;

class PennantTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PennantServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('pennant.default', 'array');
        $app['config']->set('pennant.stores.entitlements', ['driver' => 'm0-probe']);
    }
}
