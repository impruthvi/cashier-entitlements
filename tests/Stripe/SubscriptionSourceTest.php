<?php

declare(strict_types=1);

use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Impruthvi\CashierEntitlements\Tests\Support\StripeFixture;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\StripeClient;

it('reads every subscription and nested item page in the explicit customer context', function () {
    $fixture = new StripeFixture([
        StripeFixture::page([StripeFixture::subscription()], true),
        StripeFixture::page([StripeFixture::item()], true),
        StripeFixture::page([StripeFixture::item('si_extra', 'price_extra')]),
        StripeFixture::page([StripeFixture::subscription('sub_old', 'canceled')]),
        StripeFixture::page([StripeFixture::item('si_old', subscription: 'sub_old')]),
    ]);
    $owner = new OwnerReference('organization', 'org-uuid', providerContext: 'acct_test');
    $snapshot = (new StripeSubscriptionSource($fixture->client(), providerContext: 'acct_test'))
        ->read($owner, 'cus_1', new DateTimeImmutable('2026-09-11T12:00:00Z'));

    expect($snapshot->subscriptions)->toHaveCount(2)
        ->and($snapshot->subscriptions[0]->items)->toHaveCount(2)
        ->and($snapshot->subscriptions[0]->items[1]->priceId)->toBe('price_extra')
        ->and($snapshot->subscriptions[1]->status)->toBe('canceled')
        ->and($snapshot->subscriptions[0]->scheduledEndsAt)->toBeNull();
    $fixture->assertReadOnly();
    expect($fixture->requests[0]['params'])->toMatchArray(['customer' => 'cus_1', 'status' => 'all'])
        ->and($fixture->requests[2]['params']['starting_after'])->toBe('si_base')
        ->and($fixture->requests[3]['params']['starting_after'])->toBe('sub_1');
    foreach ($fixture->requests as $request) {
        expect($request['maxNetworkRetries'])->toBe(2)
            ->and($request['headers'])->toContain('Stripe-Account: acct_test', 'Stripe-Version: 2025-06-30.basil');
    }
});

it('rejects untrusted subscription identity and malformed facts', function (array $changes) {
    $fixture = new StripeFixture([
        StripeFixture::page([array_replace(StripeFixture::subscription(), $changes)]),
        StripeFixture::page([StripeFixture::item()]),
    ]);
    expect(fn () => (new StripeSubscriptionSource($fixture->client()))->read(
        new OwnerReference('organization', 42), 'cus_1', new DateTimeImmutable,
    ))->toThrow(ReadFailure::class);
})->with([
    'wrong customer' => [['customer' => 'cus_other']],
    'wrong mode' => [['livemode' => true]],
    'unknown status' => [['status' => 'new_status']],
    'missing status' => [['status' => null]],
    'malformed type' => [['metadata' => ['type' => 42]]],
    'malformed metadata' => [['metadata' => 'bad']],
    'trial without boundary' => [['status' => 'trialing', 'trial_end' => null]],
    'cancel without boundary' => [['cancel_at_period_end' => true]],
]);

it('rejects a wrong-mode client before any requests', function () {
    expect(fn () => new StripeSubscriptionSource(new StripeClient(['api_key' => 'sk_live_fixture', 'stripe_account' => null])))
        ->toThrow(ReadFailure::class, 'provider_mode_mismatch');
});

it('uses only the explicit source scope instead of inheriting hidden client account defaults', function () {
    $fixture = new StripeFixture([StripeFixture::page([])]);
    $fixture->client();
    $client = new StripeClient(['api_key' => 'sk_test_fixture', 'stripe_account' => 'acct_other', 'stripe_context' => 'acct_other']);
    $snapshot = (new StripeSubscriptionSource($client))->read(new OwnerReference('organization', 42), 'cus_1', new DateTimeImmutable);
    expect($snapshot->subscriptions)->toBe([]);
    foreach ($fixture->requests[0]['headers'] as $header) {
        expect($header)->not->toContain('acct_other');
    }
});

it('caps the total normalized snapshot rather than only each page collection', function () {
    $fixture = new StripeFixture([StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()])]);
    expect(fn () => (new StripeSubscriptionSource($fixture->client(), maxRecords: 1))->read(
        new OwnerReference('organization', 42), 'cus_1', new DateTimeImmutable,
    ))->toThrow(ReadFailure::class, 'provider_record_limit');
});

it('retrieves local IDs missing from a complete list before reporting remote absence', function () {
    $missing = InvalidRequestException::factory('private provider body', 404, null, null, null, 'resource_missing');
    $fixture = new StripeFixture([StripeFixture::page([]), StripeFixture::subscription(),
        StripeFixture::page([StripeFixture::item()]), $missing]);
    $snapshot = (new StripeSubscriptionSource($fixture->client()))->read(
        new OwnerReference('organization', 42), 'cus_1', new DateTimeImmutable, ['sub_1', 'sub_gone'],
    );
    expect($snapshot->subscriptions)->toHaveCount(1)
        ->and($snapshot->confirmedAbsentIds)->toBe(['sub_gone']);
    $fixture->assertReadOnly();
});

it('uses an explicit cancellation date rather than cancellation request time or renewal time', function () {
    $fixture = new StripeFixture([StripeFixture::page([array_replace(StripeFixture::subscription(), [
        'cancel_at' => 1791676800, 'cancel_at_period_end' => true, 'canceled_at' => 1789084800,
    ])]), StripeFixture::page([StripeFixture::item()])]);
    $snapshot = (new StripeSubscriptionSource($fixture->client()))->read(new OwnerReference('organization', 42), 'cus_1', new DateTimeImmutable);
    expect($snapshot->subscriptions[0]->scheduledEndsAt->getTimestamp())->toBe(1791676800);
});

it('bounds incomplete pagination and refuses customerless local subscriptions', function () {
    $fixture = new StripeFixture([StripeFixture::page([StripeFixture::subscription()], true), StripeFixture::page([StripeFixture::item()])]);
    $source = new StripeSubscriptionSource($fixture->client(), maxPages: 1);
    expect(fn () => $source->read(new OwnerReference('organization', 42), 'cus_1', new DateTimeImmutable))
        ->toThrow(ReadFailure::class, 'provider_page_limit')
        ->and(fn () => $source->read(new OwnerReference('organization', 42), null, new DateTimeImmutable, ['sub_1']))
        ->toThrow(ReadFailure::class, 'missing_customer');
});

it('does not return partial pages or raw provider errors', function ($response, string $reason) {
    $fixture = new StripeFixture([
        StripeFixture::page([StripeFixture::subscription()], true),
        StripeFixture::page([StripeFixture::item()]),
        $response,
    ]);
    expect(fn () => (new StripeSubscriptionSource($fixture->client()))->read(
        new OwnerReference('organization', 42), 'cus_1', new DateTimeImmutable,
    ))->toThrow(ReadFailure::class, $reason);
})->with([
    'timeout' => [new ApiConnectionException('secret provider body'), 'provider_unavailable'],
    'rate limit' => [new RateLimitException('secret provider body'), 'provider_rate_limited'],
    'authentication' => [new AuthenticationException('secret provider body'), 'provider_unauthorized'],
    'permission' => [new PermissionException('secret provider body'), 'provider_unauthorized'],
    'invalid SDK response' => [new UnexpectedValueException('secret provider body'), 'malformed_provider_data'],
    'malformed page' => [['object' => 'list', 'has_more' => false], 'malformed_provider_page'],
    'empty continuing page' => [StripeFixture::page([], true), 'malformed_provider_page'],
    'duplicate page' => [StripeFixture::page([StripeFixture::subscription()]), 'duplicate_provider_id'],
]);

it('streams all discovery pages for ten thousand synthetic subscriptions', function () {
    $pages = [];
    for ($page = 0; $page < 100; $page++) {
        $records = [];
        for ($row = 0; $row < 100; $row++) {
            $id = $page * 100 + $row;
            $records[] = ['object' => 'subscription', 'id' => 'sub_'.$id, 'customer' => 'cus_'.$id,
                'livemode' => false, 'test_clock' => 'clock_1'];
        }
        $pages[] = StripeFixture::page($records, $page < 99);
    }
    $fixture = new StripeFixture($pages);
    $customers = (new StripeSubscriptionSource($fixture->client()))->discoverCustomers('clock_1');
    expect($customers)->toHaveCount(10000)->and($customers[9999])->toBe('cus_9999');
    $fixture->assertReadOnly();
});

it('rejects invalid nested item scope and periods', function (array $changes) {
    $fixture = new StripeFixture([StripeFixture::page([StripeFixture::subscription()]),
        StripeFixture::page([array_replace(StripeFixture::item(), $changes)])]);
    expect(fn () => (new StripeSubscriptionSource($fixture->client()))->read(
        new OwnerReference('organization', 42), 'cus_1', new DateTimeImmutable,
    ))->toThrow(ReadFailure::class);
})->with([
    'wrong subscription' => [['subscription' => 'sub_other']],
    'quantity string' => [['quantity' => '1']],
    'inverted period' => [['current_period_end' => 1]],
    'missing period start' => [['current_period_start' => null]],
    'wrong price mode' => [['price' => ['id' => 'price_base', 'livemode' => true]]],
]);
