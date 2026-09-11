<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Tests\Support\BillingIntegrationTestCase;

pest()->extend(BillingIntegrationTestCase::class);

beforeEach(function () {
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_tables.php.stub')->up();
});

it('rejects an old claim after a newer request and leaves that request pending', function () {
    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-11T12:00:00Z');
    $store->request($owner, $at);
    $claim = $store->claim($owner, $at);
    $store->request($owner, $at);
    expect($store->complete($claim, BillingDecision::allowed('mapped', allowances: ['projects' => 99]), 'v1', $at, $at))->toBeFalse()
        ->and($store->state($owner)['applied_version'])->toBe(0)
        ->and($store->pending($at))->toHaveCount(1);
});

it('reclaims a crashed worker and fences both late success and late failure', function () {
    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-11T12:00:00Z');
    $store->request($owner, $at);
    $old = $store->claim($owner, $at, 1);
    $later = $at->modify('+2 seconds');
    $new = $store->claim($owner, $later);
    $store->complete($new, BillingDecision::allowed('mapped', allowances: ['projects' => 5]), 'v1', $later, $later);
    expect($store->complete($old, BillingDecision::allowed('mapped', allowances: ['projects' => 99]), 'v1', $at, $later))->toBeFalse();
    $store->fail($old, 'provider_unavailable', $later);
    $store->fail($new, 'late_exception', $later);
    expect($store->state($owner)['allowances'])->toBe(['projects' => 5])
        ->and($store->state($owner)['last_error'])->toBeNull();
});

it('advances observation and pending progress without changing an identical grant version', function () {
    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-11T12:00:00Z');
    foreach ([$at, $at->modify('+1 minute')] as $time) {
        $store->request($owner, $time);
        $store->complete($store->claim($owner, $time), BillingDecision::allowed('mapped', allowances: ['projects' => 10]), 'v1', $time, $time);
    }
    expect($store->state($owner)['applied_version'])->toBe(1)
        ->and($store->state($owner)['completed_sequence'])->toBe(2)
        ->and((int) $store->state($owner)['observed_at'])->toBe($at->getTimestamp() + 60);
});

it('rolls grants and completion back together when the native write fails', function () {
    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable;
    $store->request($owner, $at);
    $claim = $store->claim($owner, $at);
    DB::statement("CREATE TRIGGER fail_apply BEFORE UPDATE OF projection ON cashier_entitlement_states BEGIN SELECT RAISE(ABORT, 'test failure'); END");
    expect(fn () => $store->complete($claim, BillingDecision::allowed('mapped', allowances: ['projects' => 10]), 'v1', $at, $at))->toThrow(QueryException::class);
    expect($store->state($owner)['completed_sequence'])->toBe(0)
        ->and($store->state($owner)['applied_version'])->toBe(0)->and($store->state($owner)['allowances'])->toBe([]);
});

it('durably requests, claims and atomically applies an owner projection once', function () {
    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 'org-uuid', 'testing');
    $at = new DateTimeImmutable('2026-09-11T12:00:00Z');
    $store->request($owner, $at, 'evt_1');
    $store->request($owner, $at, 'evt_1');
    expect($store->state($owner)['requested_sequence'])->toBe(1);
    $claim = $store->claim($owner, $at);
    expect($claim)->not->toBeNull();
    expect($store->complete($claim, BillingDecision::allowed('mapped', allowances: ['projects' => 10, 'ai' => true]), 'v1', $at, $at))
        ->toBeTrue();
    $state = $store->state($owner);
    expect($state['completed_sequence'])->toBe(1)->and($state['applied_version'])->toBe(1)
        ->and($state['allowances'])->toBe(['ai' => true, 'projects' => 10])
        ->and($store->claim($owner, $at))->toBeNull();
});
