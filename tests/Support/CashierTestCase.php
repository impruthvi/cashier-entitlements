<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use Impruthvi\CashierEntitlements\Tests\TestCase;
use Laravel\Cashier\CashierServiceProvider;

class CashierTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), CashierServiceProvider::class];
    }
}
