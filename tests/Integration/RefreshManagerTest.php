<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Jobs\RefreshOwner;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Reconciliation\RefreshManager;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Impruthvi\CashierEntitlements\Tests\Support\BillingIntegrationTestCase;
use Impruthvi\CashierEntitlements\Tests\Support\StripeFixture;
use Stripe\Exception\ApiConnectionException;

pest()->extend(BillingIntegrationTestCase::class);

beforeEach(function () {
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_tables.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_billing_periods_table.php.stub')->up();
    config(['cashier-entitlements.enabled' => true, 'cashier-entitlements.freshness' => ['max_stale_age' => 60]]);
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]));
    Date::setTestNow('2026-09-11T12:00:00Z');
    Bus::fake();
});

it('verifies previously observed subscription IDs even when Cashier never stored them', function () {
    $owner = $this->organization();
    $fixture = new StripeFixture([
        StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()]),
        StripeFixture::page([]), StripeFixture::subscription(), StripeFixture::page([StripeFixture::item()]),
    ]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    $manager = app(RefreshManager::class);
    $reference = $manager->request($owner);
    $manager->refresh($reference);
    $manager->request($owner);
    $manager->refresh($reference);
    expect(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(10);
    $fixture->assertReadOnly();
});

it('retains successful grants on provider failure and revokes after a successful terminal observation', function () {
    $owner = $this->organization();
    $fixture = new StripeFixture([
        StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()]),
        new ApiConnectionException('private provider error'),
        StripeFixture::page([StripeFixture::subscription(status: 'canceled')]), StripeFixture::page([]),
    ]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    $manager = app(RefreshManager::class);
    $reference = $manager->request($owner);
    $manager->refresh($reference);
    $manager->request($owner);
    expect(fn () => $manager->refresh($reference))->toThrow(ReadFailure::class, 'provider_unavailable');
    expect(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(10);
    $manager->request($owner);
    $manager->refresh($reference);
    expect(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(0);
});

it('does not dispatch rolled-back work and recovers committed work after queue delivery fails', function () {
    $owner = $this->organization();
    $manager = app(RefreshManager::class);
    DB::beginTransaction();
    $manager->request($owner);
    DB::rollBack();
    Bus::assertNothingDispatched();
    expect($manager->recover())->toBe(0);
    $bus = Bus::getFacadeRoot();
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue secret'));
    $reference = $manager->request($owner);
    Bus::swap($bus);
    expect($manager->recover())->toBe(1);
    Bus::assertDispatched(RefreshOwner::class, fn ($job) => $job->owner->equals($reference));
});

it('fences a new request arriving during network IO without holding a transaction', function () {
    $owner = $this->organization();
    $fixture = new StripeFixture([
        function () use ($owner) {
            expect(DB::transactionLevel())->toBe(0);
            app(RefreshManager::class)->request($owner, 'evt_newer');

            return StripeFixture::page([StripeFixture::subscription()]);
        }, StripeFixture::page([StripeFixture::item()]),
    ]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    $manager = app(RefreshManager::class);
    $reference = $manager->request($owner);
    expect($manager->refresh($reference))->toBe('superseded')
        ->and(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(0)
        ->and($manager->recover())->toBe(1);
});

afterEach(fn () => Date::setTestNow());

it('backs off a deleted owner without starving other pending work', function () {
    $owner = $this->organization();
    $manager = app(RefreshManager::class);
    $reference = $manager->request($owner);
    $manager->request($this->organization('other', 'cus_other'));
    $owner->delete();
    expect($manager->recover())->toBe(1);
    expect(app(NativeStateStore::class)->state($reference)['last_error'])->toBe('unknown_owner');
});

it('dispatches durable work after commit and applies current provider facts without changing Cashier', function () {
    $owner = $this->organization();
    $fixture = new StripeFixture([StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()])]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    $manager = app(RefreshManager::class);
    DB::beginTransaction();
    $reference = $manager->request($owner, 'evt_1');
    Bus::assertNothingDispatched();
    DB::commit();
    Bus::assertDispatched(RefreshOwner::class);
    expect($manager->refresh($reference))->toBe('applied')
        ->and(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(10)
        ->and($owner->subscriptions()->count())->toBe(0);
    $fixture->assertReadOnly();
});
