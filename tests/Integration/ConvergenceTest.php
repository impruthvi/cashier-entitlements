<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Impruthvi\CashierEntitlements\Tests\Support\BillingIntegrationTestCase;
use Impruthvi\CashierEntitlements\Tests\Support\StripeFixture;

pest()->extend(BillingIntegrationTestCase::class);

beforeEach(function () {
    foreach (['create_cashier_entitlements_tables', 'create_cashier_entitlements_billing_periods_table',
        'create_cashier_entitlements_audit_runs_table'] as $migration) {
        (require __DIR__.'/../../database/migrations/'.$migration.'.php.stub')->up();
    }
    config(['cashier-entitlements.enabled' => true, 'cashier-entitlements.freshness' => ['max_stale_age' => 3600]]);
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]));
    Date::setTestNow('2026-09-12T12:00:00Z');
});

/**
 * The provider oracle. Expected values come from these fixture responses, never from the
 * Cashier rows under test, so a shared projection bug cannot make both sides agree.
 */
function oracle(array $responses): void
{
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource((new StripeFixture($responses))->client()));
}

function sweep(array $options = []): array
{
    $exit = Artisan::call('entitlements:sweep', ['--owner-type' => 'organization', '--json' => true, ...$options]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

it('converges an omitted creation event that Cashier never recorded, then applies nothing further', function () {
    // The `customer.subscription.created` webhook never arrived: Stripe has the subscription,
    // Cashier has no local row, and no event will ever prompt this owner.
    $owner = $this->organization();
    oracle([StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()])]);
    $reference = app(OwnerLocator::class)->reference($owner);
    expect(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(0);

    [$exit, $report] = sweep();

    expect($exit)->toBe(0)
        ->and($report)->toMatchArray(['complete' => true, 'examined' => 1, 'requested' => 1, 'failed' => 0])
        ->and(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(10);

    // A second scan finds the observation fresh, requests nothing and changes no grant version.
    $version = app(NativeStateStore::class)->state($reference)['applied_version'];
    [$exit, $second] = sweep();
    expect($exit)->toBe(0)->and($second['requested'])->toBe(0)
        ->and(app(NativeStateStore::class)->state($reference)['applied_version'])->toBe($version);
});

it('converges an omitted deletion event once the observation ages past the threshold', function () {
    $owner = $this->organization();
    // Cashier still shows the subscription active because its deletion webhook was dropped.
    $this->subscription($owner);
    oracle([
        StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()]),
        StripeFixture::page([]), StripeFixture::subscription(status: 'canceled'), StripeFixture::page([]),
    ]);
    $reference = app(OwnerLocator::class)->reference($owner);
    sweep();
    expect(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(10);

    $this->travel(2)->hours();
    [$exit, $report] = sweep();

    expect($exit)->toBe(0)->and($report['requested'])->toBe(1)
        ->and(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(0);
});

it('ignores a repeated notification and a late stale update without moving applied state backwards', function () {
    $owner = $this->organization();
    oracle([
        StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()]),
        StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()]),
    ]);
    $reference = app(OwnerLocator::class)->reference($owner);
    sweep();
    $applied = app(NativeStateStore::class)->state($reference);

    // A duplicate delivery of the same event id must not queue a second refresh.
    $store = app(NativeStateStore::class);
    $at = Date::now()->toDateTimeImmutable();
    expect($store->request($reference, $at, 'evt_1'))->toBeTrue()
        ->and($store->request($reference, $at, 'evt_1'))->toBeFalse();

    // The late redelivery re-reads current Stripe state, so the same facts reapply unchanged.
    $store->complete($store->claim($reference, $at),
        BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'), 'v1', $at, $at);

    expect(app(NativeStateStore::class)->state($reference)['applied_version'])->toBe($applied['applied_version'])
        ->and(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(10);
});

it('resumes a bounded scan across passes and refuses to call an incomplete scan complete', function () {
    foreach (['1', '2', '3'] as $suffix) {
        $this->organization('8c792286-a444-4fa0-a57f-274b2240800'.$suffix, 'cus_'.$suffix);
    }
    oracle(array_merge(...array_fill(0, 3, [StripeFixture::page([]), StripeFixture::page([])])));

    [$exit, $first] = sweep(['--limit' => 2]);
    expect($exit)->toBe(1)->and($first['complete'])->toBeFalse()->and($first['examined'])->toBe(2);

    [$exit, $second] = sweep(['--limit' => 2, '--resume' => $first['run']]);
    expect($exit)->toBe(0)->and($second['complete'])->toBeTrue()->and($second['examined'])->toBe(3)
        ->and($second['run'])->toBe($first['run']);
});

it('counts an unmappable owner without ending the scan or claiming a clean result', function () {
    $this->organization('8c792286-a444-4fa0-a57f-274b224080e1', 'cus_1');
    // Two owners sharing one Stripe customer cannot be mapped to a single billing subject.
    $this->organization('8c792286-a444-4fa0-a57f-274b224080e2', 'cus_1');
    oracle([]);

    [$exit, $report] = sweep();

    expect($exit)->toBe(1)->and($report['complete'])->toBeTrue()->and($report['examined'])->toBe(2)
        ->and($report['failed'])->toBe(2)->and($report['last_error'])->toBe('ambiguous_customer');
});

it('still reports Cashier drift after convergence, because this package never repairs Cashier rows', function () {
    $owner = $this->organization();
    oracle([
        StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()]),
        StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()]),
    ]);
    sweep();
    $reference = app(OwnerLocator::class)->reference($owner);
    expect(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(10)
        ->and($owner->subscriptions()->count())->toBe(0);

    $exit = Artisan::call('entitlements:reconcile', ['--owner-type' => 'organization', '--owner' => $owner->getKey(), '--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($report['differences'][0])->toMatchArray(['subscription_id' => 'sub_1', 'kind' => 'missing_local']);
});
