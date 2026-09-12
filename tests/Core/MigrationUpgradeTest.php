<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\CashierEntitlementsServiceProvider;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Usage\MeterPeriods;
use Impruthvi\CashierEntitlements\Usage\NativeUsage;

it('upgrades an already migrated installation without losing applied state or billing history', function (string $baseline) {
    // Frozen published schemas: an existing installation never re-runs its core migration.
    (require __DIR__.'/../Fixtures/migrations/'.$baseline.'-core.php.stub')->up();
    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-12T12:00:00Z');
    $decision = BillingDecision::allowed('mapped', allowances: ['projects' => 5]);
    $facts = [['items' => [['id' => 'si_1', 'price_id' => 'price_pro',
        'period_start' => $at->format(DATE_ATOM), 'period_end' => $at->modify('+1 month')->format(DATE_ATOM)]]]];
    $store->request($owner, $at);
    $store->complete($store->claim($owner, $at), $decision, 'v1', $at, $at, $baseline === 'm4' ? $facts : []);
    $before = $store->state($owner);
    foreach (ServiceProvider::pathsToPublish(CashierEntitlementsServiceProvider::class, 'cashier-entitlements-migrations') as $source => $destination) {
        if (! str_ends_with($source, '/create_cashier_entitlements_tables.php.stub')) {
            (require $source)->up();
        }
    }
    expect($store->state($owner))->toBe($before);
    $usage = new NativeUsage($store, new PriceCatalog('v1', [], ['projects' => 5]), new MeterPeriods(['projects' => 'billing:price_pro']));
    if ($baseline === 'm4') {
        expect($usage->usage($owner, 'projects', $at))->toBe(0);
    }
    $store->request($owner, $at);
    expect($store->complete($store->claim($owner, $at), $decision, 'v1', $at, $at, $facts))->toBeTrue();
    expect($usage->record($owner, 'projects', 2, 'after-upgrade', $at)->total)->toBe(2)
        ->and($usage->usage($owner, 'projects', $at))->toBe(2);
})->with(['m3', 'm4']);
