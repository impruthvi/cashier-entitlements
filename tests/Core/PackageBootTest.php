<?php

declare(strict_types=1);
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\CashierEntitlementsServiceProvider;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Usage\MeterPeriods;
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
            ->expectsOutputToContain('application_disabled')->assertExitCode(2);
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

it('publishes every native migration the apply and usage paths write to', function () {
    $paths = array_values(ServiceProvider::pathsToPublish(
        CashierEntitlementsServiceProvider::class,
        'cashier-entitlements-migrations',
    ));

    expect($paths)->toHaveCount(3);
    foreach (['create_cashier_entitlements_tables', 'create_cashier_entitlements_usage_tables', 'create_cashier_entitlements_overrides_table'] as $migration) {
        expect(implode(' ', $paths))->toContain($migration);
    }
});

it('rejects an unusable meter rule instead of silently metering the wrong period', function () {
    foreach ([
        'calendar_day',
        ['projects' => 'calendar_week'],
        ['projects' => 'billing:'],
        ['projects' => 'billing:   '],
        ['projects' => 30],
        ['projects' => ['calendar_day']],
    ] as $meters) {
        config(['cashier-entitlements.meters' => $meters]);
        expect(fn () => app()->make(MeterPeriods::class))->toThrow(ReadFailure::class, 'invalid_meter_rules');
    }

    config(['cashier-entitlements.meters' => ['projects' => 'calendar_month', 'seats' => 'billing:price_pro']]);
    expect(app()->make(MeterPeriods::class)->rules)->toBe(['projects' => 'calendar_month', 'seats' => 'billing:price_pro']);
});

it('leaves the override ledger out of resolution until an installation opts in', function () {
    foreach (['create_cashier_entitlements_tables', 'create_cashier_entitlements_overrides_table'] as $migration) {
        (require __DIR__.'/../../database/migrations/'.$migration.'.php.stub')->up();
    }
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', [], ['projects' => 0]));
    config(['cashier-entitlements.freshness' => ['retain_last_known' => true]]);

    config(['cashier-entitlements.overrides' => 'yes']);
    expect(fn () => app()->make(LocalResolver::class))->toThrow(ReadFailure::class, 'invalid_override_setting');

    foreach ([false => 0, true => 1] as $enabled => $expected) {
        config(['cashier-entitlements.overrides' => (bool) $enabled]);
        expect(countOverrideQueries(fn () => app()->make(LocalResolver::class)
            ->for(new OwnerReference('organization', 7, DB::connection()->getName()), new DateTimeImmutable('2026-09-12T12:00:00Z'))->all()))->toBe($expected);
    }
});

function countOverrideQueries(Closure $resolve): int
{
    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        $queries += str_contains($query->sql, 'cashier_entitlement_overrides') ? 1 : 0;
    });
    $resolve();

    return $queries;
}
