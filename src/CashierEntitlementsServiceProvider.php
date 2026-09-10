<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class CashierEntitlementsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('cashier-entitlements')->hasConfigFile();
    }
}
