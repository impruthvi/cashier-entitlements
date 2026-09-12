<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Impruthvi\CashierEntitlements\Tests\Support\BillingIntegrationTestCase;
use Impruthvi\CashierEntitlements\Tests\Support\StripeFixture;
use Stripe\Exception\RateLimitException;

pest()->extend(BillingIntegrationTestCase::class);

beforeEach(function () {
    foreach (['create_cashier_entitlements_tables', 'create_cashier_entitlements_billing_periods_table',
        'create_cashier_entitlements_audit_runs_table'] as $migration) {
        (require __DIR__.'/../../database/migrations/'.$migration.'.php.stub')->up();
    }
    config(['cashier-entitlements.enabled' => true, 'cashier-entitlements.freshness' => ['max_stale_age' => 3600]]);
    Date::setTestNow('2026-09-12T12:00:00Z');
});

it('answers one feature and a hundred with the same number of queries', function () {
    $allowances = ['ai' => true];
    for ($i = 0; $i < 99; $i++) {
        $allowances['feature_'.$i] = $i;
    }
    ksort($allowances);
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', [], $allowances));
    $owner = new OwnerReference('organization', 1, 'testing');
    $store = app(NativeStateStore::class);
    $at = Date::now()->toDateTimeImmutable();
    $store->request($owner, $at);
    $store->complete($store->claim($owner, $at), BillingDecision::allowed('mapped', allowances: $allowances, planKey: 'pro'), 'v1', $at, $at);

    $count = function (callable $read): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $read();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $resolver = app(LocalResolver::class);

    $one = $count(fn () => $resolver->for($owner)->can('ai'));
    $hundred = $count(fn () => $resolver->snapshot($owner, Date::now()->toDateTimeImmutable())->all());

    expect($one)->toBe(1)->and($hundred)->toBe(1);
});

/**
 * Interleaved exactly as the source reads: one subscription page, then one item page per
 * subscription on it, before the next subscription page is fetched.
 *
 * Each page is a closure, so the fixture costs nothing until requested and the measurement
 * below reflects what the source holds rather than what the test staged.
 *
 * @return list<Closure(): array<string, mixed>>
 */
function providerPages(int $subscriptionPages): array
{
    $pages = [];
    for ($page = 0; $page < $subscriptionPages; $page++) {
        $first = $page * 100;
        $pages[] = fn (): array => StripeFixture::page(array_map(
            fn (int $index): array => StripeFixture::subscription('sub_'.($first + $index)), range(0, 99),
        ), $page < $subscriptionPages - 1);
        foreach (range(0, 99) as $index) {
            $id = 'sub_'.($first + $index);
            $pages[] = fn (): array => StripeFixture::page([StripeFixture::item('si_'.$id, 'price_base', $id)]);
        }
    }

    return $pages;
}

it('reads ten thousand synthetic remote subscriptions within its page and memory bounds', function () {
    // Ten thousand subscriptions plus their items are twenty thousand records, so this
    // scale needs the ceiling raised on purpose rather than reached by accident.
    $source = new StripeSubscriptionSource((new StripeFixture(providerPages(100)))->client(),
        maxPages: 1000, maxRecords: 25000);
    $owner = new OwnerReference('organization', 1, 'testing');
    $before = memory_get_usage();

    $snapshot = $source->read($owner, 'cus_1', Date::now()->toDateTimeImmutable());

    expect($snapshot->subscriptions)->toHaveCount(10000)
        ->and(memory_get_usage() - $before)->toBeLessThan(96 * 1024 * 1024);
});

it('refuses a provider result past its record ceiling rather than reading without end', function () {
    $source = new StripeSubscriptionSource((new StripeFixture(providerPages(2)))->client(), maxRecords: 150);

    expect(fn () => $source->read(new OwnerReference('organization', 1, 'testing'), 'cus_1', Date::now()->toDateTimeImmutable()))
        ->toThrow(ReadFailure::class, 'provider_record_limit');
});

it('refuses to page without end when the provider keeps reporting more', function () {
    $source = new StripeSubscriptionSource((new StripeFixture(providerPages(3)))->client(), maxPages: 2, maxRecords: 25000);

    expect(fn () => $source->read(new OwnerReference('organization', 1, 'testing'), 'cus_1', Date::now()->toDateTimeImmutable()))
        ->toThrow(ReadFailure::class, 'provider_page_limit');
});

it('keeps a rate-limited owner queued and records only a sanitized reason', function () {
    $owner = $this->organization();
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]));
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource(
        (new StripeFixture([new RateLimitException('sk_test_leaked private provider detail')]))->client()));

    // The sweep's job is to request work. A provider failure belongs to the worker, so the
    // scan still completes and the committed request stays the durable outbox.
    $exit = Artisan::call('entitlements:sweep', ['--owner-type' => 'organization', '--json' => true]);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($report)->toMatchArray(['complete' => true, 'examined' => 1, 'requested' => 1, 'failed' => 0]);

    $state = app(NativeStateStore::class)->state(app(OwnerLocator::class)->reference($owner));
    expect($state['requested_sequence'])->toBeGreaterThan($state['completed_sequence'])
        ->and($state['last_error'])->toBe('provider_rate_limited')
        ->and(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain('sk_test_leaked');
});

it('holds a large pending backlog to its requested limit rather than loading every owner', function () {
    $store = app(NativeStateStore::class);
    $at = Date::now()->toDateTimeImmutable();
    for ($index = 0; $index < 250; $index++) {
        $store->request(new OwnerReference('organization', $index, 'testing'), $at);
    }

    expect($store->pending($at, 100))->toHaveCount(100)
        ->and($store->pending($at, 250))->toHaveCount(250)
        ->and(fn () => $store->pending($at, 1001))->toThrow(ReadFailure::class, 'invalid_pending_limit');
});
