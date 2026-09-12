<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Resolution\FeatureTypeMismatch;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Resolution\UnknownFeature;
use Impruthvi\CashierEntitlements\Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_tables.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_usage_tables.php.stub')->up();
    config(['cashier-entitlements.freshness' => ['retain_last_known' => true],
        'cashier-entitlements.meters' => ['projects' => 'calendar_day', 'exports' => 'calendar_day']]);
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', [], ['projects' => 2, 'exports' => null, 'ai' => false]));
});

it('keeps retries in their original period while a long lived owner object sees rollover', function () {
    $owner = new OwnerReference('organization', 42, 'testing');
    $before = new DateTimeImmutable('2026-09-12T23:59:59.999999Z');
    $after = new DateTimeImmutable('2026-09-13T00:00:00Z');
    Date::setTestNow($before);
    try {
        $access = app(LocalResolver::class)->for($owner);
        $first = $access->record('projects', 1, 'one');
        Date::setTestNow($after);
        expect($access->record('projects', 1, 'one'))->toEqual($first)
            ->and($access->usage('projects'))->toBe(0)
            ->and($access->remaining('projects'))->toBe(0);
        $access->record('projects', 2, 'late', occurredAt: $before);
        expect(app(LocalResolver::class)->for($owner, $before)->usage('projects'))->toBe(3)
            ->and($access->usage('projects'))->toBe(0);
    } finally {
        Date::setTestNow();
    }
});

it('preserves unknown feature type and period errors through the owner API', function () {
    $access = app(LocalResolver::class)->for(new OwnerReference('organization', 42, 'testing'));
    foreach (['usage', 'remaining', 'record'] as $method) {
        $args = $method === 'record' ? [1, 'operation'] : [];
        expect(fn () => $access->$method('missing', ...$args))->toThrow(UnknownFeature::class)
            ->and(fn () => $access->$method('ai', ...$args))->toThrow(FeatureTypeMismatch::class);
    }
    config(['cashier-entitlements.meters' => []]);
    $access = app(LocalResolver::class)->for(new OwnerReference('organization', 42, 'testing'));
    expect(fn () => $access->remaining('projects'))->toThrow(ReadFailure::class, 'missing_meter_period')
        ->and(fn () => $access->remaining('exports'))->toThrow(ReadFailure::class, 'missing_meter_period');
});

it('exposes owner scoped usage and finite negative denied and unlimited remaining', function () {
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-12T23:59:59.999999Z');
    $store = app(NativeStateStore::class);
    $resolver = app(LocalResolver::class);
    $access = $resolver->for($owner, $at);
    expect($access->usage('projects'))->toBe(0)->and($access->remaining('projects'))->toBe(0);
    $store->request($owner, $at);
    $store->complete($store->claim($owner, $at), BillingDecision::allowed('free_plan', allowances: ['projects' => 2, 'exports' => null]), 'v1', $at, $at);
    expect($access->remaining('projects'))->toBe(2)->and($access->remaining('exports'))->toBeNull();
    $first = $access->record('projects', 3, idempotencyKey: 'operation');
    expect($access->record('projects', 3, idempotencyKey: 'operation'))->toEqual($first)
        ->and($access->usage('projects'))->toBe(3)
        ->and($access->remaining('projects'))->toBe(-1);
    $access->record('exports', 7, idempotencyKey: 'operation');
    expect($access->usage('exports'))->toBe(7)->and($access->remaining('exports'))->toBeNull();
    expect($resolver->for(new OwnerReference('organization', 43, 'testing'), $at)->usage('projects'))->toBe(0);
    $store->request($owner, $at);
    $store->complete($store->claim($owner, $at), BillingDecision::denied('canceled'), 'v1', $at, $at);
    expect($access->remaining('projects'))->toBe(-3);
});
