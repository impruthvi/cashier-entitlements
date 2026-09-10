<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Impruthvi\CashierEntitlements\Billing\DecisionStatus;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapper;
use Impruthvi\CashierEntitlements\CashierEntitlementsServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Pennant\Feature;
use LucaLongo\LaravelEntitlements\Entitlements;
use Orchestra\Testbench\TestCase;

require __DIR__.'/vendor/autoload.php';

$app = Application::configure(basePath: __DIR__)->create();
$app->make(Kernel::class)->bootstrap();

if (! $app->getProvider(CashierEntitlementsServiceProvider::class) || config('cashier-entitlements.enabled') !== false) {
    throw new RuntimeException('Package auto-discovery or default configuration failed.');
}

foreach ([Cashier::class, Feature::class, Entitlements::class, TestCase::class] as $optional) {
    if (class_exists($optional)) {
        throw new RuntimeException('Unexpected development dependency: '.$optional);
    }
}

$kernel = $app->make(Kernel::class);
if ($kernel->call('vendor:publish', ['--tag' => 'cashier-entitlements-config', '--force' => true]) !== 0) {
    throw new RuntimeException('Configuration publish failed.');
}
if (! is_file(__DIR__.'/config/cashier-entitlements.php')) {
    throw new RuntimeException('Published configuration was not created.');
}

$result = (new PriceMapper)->map(new OwnerReference('organization', 1), [], new PriceCatalog('v1', [], ['projects' => 0]), new DateTimeImmutable('2026-09-10T12:00:00Z'), complete: true);
if ($result->status !== DecisionStatus::Allowed || $result->allowances !== ['projects' => 0]) {
    throw new RuntimeException('M1 billing calculation failed without optional dependencies.');
}

echo 'Consumer boot, auto-discovery, config publish and M1 billing calculation passed without optional dependencies or Testbench.'.PHP_EOL;
