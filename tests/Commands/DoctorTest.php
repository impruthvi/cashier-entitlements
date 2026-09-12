<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
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

function doctor(array $options = []): array
{
    $exit = Artisan::call('entitlements:doctor', ['--json' => true, ...$options]);

    return [$exit, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)];
}

function status(array $report, string $name): string
{
    return array_column($report['checks'], 'status', 'name')[$name];
}

it('reports a healthy installation without opening a provider connection', function () {
    config(['cashier-entitlements.schedule' => ['owner_type' => 'organization', 'sweep' => '0 * * * *',
        'recover' => '*/5 * * * *', 'sweep_limit' => 100, 'stale_after' => null]]);
    $fixture = new StripeFixture([]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));

    [$exit, $report] = doctor();

    expect($exit)->toBe(0)
        ->and($report['schema_version'])->toBe(1)
        ->and(status($report, 'price_catalog'))->toBe('ok')
        ->and(status($report, 'freshness_policy'))->toBe('ok')
        ->and(status($report, 'entitlement_driver'))->toBe('ok')
        ->and(status($report, 'application_enabled'))->toBe('ok')
        ->and(status($report, 'scheduled_convergence'))->toBe('ok')
        ->and($report['state'])->toMatchArray(['owners' => 0, 'pending' => 0, 'failing' => 0, 'stale' => 0])
        ->and($fixture->requests)->toBe([]);
});

it('warns with the exact reason a convergence schedule was ignored rather than silently skipping it', function (array $schedule, string $reason) {
    config(['cashier-entitlements.schedule' => $schedule]);

    [$exit, $report] = doctor();

    expect($exit)->toBe(1)
        ->and(status($report, 'scheduled_convergence'))->toBe('warn')
        ->and(array_column($report['checks'], 'detail', 'name')['scheduled_convergence'])->toBe($reason);
})->with([
    'nothing scheduled' => [['owner_type' => 'organization'], 'no_scheduled_convergence'],
    'no owner type' => [['sweep' => '0 * * * *'], 'schedule_requires_owner_type'],
    'bad expression' => [['owner_type' => 'organization', 'sweep' => 'hourly'], 'invalid_cron_expression'],
    'invalid minute' => [['owner_type' => 'organization', 'sweep' => '99 * * * *'], 'invalid_cron_expression'],
    'missing fields' => [['owner_type' => 'organization', 'sweep' => '* * *'], 'invalid_cron_expression'],
    'partial schedule' => [['owner_type' => 'organization', 'sweep' => 'hourly', 'recover' => '*/5 * * * *'], 'invalid_cron_expression'],
    'bad limit' => [['owner_type' => 'organization', 'sweep' => '0 * * * *', 'sweep_limit' => 9999], 'invalid_sweep_limit'],
    'bad stale age' => [['owner_type' => 'organization', 'sweep' => '0 * * * *', 'stale_after' => 0], 'invalid_stale_age'],
    'not an array' => [[], 'no_scheduled_convergence'],
]);

it('fails when the application never bound a catalog or chose one freshness policy', function (array $config, string $check) {
    config($config);
    app()->forgetInstance(PriceCatalog::class);
    app()->offsetUnset(PriceCatalog::class);

    [$exit, $report] = doctor();

    expect($exit)->toBe(2)->and(status($report, $check))->toBe('fail');
})->with([
    'unbound catalog' => [[], 'price_catalog'],
    'invalid freshness' => [['cashier-entitlements.freshness' => ['max_stale_age' => 'soon']], 'freshness_policy'],
    'invalid meters' => [['cashier-entitlements.meters' => ['seats' => 'whenever']], 'meter_rules'],
    'unknown driver' => [['cashier-entitlements.driver' => 'invented'], 'entitlement_driver'],
]);

it('warns about pending work, failures and stale observations, and counts reasons by code', function () {
    $owner = $this->organization();
    $reference = app(OwnerLocator::class)->reference($owner);
    $store = app(NativeStateStore::class);
    $at = Date::now()->toDateTimeImmutable();
    $store->request($reference, $at);
    $store->complete($store->claim($reference, $at), BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'), 'v1', $at, $at);
    $store->request($reference, $at);
    $store->fail($store->claim($reference, $at), 'provider_unavailable', $at);
    $this->travel(2)->hours();

    [$exit, $report] = doctor();

    expect($exit)->toBe(1)
        ->and($report['state'])->toMatchArray(['owners' => 1, 'pending' => 1, 'failing' => 1, 'stale' => 1])
        ->and($report['state']['oldest_observation_age'])->toBe(7200)
        ->and($report['errors'])->toBe(['provider_unavailable' => 1])
        ->and(status($report, 'stale'))->toBe('warn');
});

it('warns when applied state belongs to a superseded catalog version', function () {
    $owner = $this->organization();
    $reference = app(OwnerLocator::class)->reference($owner);
    $store = app(NativeStateStore::class);
    $at = Date::now()->toDateTimeImmutable();
    $store->request($reference, $at);
    $store->complete($store->claim($reference, $at), BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'), 'v0', $at, $at);

    [$exit, $report] = doctor();

    expect($exit)->toBe(1)->and($report['state']['catalog_mismatch'])->toBe(1)
        ->and(status($report, 'catalog_mismatch'))->toBe('warn');
});

it('warns that missed notifications are undetected until a scan has exhausted the scope', function () {
    $this->organization();
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource(
        (new StripeFixture([StripeFixture::page([]), StripeFixture::page([])]))->client()));

    [$exit, $before] = doctor(['--owner-type' => 'organization']);
    expect($exit)->toBe(1)->and($before['sweep'])->toBeNull()
        ->and(status($before, 'account_sweep'))->toBe('warn');

    Artisan::call('entitlements:sweep', ['--owner-type' => 'organization', '--json' => true]);
    [, $after] = doctor(['--owner-type' => 'organization']);

    expect($after['sweep'])->toMatchArray(['complete' => true, 'examined' => 1, 'failed' => 0])
        ->and(status($after, 'account_sweep'))->toBe('ok');
});

it('emits only counts, timestamps and known reason codes, never credentials or SQL', function () {
    config(['cashier-entitlements.freshness' => ['max_stale_age' => 'soon']]);
    $owner = $this->organization();
    $store = app(NativeStateStore::class);
    $at = Date::now()->toDateTimeImmutable();
    $reference = app(OwnerLocator::class)->reference($owner);
    $store->request($reference, $at);
    $store->fail($store->claim($reference, $at), 'provider_unavailable', $at);

    [, $report] = doctor(['--owner-type' => 'organization']);
    $json = json_encode($report, JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('sk_')->not->toContain('cus_')
        ->not->toContain('select ')->not->toContain('Exception')
        ->and($report['state']['threshold'])->toBeNull();
});

it('reports failed sweep owners even when no native state could be created', function () {
    config(['cashier-entitlements.schedule' => ['owner_type' => 'organization', 'sweep' => '0 * * * *', 'recover' => '*/5 * * * *']]);
    $this->organization('1', 'cus_shared');
    $this->organization('2', 'cus_shared');
    Artisan::call('entitlements:sweep', ['--owner-type' => 'organization']);

    [$exit, $report] = doctor(['--owner-type' => 'organization']);

    expect($exit)->toBe(1)
        ->and($report['state']['owners'])->toBe(0)
        ->and($report['sweep'])->toMatchArray(['complete' => true, 'failed' => 2, 'last_error' => 'ambiguous_customer'])
        ->and(status($report, 'account_sweep'))->toBe('warn')
        ->and($report['errors'])->toBe(['sweep_owner_failed' => 2]);
});

it('rejects an unusable stale-age argument instead of reporting a misleading scan', function () {
    [$exit, $report] = doctor(['--stale-after' => '0']);

    expect($exit)->toBe(2)->and($report['errors'])->toBe(['invalid_stale_age']);
});
