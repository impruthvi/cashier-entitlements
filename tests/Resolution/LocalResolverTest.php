<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Resolution\FeatureTypeMismatch;
use Impruthvi\CashierEntitlements\Resolution\FreshnessPolicy;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Resolution\UnknownFeature;
use Impruthvi\CashierEntitlements\Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_tables.php.stub')->up();
});

it('does not expire an explicitly applied free plan solely because provider observation ages', function () {
    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-11T12:00:00Z');
    $catalog = new PriceCatalog('v1', [], ['projects' => 1]);
    $store->request($owner, $at);
    $store->complete($store->claim($owner, $at), BillingDecision::allowed('free_plan', allowances: ['projects' => 1]), 'v1', $at, $at);
    expect((new LocalResolver($store, $catalog, new FreshnessPolicy(60)))->for($owner, $at->modify('+1 year'))->limit('projects'))->toBe(1);
});

it('requires an explicit outage policy and distinguishes unknown, boolean, zero and unlimited features', function () {
    expect(fn () => new FreshnessPolicy)->toThrow(ReadFailure::class)
        ->and(fn () => new FreshnessPolicy(60, true))->toThrow(ReadFailure::class);
    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-11T12:00:00Z');
    $catalog = new PriceCatalog('v1', ['price' => new PriceMapping('pro', ['ai' => true, 'projects' => 0])]);
    $access = (new LocalResolver($store, $catalog, new FreshnessPolicy(retainLastKnown: true)))->for($owner, $at);
    expect(fn () => $access->can('missing'))->toThrow(UnknownFeature::class)
        ->and(fn () => $access->can('projects'))->toThrow(FeatureTypeMismatch::class)
        ->and(fn () => $access->limit('ai'))->toThrow(FeatureTypeMismatch::class);
});

it('enforces stale age, catalog version and current time without caching access objects', function () {
    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 42, 'testing');
    $other = new OwnerReference('organization', 43, 'testing');
    $at = new DateTimeImmutable('2026-09-11T12:00:00Z');
    $catalog = new PriceCatalog('v1', ['price' => new PriceMapping('pro', ['ai' => true])]);
    $store->request($owner, $at);
    $store->complete($store->claim($owner, $at), BillingDecision::allowed('mapped', allowances: ['ai' => true]), 'v1', $at, $at);
    $resolver = new LocalResolver($store, $catalog, new FreshnessPolicy(60));
    expect($resolver->for($owner, $at->modify('+59 seconds'))->can('ai'))->toBeTrue()
        ->and($resolver->for($owner, $at->modify('+60 seconds'))->can('ai'))->toBeFalse()
        ->and($resolver->for($other, $at)->can('ai'))->toBeFalse();
    Date::setTestNow($at);
    try {
        $access = $resolver->for($owner);
        expect($access->can('ai'))->toBeTrue();
        Date::setTestNow($at->modify('+60 seconds'));
        expect($access->can('ai'))->toBeFalse();
    } finally {
        Date::setTestNow();
    }
    $retained = new LocalResolver($store, $catalog, new FreshnessPolicy(retainLastKnown: true));
    expect($retained->for($owner, $at->modify('+1 year'))->can('ai'))->toBeTrue();
    $changed = new LocalResolver($store, new PriceCatalog('v2', $catalog->prices), new FreshnessPolicy(retainLastKnown: true));
    expect($changed->for($owner, $at)->can('ai'))->toBeFalse();
});

it('resolves only applied grants and honors exact expiry without a provider or Cashier read', function () {
    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-11T12:00:00Z');
    $catalog = new PriceCatalog('v1', ['price_1' => new PriceMapping('pro', ['ai' => true, 'projects' => null])]);
    $resolver = new LocalResolver($store, $catalog, new FreshnessPolicy(maxStaleAgeSeconds: 60));
    expect($resolver->for($owner, $at)->can('ai'))->toBeFalse()
        ->and($resolver->for($owner, $at)->limit('projects'))->toBe(0);
    $store->request($owner, $at);
    $store->complete($store->claim($owner, $at), BillingDecision::allowed('mapped', $at->modify('+30 seconds'), ['ai' => true, 'projects' => null]), 'v1', $at, $at);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $access = $resolver->for($owner, $at);
    expect($access->can('ai'))->toBeTrue()->and($access->limit('projects'))->toBeNull();
    foreach (DB::getQueryLog() as $query) {
        expect($query['query'])->toContain('cashier_entitlement_states');
    }
    expect($resolver->for($owner, $at->modify('+30 seconds'))->can('ai'))->toBeFalse();
    $retained = new LocalResolver($store, $catalog, new FreshnessPolicy(retainLastKnown: true));
    expect($retained->for($owner, $at->modify('+30 seconds'))->can('ai'))->toBeFalse();
    DB::flushQueryLog();
    expect($access->all())->toBe(['ai' => true, 'projects' => null])
        ->and(DB::getQueryLog())->toHaveCount(1);
});
