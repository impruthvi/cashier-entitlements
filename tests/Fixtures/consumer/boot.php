<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Impruthvi\CashierEntitlements\Billing\DecisionStatus;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapper;
use Impruthvi\CashierEntitlements\CashierEntitlementsServiceProvider;
use Impruthvi\CashierEntitlements\Drivers\EntitlementDriver;
use Impruthvi\CashierEntitlements\Drivers\NativeOnlyDriver;
use Impruthvi\CashierEntitlements\Overrides\NativeOverrides;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Usage\LimitExceeded;
use Impruthvi\CashierEntitlements\Usage\NativeUsage;
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

config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
    'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
], 'cashier-entitlements.freshness' => ['max_stale_age' => 60],
    'cashier-entitlements.meters' => ['projects' => 'calendar_day'], 'cashier-entitlements.overrides' => true]);
if ($kernel->call('vendor:publish', ['--tag' => 'cashier-entitlements-migrations', '--force' => true]) !== 0
    || $kernel->call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Native migration publish or execution failed.');
}
$catalog = new PriceCatalog('v1', [], ['projects' => 0]);
$app->instance(PriceCatalog::class, $catalog);
$owner = new OwnerReference('organization', 1, 'sqlite');
$at = new DateTimeImmutable('2026-09-11T12:00:00Z');
$store = $app->make(NativeStateStore::class);
$store->request($owner, $at);
if (! $store->complete($store->claim($owner, $at), $result, 'v1', $at, $at)
    || $app->make(LocalResolver::class)->for($owner, $at)->all() !== ['projects' => 0]) {
    throw new RuntimeException('Native apply or local resolution failed without optional dependencies.');
}

// M4: the usage and override tables must publish and work on a fresh install too.
$overrides = $app->make(NativeOverrides::class);
$usage = $app->make(NativeUsage::class);
$resolver = $app->make(LocalResolver::class);
try {
    $usage->admit($owner, 'projects', 1, 'first', $resolver, static fn () => null, $at);
    throw new RuntimeException('Usage admission ignored a zero plan limit.');
} catch (LimitExceeded) {
}

$grant = $overrides->grant($owner, 'projects', 2, 'consumer smoke', 'script', $at, $at->modify('+1 day'));
$receipt = $usage->admit($owner, 'projects', 1, 'first', $resolver, static fn () => null, $at);
if ($receipt->total !== 1 || $usage->usage($owner, 'projects', $at) !== 1
    || $resolver->for($owner, $at)->limit('projects') !== 2) {
    throw new RuntimeException('Usage admission or override resolution failed on a fresh install.');
}

$access = $resolver->for($owner, $at);
if ($access->usage('projects') !== 1 || $access->remaining('projects') !== 1
    || $access->record('projects', 2, 'measured-overage')->total !== 3
    || $access->remaining('projects') !== -1) {
    throw new RuntimeException('Owner-scoped usage or remaining failed on a fresh install.');
}

// M5: an installation that configures no adapter must stay native-only and still own its binding table.
if (! $app->make(EntitlementDriver::class) instanceof NativeOnlyDriver
    || ! Schema::hasTable('cashier_entitlement_driver_bindings')) {
    throw new RuntimeException('Default entitlement driver or its binding table failed on a fresh install.');
}

$overrides->revoke($owner, $grant, 'smoke complete', 'script', $at->modify('+1 hour'));
$history = $overrides->history($owner);
if ($resolver->for($owner, $at->modify('+1 hour'))->limit('projects') !== 0 || count($history) !== 2) {
    throw new RuntimeException('Override revocation or history failed on a fresh install.');
}

echo 'Consumer discovery, config/migration publishing, native apply, local resolution, usage admission and audited overrides passed without optional dependencies or Testbench.'.PHP_EOL;
