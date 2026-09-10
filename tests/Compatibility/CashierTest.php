<?php

declare(strict_types=1);

use Impruthvi\CashierEntitlements\Tests\Support\CashierTestCase;
use Impruthvi\CashierEntitlements\Tests\Support\Organization;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;

pest()->extend(CashierTestCase::class);

it('supports a configured organization owner relationship without assuming user_id', function () {
    $previous = Cashier::$customerModel;
    try {
        Cashier::useCustomerModel(Organization::class);
        $subscription = new Subscription;
        expect($subscription->owner()->getForeignKeyName())->toBe('organization_id')
            ->and($subscription->owner()->getRelated())->toBeInstanceOf(Organization::class)
            ->and(config('cashier-entitlements.enabled'))->toBeFalse();
    } finally {
        Cashier::useCustomerModel($previous);
    }
});

it('characterizes why billing facts must not use active as the entitlement decision', function () {
    $subscription = new Subscription(['stripe_status' => 'canceled', 'ends_at' => null]);

    expect($subscription->stripe_status)->toBe('canceled')
        ->and($subscription->active())->toBeTrue();
});
