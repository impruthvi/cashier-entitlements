<?php

declare(strict_types=1);

use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Impruthvi\CashierEntitlements\Tests\TestCase;
use Stripe\StripeClient;

pest()->extend(TestCase::class);

/**
 * Opt-in contract check against a real Stripe sandbox.
 *
 * It answers one question the recorded fixtures cannot: does the pinned API version still
 * return the shape this package reads? It is read-only, creates no Stripe resource, and is
 * excluded from the default suite so credentials never enter normal pull-request CI.
 */
beforeEach(function () {
    $key = getenv('CASHIER_ENTITLEMENTS_SANDBOX_KEY');
    if ($key === false || trim($key) === '') {
        $this->markTestSkipped('Set CASHIER_ENTITLEMENTS_SANDBOX_KEY to run the live contract suite.');
    }
    if (preg_match('/^(sk|rk)_test_/', $key) !== 1) {
        $this->fail('Refusing to run the contract suite with anything but a Stripe test key.');
    }
    $this->stripe = new StripeClient(['api_key' => $key, 'stripe_version' => StripeSubscriptionSource::API_VERSION]);
});

it('still accepts the pinned API version and returns the list envelope the source pages on', function () {
    $page = $this->stripe->subscriptions->all(['limit' => 1, 'status' => 'all'])->toArray();

    expect($page['object'])->toBe('list')
        ->and($page)->toHaveKeys(['data', 'has_more'])
        ->and($page['data'])->toBeArray();
});

it('returns every subscription field the source requires, or reports that the sandbox is empty', function () {
    $page = $this->stripe->subscriptions->all(['limit' => 1, 'status' => 'all'])->toArray();
    if ($page['data'] === []) {
        $this->markTestSkipped('The sandbox has no subscription to compare against the fixtures.');
    }
    $subscription = $page['data'][0];

    expect($subscription)->toHaveKeys(['object', 'id', 'customer', 'livemode', 'status', 'metadata',
        'trial_end', 'cancel_at', 'cancel_at_period_end'])
        ->and($subscription['object'])->toBe('subscription')
        ->and($subscription['livemode'])->toBeFalse()
        ->and($subscription['status'])->toBeIn(['active', 'trialing', 'past_due', 'incomplete',
            'incomplete_expired', 'canceled', 'unpaid', 'paused']);
});

it('returns every subscription item field the source requires, including its billing period', function () {
    $page = $this->stripe->subscriptions->all(['limit' => 1, 'status' => 'all'])->toArray();
    if ($page['data'] === []) {
        $this->markTestSkipped('The sandbox has no subscription to compare against the fixtures.');
    }
    $items = $this->stripe->subscriptionItems->all(['subscription' => $page['data'][0]['id'], 'limit' => 1])->toArray();
    if ($items['data'] === []) {
        $this->markTestSkipped('The sandbox subscription has no item to compare against the fixtures.');
    }
    $item = $items['data'][0];

    expect($item)->toHaveKeys(['object', 'id', 'subscription', 'price', 'current_period_start', 'current_period_end'])
        ->and($item['object'])->toBe('subscription_item')
        ->and($item['price'])->toHaveKeys(['id', 'object', 'livemode'])
        ->and($item['current_period_end'])->toBeGreaterThan($item['current_period_start']);
});
