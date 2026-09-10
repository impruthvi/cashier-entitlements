<?php

declare(strict_types=1);

use Impruthvi\CashierEntitlements\Billing\DecisionStatus;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapper;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Billing\SubscriptionItem;
use Impruthvi\CashierEntitlements\Tests\Support\BillingFixtures as F;

it('maps an explicit base price to typed allowances without billing dependencies', function () {
    $catalog = new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10, 'ai' => true, 'storage' => null])]);
    $result = (new PriceMapper)->map(F::owner(), [F::subscription()], $catalog, F::at(), complete: true);

    expect($result->status)->toBe(DecisionStatus::Allowed)
        ->and($result->planKey)->toBe('pro')
        ->and($result->allowances)->toBe(['ai' => true, 'projects' => 10, 'storage' => null]);
});

it('never manufactures access from an incomplete observation', function () {
    $result = (new PriceMapper)->map(F::owner(), [], new PriceCatalog('v1', [], ['ai' => true]), F::at(), complete: false);
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe('incomplete_snapshot');
});

it('uses only the eligible subscription and ignores ended history and other subscription types', function () {
    $catalog = new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]);
    $history = F::subscription(['id' => 'sub_old', 'status' => 'canceled', 'items' => [new SubscriptionItem('si_old', 'removed_price', 1)]]);
    $other = F::subscription(['id' => 'sub_other', 'type' => 'other', 'status' => 'new_status']);
    $result = (new PriceMapper)->map(F::owner(), [$history, $other, F::subscription()], $catalog, F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Allowed)->and($result->allowances)->toBe(['projects' => 10]);
});

it('denies an ended subscription even when its old price is no longer mapped', function () {
    $result = (new PriceMapper)->map(F::owner(), [F::subscription(['status' => 'canceled'])], new PriceCatalog('v1', []), F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Denied)->and($result->allowances)->toBe([]);
});

it('does not select one of multiple eligible bases arbitrarily', function () {
    $catalog = new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]);
    $other = F::subscription(['id' => 'sub_2', 'items' => [new SubscriptionItem('si_2', 'price_base', 1)]]);
    $result = (new PriceMapper)->map(F::owner(), [F::subscription(), $other], $catalog, F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe('conflicting_bases');
});

it('distinguishes an unknown eligible price from denied access', function () {
    $result = (new PriceMapper)->map(F::owner(), [F::subscription()], new PriceCatalog('v1', []), F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe('unknown_price');
});

it('supports a configured free allowance only with a complete observation', function () {
    $result = (new PriceMapper)->map(F::owner(), [], new PriceCatalog('v1', [], ['projects' => 0, 'ai' => false]), F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Allowed)->and($result->reason)->toBe('free_plan')
        ->and($result->allowances)->toBe(['ai' => false, 'projects' => 0]);
});

it('rejects mixed owner or billing contexts rather than leaking allowances', function (OwnerReference $other) {
    $result = (new PriceMapper)->map(F::owner(), [F::subscription(['owner' => $other])],
        new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['ai' => true])]), F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe('owner_mismatch');
})->with([
    new OwnerReference('organization', 43), new OwnerReference('account', 42),
    new OwnerReference('organization', '042'),
    new OwnerReference('organization', 42, connection: 'tenant'),
    new OwnerReference('organization', 42, providerContext: 'acct_connected'),
    new OwnerReference('organization', 42, liveMode: true),
]);

it('requires the catalog to match the provider account and live mode', function (PriceCatalog $catalog) {
    $result = (new PriceMapper)->map(F::owner(), [], $catalog, F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe('catalog_context_mismatch');
})->with([
    new PriceCatalog('v1', [], providerContext: 'acct_other'),
    new PriceCatalog('v1', [], liveMode: true),
]);

it('accepts integer string and UUID owner keys without model lookups', function (int|string $key) {
    $owner = new OwnerReference('organization', $key, 'tenant', 'acct_connected', true);
    $facts = F::subscription(['owner' => new OwnerReference('organization', (string) $key, 'tenant', 'acct_connected', true)]);
    $catalog = new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['ai' => true])], providerContext: 'acct_connected', liveMode: true);
    expect((new PriceMapper)->map($owner, [$facts], $catalog, F::at(), true)->status)->toBe(DecisionStatus::Allowed);
})->with([42, '42', '7db3b45e-a1c5-4b8d-9ac7-004719117e16']);

it('rejects duplicate subscription identities', function () {
    $result = (new PriceMapper)->map(F::owner(), [F::subscription(), F::subscription()], new PriceCatalog('v1', [
        'price_base' => new PriceMapping('pro', ['ai' => true]),
    ]), F::at(), true);
    expect($result->reason)->toBe('duplicate_subscription')->and($result->status)->toBe(DecisionStatus::Invalid);
});

it('combines a base with per-unit add-ons and preserves boolean zero and unlimited values', function () {
    $catalog = new PriceCatalog('v1', [
        'price_base' => new PriceMapping('pro', ['ai' => false, 'projects' => 10, 'storage' => null, 'exports' => 0]),
        'price_addon' => new PriceMapping('extra', ['ai' => true, 'projects' => 5, 'storage' => 20], isBase: false, perUnit: true),
    ]);
    $facts = F::subscription(['items' => [new SubscriptionItem('si_addon', 'price_addon', 3), new SubscriptionItem('si_base', 'price_base', 1)]]);
    $result = (new PriceMapper)->map(F::owner(), [$facts], $catalog, F::at(), true);
    expect($result->allowances)->toBe(['ai' => true, 'exports' => 0, 'projects' => 25, 'storage' => null]);
});

it('requires a base when only add-ons are eligible rather than falling back to free', function () {
    $catalog = new PriceCatalog('v1', ['price_base' => new PriceMapping('extra', ['projects' => 5], isBase: false)], ['ai' => true]);
    $result = (new PriceMapper)->map(F::owner(), [F::subscription()], $catalog, F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe('missing_base');
});

it('rejects malformed eligible items instead of guessing prices quantities or periods', function (array $items, string $reason) {
    $catalog = new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]);
    $result = (new PriceMapper)->map(F::owner(), [F::subscription(['items' => $items])], $catalog, F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe($reason);
})->with([
    [[], 'missing_items'],
    [[null], 'invalid_item'],
    [[new SubscriptionItem('', 'price_base', 1)], 'invalid_item'],
    [[new SubscriptionItem('si_1', '', 1)], 'invalid_item'],
    [[new SubscriptionItem('si_1', 'price_base', null)], 'invalid_quantity'],
    [[new SubscriptionItem('si_1', 'price_base', 0)], 'invalid_quantity'],
    [[new SubscriptionItem('si_1', 'price_base', -1)], 'invalid_quantity'],
    [[new SubscriptionItem('si_1', 'price_base', 2)], 'quantity_requires_per_unit'],
    [[new SubscriptionItem('si_1', 'price_base', 1), new SubscriptionItem('si_1', 'price_base', 1)], 'duplicate_item'],
    [[new SubscriptionItem('si_1', 'price_base', 1, F::at())], 'invalid_item_period'],
    [[new SubscriptionItem('si_1', 'price_base', 1, periodEnd: F::at())], 'invalid_item_period'],
    [[new SubscriptionItem('si_1', 'price_base', 1, F::at(), F::at())], 'invalid_item_period'],
    [[new SubscriptionItem('si_1', 'price_base', 1, F::at(), F::at()->modify('-1 day'))], 'invalid_item_period'],
]);

it('validates the catalog and keeps feature types consistent', function (PriceCatalog $catalog, string $reason) {
    $result = (new PriceMapper)->map(F::owner(), [F::subscription()], $catalog, F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe($reason);
})->with([
    [new PriceCatalog('', []), 'invalid_catalog'],
    [new PriceCatalog('v1', ['price_base' => null]), 'invalid_catalog'],
    [new PriceCatalog('v1', ['' => new PriceMapping('pro', [])]), 'invalid_catalog'],
    [new PriceCatalog('v1', ['price_base' => new PriceMapping('', [])]), 'invalid_catalog'],
    [new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['' => 10])]), 'invalid_allowance'],
    [new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => -1])]), 'invalid_allowance'],
    [new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 1.5])]), 'invalid_allowance'],
    [new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => '10'])]), 'invalid_allowance'],
    [new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => []])]), 'invalid_allowance'],
    [new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])], ['projects' => true]), 'feature_type_mismatch'],
    [new PriceCatalog('v1', [
        'price_base' => new PriceMapping('pro', ['ai' => true]),
        'price_other' => new PriceMapping('other', ['ai' => null]),
    ]), 'feature_type_mismatch'],
]);

it('rejects integer overflow without returning partial allowances', function (int $base, int $addon, int $quantity) {
    $catalog = new PriceCatalog('v1', [
        'price_base' => new PriceMapping('pro', ['projects' => $base]),
        'price_addon' => new PriceMapping('extra', ['projects' => $addon], false, true),
    ]);
    $facts = F::subscription(['items' => [new SubscriptionItem('si_1', 'price_base', 1), new SubscriptionItem('si_2', 'price_addon', $quantity)]]);
    $result = (new PriceMapper)->map(F::owner(), [$facts], $catalog, F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe('allowance_overflow')
        ->and($result->allowances)->toBe([]);
})->with([[PHP_INT_MAX, 1, 1], [0, PHP_INT_MAX, 2]]);

it('retains the largest valid integer exactly', function () {
    $catalog = new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => PHP_INT_MAX], perUnit: true)]);
    $result = (new PriceMapper)->map(F::owner(), [F::subscription()], $catalog, F::at(), true);
    expect($result->allowances['projects'])->toBe(PHP_INT_MAX);
});

it('never merges active subscriptions belonging to different customers', function () {
    $catalog = new PriceCatalog('v1', [
        'price_base' => new PriceMapping('pro', ['projects' => 10]),
        'price_addon' => new PriceMapping('extra', ['projects' => 5], false),
    ]);
    $addon = F::subscription(['id' => 'sub_2', 'customerId' => 'cus_other', 'items' => [new SubscriptionItem('si_2', 'price_addon', 1)]]);
    $result = (new PriceMapper)->map(F::owner(), [F::subscription(), $addon], $catalog, F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe('conflicting_customers');
});

it('is deterministic under reordered subscriptions and items with unlimited dominating finite arithmetic', function () {
    $catalog = new PriceCatalog('v1', [
        'price_base' => new PriceMapping('pro', ['projects' => null, 'ai' => false]),
        'price_addon' => new PriceMapping('extra', ['projects' => PHP_INT_MAX, 'ai' => true], false, true),
    ]);
    $base = F::subscription();
    $addon = F::subscription(['id' => 'sub_2', 'scheduledEndsAt' => F::at()->modify('+1 day'), 'items' => [
        new SubscriptionItem('si_3', 'price_addon', 2), new SubscriptionItem('si_2', 'price_addon', 3),
    ]]);
    $mapper = new PriceMapper;
    $result = $mapper->map(F::owner(), [$base, $addon], $catalog, F::at(), true);
    $reordered = F::subscription(['id' => 'sub_2', 'scheduledEndsAt' => $addon->scheduledEndsAt, 'items' => array_reverse($addon->items)]);
    expect($result)->toEqual($mapper->map(F::owner(), [$reordered, $base], $catalog, F::at(), true))
        ->and($result->allowances)->toBe(['ai' => true, 'projects' => null])
        ->and($result->validUntil)->toEqual(F::at()->modify('+1 day'));
});

it('re-evaluates time without mutation or cached decisions', function () {
    $facts = F::subscription(['status' => 'trialing', 'trialEndsAt' => F::at()->modify('+1 day')]);
    $catalog = new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['ai' => true])]);
    $mapper = new PriceMapper;
    expect($mapper->map(F::owner(), [$facts], $catalog, F::at(), true)->status)->toBe(DecisionStatus::Allowed)
        ->and($mapper->map(F::owner(), [$facts], $catalog, F::at()->modify('+1 day'), true)->status)->toBe(DecisionStatus::Denied)
        ->and($mapper->map(F::owner(), [$facts], $catalog, F::at(), true)->status)->toBe(DecisionStatus::Allowed);
});

it('does not turn a normal renewal period or historical trial end into a cancellation', function () {
    $facts = F::subscription(['trialEndsAt' => F::at()->modify('-1 month'), 'items' => [
        new SubscriptionItem('si_1', 'price_base', 1, F::at()->modify('-1 month'), F::at()),
    ]]);
    $result = (new PriceMapper)->map(F::owner(), [$facts], new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['ai' => true])]), F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Allowed)->and($result->validUntil)->toBeNull();
});

it('rejects invalid snapshot structure with named results', function (array $subscriptions, string $reason) {
    $result = (new PriceMapper)->map(F::owner(), $subscriptions, new PriceCatalog('v1', []), F::at(), true);
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe($reason);
})->with([
    [[null], 'invalid_subscription'],
    [[['status' => 'active']], 'invalid_subscription'],
    [[F::subscription(['status' => 'future_status'])], 'unknown_status'],
]);

it('validates owner and subscription type even when no subscriptions exist', function () {
    $mapper = new PriceMapper;
    $catalog = new PriceCatalog('v1', []);
    expect($mapper->map(new OwnerReference('', ''), [], $catalog, F::at(), true)->reason)->toBe('invalid_owner')
        ->and($mapper->map(F::owner(), [], $catalog, F::at(), true, '')->reason)->toBe('invalid_subscription_type');
});

it('scales an explicitly per-unit base and does not multiply boolean access', function () {
    $catalog = new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['ai' => true, 'projects' => 5], perUnit: true)]);
    $facts = F::subscription(['items' => [new SubscriptionItem('si_1', 'price_base', 3)]]);
    expect((new PriceMapper)->map(F::owner(), [$facts], $catalog, F::at(), true)->allowances)->toBe(['ai' => true, 'projects' => 15]);
});

it('can select a named subscription domain without using another domains base', function () {
    $catalog = new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['ai' => true])]);
    $other = F::subscription(['id' => 'sub_2', 'type' => 'secondary']);
    expect((new PriceMapper)->map(F::owner(), [F::subscription(), $other], $catalog, F::at(), true, 'secondary')->status)
        ->toBe(DecisionStatus::Allowed);
});

it('applies configured free allowances after paid access ends but not when facts are invalid', function () {
    $catalog = new PriceCatalog('v1', [], ['ai' => false]);
    $mapper = new PriceMapper;
    expect($mapper->map(F::owner(), [F::subscription(['status' => 'canceled'])], $catalog, F::at(), true)->reason)->toBe('free_plan')
        ->and($mapper->map(F::owner(), [F::subscription(['status' => 'unknown'])], $catalog, F::at(), true)->status)->toBe(DecisionStatus::Invalid);
});
