<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Impruthvi\CashierEntitlements\Tests\Support\BillingIntegrationTestCase;
use Impruthvi\CashierEntitlements\Tests\Support\StripeFixture;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;

pest()->extend(BillingIntegrationTestCase::class);

it('runs a customerless free-owner diagnostic through default bindings without credentials or HTTP', function () {
    $owner = $this->organization(customer: null);
    $fixture = new StripeFixture([]);
    $fixture->client();
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', [], ['projects' => 1]));
    expect(Artisan::call('entitlements:reconcile', ['--owner-type' => 'organization', '--owner' => $owner->getKey(), '--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['proposed_decision']['reason'])->toBe('free_plan')
        ->and($fixture->requests)->toBe([]);
});

it('diagnoses an omitted creation event by default without writing local billing or access', function () {
    $owner = $this->organization();
    $fixture = new StripeFixture([StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()])]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]));
    DB::enableQueryLog();
    $exit = Artisan::call('entitlements:reconcile', ['--owner-type' => 'organization', '--owner' => $owner->getKey(), '--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($report['schema_version'])->toBe(1)
        ->and($report['mode'])->toBe('dry-run')
        ->and($report['complete'])->toBeTrue()
        ->and($report['proposed_decision']['allowances'])->toBe(['projects' => 10])
        ->and($report['differences'][0])->toMatchArray(['subscription_id' => 'sub_1', 'kind' => 'missing_local']);
    foreach (DB::getQueryLog() as $query) {
        expect(strtolower(ltrim($query['query'])))->toStartWith('select');
    }
    $fixture->assertReadOnly();
});

it('compares stale updates and confirmed absence without repairing Cashier', function (string $scenario, array $responses, string $kind, int $exit) {
    $owner = $this->organization();
    $this->subscription($owner);
    $fixture = new StripeFixture($responses);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]));
    expect(Artisan::call('entitlements:reconcile', ['--owner-type' => 'organization', '--owner' => $owner->getKey(), '--json' => true]))->toBe($exit);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['differences'][0]['kind'])->toBe($kind)
        ->and($report['proposed_decision']['status'])->toBe('denied')
        ->and($owner->subscriptions()->first()->stripe_status)->toBe('active');
    $fixture->assertReadOnly();
})->with([
    'missed cancellation' => ['cancellation', [StripeFixture::page([StripeFixture::subscription(status: 'canceled')]),
        StripeFixture::page([StripeFixture::item('si_sub_1')])], 'changed', 1],
    'confirmed missing remote' => ['absent', [StripeFixture::page([]),
        InvalidRequestException::factory('private body', 404, null, null, null, 'resource_missing')], 'missing_provider_confirmed', 1],
]);

it('audits a bounded test-clock scope and reports remote customers with no local owner', function () {
    $owner = $this->organization();
    $remote = array_replace(StripeFixture::subscription(), ['test_clock' => 'clock_1']);
    $unknown = array_replace($remote, ['id' => 'sub_unknown', 'customer' => 'cus_unknown']);
    $fixture = new StripeFixture([
        StripeFixture::page([$remote, $unknown]),
        ['object' => 'customer', 'id' => 'cus_1', 'livemode' => false, 'test_clock' => 'clock_1'],
        StripeFixture::page([$remote]), StripeFixture::page([StripeFixture::item()]),
    ]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]));
    expect(Artisan::call('entitlements:reconcile', ['--all' => true, '--owner-type' => 'organization', '--test-clock' => 'clock_1', '--json' => true]))->toBe(1);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['complete'])->toBeTrue()->and($report['unknown_customers'])->toBe(['cus_unknown'])
        ->and($report['owners'])->toHaveCount(1)
        ->and($report['owners'][0]['owner']['key'])->toBe($owner->getKey());
    $fixture->assertReadOnly();
});

it('reports expected incomplete-expired absence without false drift', function () {
    $owner = $this->organization();
    $fixture = new StripeFixture([StripeFixture::page([StripeFixture::subscription(status: 'incomplete_expired')]), StripeFixture::page([])]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', []));
    expect(Artisan::call('entitlements:reconcile', ['--owner-type' => 'organization', '--owner' => $owner->getKey(), '--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['differences'][0]['kind'])->toBe('expected_missing_local');
});

it('reports a clean comparison independent of item ordering and unavailable local periods', function () {
    $owner = $this->organization();
    $this->subscription($owner);
    $owner->subscriptions()->first()->items()->create(['stripe_id' => 'si_extra', 'stripe_product' => 'prod_extra', 'stripe_price' => 'price_extra', 'quantity' => 1]);
    $fixture = new StripeFixture([StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item('si_extra', 'price_extra'), StripeFixture::item('si_sub_1')])]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10]),
        'price_extra' => new PriceMapping('extra', ['projects' => 5], isBase: false)]));
    expect(Artisan::call('entitlements:reconcile', ['--owner-type' => 'organization', '--owner' => $owner->getKey(), '--json' => true]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['differences'])->toBe([])->and($report['errors'])->toBe([]);
});

it('does not turn failed reads into a cancellation or expose exception bodies', function () {
    $owner = $this->organization();
    $fixture = new StripeFixture([new ApiConnectionException('sk_test_sensitive private@example.com')]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', [], ['projects' => 1]));
    expect(Artisan::call('entitlements:reconcile', ['--owner-type' => 'organization', '--owner' => $owner->getKey(), '--json' => true]))->toBe(2);
    $output = Artisan::output();
    $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    expect($report['complete'])->toBeFalse()->and($report['proposed_decision'])->toBeNull()
        ->and($report['differences'])->toBe([])->and($report['errors'])->toBe(['provider_unavailable'])
        ->and($output)->not->toContain('sk_test_sensitive', 'private@example.com');
});

it('fails a missing price mapping without pretending the snapshot failed', function () {
    $owner = $this->organization();
    $fixture = new StripeFixture([StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()])]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', []));
    expect(Artisan::call('entitlements:reconcile', ['--owner-type' => 'organization', '--owner' => $owner->getKey(), '--json' => true]))->toBe(2);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['complete'])->toBeTrue()->and($report['proposed_decision']['status'])->toBe('invalid')
        ->and($report['errors'])->toBe(['provider:unknown_price']);
});

it('refuses apply, unknown owners and unregistered class names before provider requests', function (array $options, string $error) {
    $fixture = new StripeFixture([]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    expect(Artisan::call('entitlements:reconcile', [...$options, '--json' => true]))->toBe(2);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['errors'])->toBe([$error]);
    expect($fixture->requests)->toBe([]);
})->with([
    'apply' => [['--apply' => true], 'application_disabled'],
    'no owner' => [[], 'explicit_owner_required'],
    'unknown owner' => [['--owner-type' => 'organization', '--owner' => 'missing'], 'unknown_owner'],
    'class injection' => [['--owner-type' => stdClass::class, '--owner' => '1'], 'unregistered_owner_type'],
]);

it('discards a partial account audit and never reports unknown-owner absence as complete', function () {
    $fixture = new StripeFixture([
        StripeFixture::page([array_replace(StripeFixture::subscription(), ['test_clock' => 'clock_1'])], true),
        new RateLimitException('private provider body'),
    ]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', []));
    expect(Artisan::call('entitlements:reconcile', ['--all' => true, '--owner-type' => 'organization', '--test-clock' => 'clock_1', '--json' => true]))->toBe(2);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['complete'])->toBeFalse()->and($report['proposed_decision'])->toBeNull()
        ->and($report['errors'])->toBe(['provider_rate_limited'])->and($report)->not->toHaveKey('owners');
});
