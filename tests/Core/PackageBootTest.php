<?php

declare(strict_types=1);
use Illuminate\Support\ServiceProvider;
use Impruthvi\CashierEntitlements\CashierEntitlementsServiceProvider;
use Laravel\Cashier\Cashier;
use Laravel\Pennant\Feature;
use LucaLongo\LaravelEntitlements\Entitlements;

it('loads its config without replacing an application entitlement catalog', function () {
    expect(config('cashier-entitlements.enabled'))->toBeFalse()
        ->and(config('entitlements.type_enum'))->toBe('App\\Enums\\LicenseType');
});

it('keeps the diagnostic command available without loading an optional integration', function () {
    if (! class_exists(Cashier::class)) {
        $this->artisan('entitlements:reconcile', ['--json' => true])
            ->expectsOutputToContain('cashier_not_installed')->assertExitCode(2);
    } else {
        $this->artisan('entitlements:reconcile', ['--apply' => true, '--json' => true])
            ->expectsOutputToContain('apply_not_supported')->assertExitCode(2);
    }
});

it('registers a publishable config without registering a Pennant store', function () {
    $paths = ServiceProvider::pathsToPublish(
        CashierEntitlementsServiceProvider::class,
        'cashier-entitlements-config',
    );

    expect($paths)->toHaveCount(1)
        ->and(array_values($paths))->toBe([config_path('cashier-entitlements.php')])
        ->and(config('pennant.stores.entitlements'))->toBeNull();
});

it('has no hidden optional dependencies in isolated installs', function () {
    $mode = getenv('ENTITLEMENTS_OPTIONAL_MODE') ?: 'all';
    foreach ([
        'cashier' => Cashier::class,
        'masterix' => Entitlements::class,
        'pennant' => Feature::class,
    ] as $dependency => $class) {
        expect(class_exists($class))->toBe($mode === 'all' || $mode === $dependency);
    }
});
