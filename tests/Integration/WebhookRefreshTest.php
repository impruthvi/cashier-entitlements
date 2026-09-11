<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Jobs\RefreshOwner;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Tests\Support\BillingIntegrationTestCase;

pest()->extend(BillingIntegrationTestCase::class);

it('records only verified Cashier webhook completions and deduplicates event requests', function () {
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_tables.php.stub')->up();
    config(['cashier-entitlements.enabled' => true, 'cashier-entitlements.freshness' => ['max_stale_age' => 60],
        'cashier.webhook.secret' => 'whsec_fixture_only']);
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', []));
    $owner = $this->organization();
    Bus::fake();
    $body = json_encode(['id' => 'evt_1', 'type' => 'customer.subscription.deleted', 'livemode' => false,
        'data' => ['object' => ['id' => 'sub_1', 'customer' => 'cus_1']]], JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_fixture_only');
    $this->call('POST', 'stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => 'invalid'], $body)->assertForbidden();
    Bus::assertNothingDispatched();
    foreach ([1, 2] as $delivery) {
        $this->call('POST', 'stripe/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $body)->assertSuccessful();
    }
    Bus::assertDispatchedTimes(RefreshOwner::class, 1);
    $reference = app(OwnerLocator::class)->reference($owner);
    expect(app(NativeStateStore::class)->state($reference)['requested_sequence'])->toBe(1);
});
