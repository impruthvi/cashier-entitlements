<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Bridges\Pennant\OwnerScope;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Tests\Support\PennantTestCase;
use Laravel\Pennant\Feature;

pest()->extend(PennantTestCase::class);

beforeEach(function () {
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_tables.php.stub')->up();
    config(['pennant.stores.billing-access' => ['driver' => 'cashier-entitlements'],
        'cashier-entitlements.freshness' => ['retain_last_known' => true]]);
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', [], ['ai' => false, 'projects' => 0, 'storage' => null]));
    $this->travelTo(now()->setDate(2026, 9, 12)->startOfDay());
});

/** Applies a grant through the same durable path a refresh uses, so Pennant reads real state. */
function grant(OwnerReference $owner, array $allowances, ?DateTimeImmutable $validUntil = null): void
{
    $at = now()->toDateTimeImmutable();
    $store = app(NativeStateStore::class);
    $store->request($owner, $at);
    $store->complete($store->claim($owner, $at), BillingDecision::allowed('pro_plan', $validUntil, $allowances, 'pro'), 'v1', $at, $at);
}

it('enumerates the application catalog and batches all features in one local owner read', function () {
    $scope = new OwnerScope(new OwnerReference('organization', 42, 'testing'));
    $store = Feature::store('billing-access');
    expect($store->defined())->toBe(['ai', 'projects', 'storage']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($store->for($scope)->all())->toBe(['ai' => false, 'projects' => 0, 'storage' => 0]);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1)->and($queries[0]['query'])->toContain('cashier_entitlement_states');
});

it('reads native boolean zero and unlimited values through Pennant with explicit owner isolation', function () {
    $owner = new OwnerReference('organization', 'uuid-owner', 'testing');
    grant($owner, ['ai' => true, 'projects' => 0, 'storage' => null]);
    $pennant = Feature::store('billing-access');
    $scope = new OwnerScope($owner);

    expect($pennant->for($scope)->active('ai'))->toBeTrue()
        ->and($pennant->for($scope)->value('projects'))->toBe(0)
        ->and($pennant->for($scope)->value('storage'))->toBeNull()
        ->and($pennant->for(new OwnerScope(new OwnerReference('organization', 'another-owner', 'testing')))->active('ai'))->toBeFalse()
        ->and(config('pennant.default'))->toBe('array');
});

it('batches one read per distinct owner rather than one per feature', function () {
    $first = new OwnerScope(new OwnerReference('organization', 1, 'testing'));
    $second = new OwnerScope(new OwnerReference('organization', 2, 'testing'));
    DB::enableQueryLog();
    DB::flushQueryLog();
    Feature::store('billing-access')->for([$first, $second])->load(['ai', 'projects', 'storage']);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(2);
});

it('rejects any scope that is not an explicit entitlements owner', function (mixed $scope) {
    Feature::store('billing-access')->for($scope)->value('ai');
})->with([null, 'organization:1', 42])
    ->throws(InvalidArgumentException::class, 'explicit_pennant_owner_scope_required');

it('rejects an owner reference that cannot identify a tenant', function () {
    new OwnerScope(new OwnerReference('organization', ' ', 'testing'));
})->throws(InvalidArgumentException::class, 'invalid_pennant_owner');

it('rejects every mutation through the public store', function (string $operation) {
    $store = Feature::store('billing-access');
    $scope = new OwnerScope(new OwnerReference('organization', 1, 'testing'));
    match ($operation) {
        'define' => $store->define('ai', fn () => true),
        'set' => $store->for($scope)->activate('ai'),
        'delete' => $store->for($scope)->forget('ai'),
        'all' => $store->activateForEveryone('ai'),
        'purge' => $store->purge(),
    };
})->with(['define', 'set', 'delete', 'all', 'purge'])
    ->throws(LogicException::class, 'Entitlements are read-only in Pennant.');

it('serves a stale snapshot after a grant changes until the decorator cache is flushed', function () {
    $owner = new OwnerReference('organization', 7, 'testing');
    grant($owner, ['ai' => true, 'projects' => 10, 'storage' => null]);
    $store = Feature::store('billing-access');
    $scope = new OwnerScope($owner);
    expect($store->for($scope)->value('projects'))->toBe(10);

    grant($owner, ['ai' => false, 'projects' => 5, 'storage' => null]);
    expect($store->for($scope)->value('projects'))->toBe(10)
        ->and(app(LocalResolver::class)->for($owner)->limit('projects'))->toBe(5);

    $store->flushCache();
    expect($store->for($scope)->value('projects'))->toBe(5);
});

it('serves a stale snapshot after an entitlement expires on time alone until the cache is flushed', function () {
    $owner = new OwnerReference('organization', 8, 'testing');
    grant($owner, ['ai' => true, 'projects' => 10, 'storage' => null], now()->addMinute()->toDateTimeImmutable());
    $store = Feature::store('billing-access');
    $scope = new OwnerScope($owner);
    expect($store->for($scope)->active('ai'))->toBeTrue();

    $this->travel(61)->seconds();
    expect($store->for($scope)->active('ai'))->toBeTrue()
        ->and(app(LocalResolver::class)->for($owner)->can('ai'))->toBeFalse();

    $store->flushCache();
    expect($store->for($scope)->active('ai'))->toBeFalse();
});

it('gives each queued job a fresh snapshot because Pennant flushes on job completion', function () {
    $owner = new OwnerReference('organization', 9, 'testing');
    grant($owner, ['ai' => true, 'projects' => 10, 'storage' => null]);
    $scope = new OwnerScope($owner);
    expect(Feature::store('billing-access')->for($scope)->value('projects'))->toBe(10);

    grant($owner, ['ai' => true, 'projects' => 3, 'storage' => null]);
    Event::dispatch(new JobProcessed('sync', Mockery::mock(Job::class)));

    expect(Feature::store('billing-access')->for($scope)->value('projects'))->toBe(3);
});
