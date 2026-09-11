<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Impruthvi\CashierEntitlements\Reconciliation\CashierLocalProjector;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Tests\Support\BillingIntegrationTestCase;
use Impruthvi\CashierEntitlements\Tests\Support\CustomSubscription;
use Impruthvi\CashierEntitlements\Tests\Support\CustomSubscriptionItem;
use Laravel\Cashier\Cashier;

pest()->extend(BillingIntegrationTestCase::class);

it('reads fresh scoped relationships for UUID owners without using parent price or cached relations', function () {
    $owner = $this->organization();
    $owner->load('subscriptions');
    $this->subscription($owner, status: 'canceled');
    $this->subscription($this->organization('other', 'cus_other'), 'sub_other');
    $snapshot = (new CashierLocalProjector)->read($owner, new DateTimeImmutable('2026-09-11T12:00:00Z'));

    expect($snapshot->owner->key)->toBe('8c792286-a444-4fa0-a57f-274b224080e0')
        ->and($snapshot->owner->type)->toBe('organization')
        ->and($snapshot->owner->connection)->toBe('testing')
        ->and($snapshot->subscriptions)->toHaveCount(1)
        ->and($snapshot->subscriptions[0]->status)->toBe('canceled')
        ->and($snapshot->subscriptions[0]->items[0]->priceId)->toBe('price_base')
        ->and($snapshot->subscriptions[0]->items[0]->periodStart)->toBeNull();
});

it('honors configured subscription and item models with custom tables', function () {
    $subscription = Cashier::$subscriptionModel;
    $item = Cashier::$subscriptionItemModel;
    try {
        Schema::rename('subscriptions', 'custom_subscriptions');
        Schema::rename('subscription_items', 'custom_subscription_items');
        Cashier::useSubscriptionModel(CustomSubscription::class);
        Cashier::useSubscriptionItemModel(CustomSubscriptionItem::class);
        $owner = $this->organization();
        $this->subscription($owner);
        $snapshot = (new CashierLocalProjector)->read($owner, new DateTimeImmutable);
        expect($snapshot->subscriptions)->toHaveCount(1)
            ->and($snapshot->subscriptions[0]->items[0]->id)->toBe('si_sub_1');
    } finally {
        Cashier::useSubscriptionModel($subscription);
        Cashier::useSubscriptionItemModel($item);
    }
});

it('rejects ambiguous customer ownership', function () {
    $owner = $this->organization();
    $this->organization('duplicate');
    expect(fn () => (new CashierLocalProjector)->read($owner, new DateTimeImmutable))
        ->toThrow(ReadFailure::class, 'ambiguous_customer');
});

it('keeps a confirmed free owner distinct from broken customerless billing rows', function () {
    $owner = $this->organization(customer: null);
    expect((new CashierLocalProjector)->read($owner, new DateTimeImmutable)->subscriptions)->toBe([]);
    $this->subscription($owner);
    expect(fn () => (new CashierLocalProjector)->read($owner, new DateTimeImmutable))
        ->toThrow(ReadFailure::class, 'missing_customer');
});
