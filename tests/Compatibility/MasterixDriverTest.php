<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Drivers\EntitlementDriver;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Reconciliation\RefreshManager;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Impruthvi\CashierEntitlements\Tests\Support\BillableAccount;
use Impruthvi\CashierEntitlements\Tests\Support\BillableOrganization;
use Impruthvi\CashierEntitlements\Tests\Support\LicenseType;
use Impruthvi\CashierEntitlements\Tests\Support\MasterixDriverTestCase;
use Impruthvi\CashierEntitlements\Tests\Support\StripeFixture;
use LucaLongo\LaravelEntitlements\Exceptions\InsufficientCapacityForTransition;
use LucaLongo\LaravelEntitlements\Facades\Entitlements;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\LicenseUsage;

pest()->extend(MasterixDriverTestCase::class);

beforeEach(function () {
    Date::setTestNow('2026-09-12T12:00:00Z');
    $this->pro = $this->plan(10);
    $this->scale = $this->plan(20);
    $this->starter = $this->plan(5);
    config(['cashier-entitlements.enabled' => true, 'cashier-entitlements.driver' => 'masterix',
        'cashier-entitlements.freshness' => ['max_stale_age' => 60],
        'cashier-entitlements.masterix.plans' => ['pro' => $this->pro->getKey(),
            'scale' => $this->scale->getKey(), 'starter' => $this->starter->getKey()]]);
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]));
});

/** Applies a decision the way the refresh transaction does, so binding writes share its atomicity. */
function project(BillableAccount $account, BillingDecision $decision): void
{
    $owner = app(OwnerLocator::class)->reference($account);
    DB::transaction(fn () => app(EntitlementDriver::class)->apply($account, $owner, $decision, Date::now()->toDateTimeImmutable()));
}

function bindings(): array
{
    return DB::table('cashier_entitlement_driver_bindings')->get()->all();
}

it('assigns the mapped plan once and records the binding it now owns', function () {
    $account = $this->account();
    project($account, BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'));

    expect(Entitlements::capacity($account, LicenseType::Projects))->toBe(10)
        ->and(Entitlements::allows($account, LicenseType::Ai))->toBeTrue()
        ->and(bindings())->toHaveCount(1)
        ->and(bindings()[0]->plan_key)->toBe('pro')
        ->and((int) bindings()[0]->external_plan_id)->toBe($this->pro->getKey());
});

it('does not add capacity when the same decision is applied again', function () {
    $account = $this->account();
    $decision = BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro');
    project($account, $decision);
    $group = bindings()[0]->external_group_id;
    project($account, $decision);
    project($account, $decision);

    expect(Entitlements::capacity($account, LicenseType::Projects))->toBe(10)
        ->and(bindings())->toHaveCount(1)
        ->and(bindings()[0]->external_group_id)->toBe($group)
        ->and(License::query()->count())->toBe(2);
});

it('transitions the bound group on an upgrade and keeps consumed slots', function () {
    $account = $this->account();
    project($account, BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'));
    $first = bindings()[0]->external_group_id;
    Entitlements::consume($account, LicenseType::Projects, BillableAccount::create(), 3);
    project($account, BillingDecision::allowed('mapped', allowances: ['projects' => 20], planKey: 'scale'));

    expect(Entitlements::capacity($account, LicenseType::Projects))->toBe(20)
        ->and(Entitlements::available($account, LicenseType::Projects))->toBe(17)
        ->and(bindings())->toHaveCount(1)
        ->and(bindings()[0]->plan_key)->toBe('scale')
        ->and(bindings()[0]->external_group_id)->not->toBe($first);
});

it('aborts a downgrade below current usage instead of reporting it applied', function () {
    $account = $this->account();
    project($account, BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'));
    $group = bindings()[0]->external_group_id;
    Entitlements::consume($account, LicenseType::Projects, BillableAccount::create(), 8);

    expect(fn () => project($account, BillingDecision::allowed('mapped', allowances: ['projects' => 5], planKey: 'starter')))
        ->toThrow(InsufficientCapacityForTransition::class);

    expect(Entitlements::capacity($account, LicenseType::Projects))->toBe(10)
        ->and(Entitlements::available($account, LicenseType::Projects))->toBe(2)
        ->and(bindings()[0]->plan_key)->toBe('pro')
        ->and(bindings()[0]->external_group_id)->toBe($group);
});

it('closes only the group it owns and preserves usage history and unrelated groups', function () {
    $account = $this->account();
    $other = $this->account('cus_2');
    project($account, BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'));
    // A group this package never assigned must survive: it does not own the owner's whole account.
    Entitlements::assignPlan($account, $this->scale, Date::now());
    Entitlements::assignPlan($other, $this->pro, Date::now());
    $usage = Entitlements::consume($account, LicenseType::Projects, BillableAccount::create());

    project($account, BillingDecision::denied('no_eligible_subscription'));

    expect(Entitlements::capacity($account, LicenseType::Projects))->toBe(20)
        ->and(Entitlements::capacity($other, LicenseType::Projects))->toBe(10)
        ->and($usage->fresh())->not->toBeNull()
        ->and(bindings())->toBeEmpty();
});

it('closes idempotently when a denied decision is applied twice', function () {
    $account = $this->account();
    project($account, BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'));
    project($account, BillingDecision::denied('no_eligible_subscription'));
    $ended = License::query()->pluck('ends_at', 'id')->map(fn ($value) => (string) $value)->all();

    Date::setTestNow('2026-09-12T13:00:00Z');
    project($account, BillingDecision::denied('no_eligible_subscription'));

    expect(Entitlements::capacity($account, LicenseType::Projects))->toBe(0)
        ->and(License::query()->pluck('ends_at', 'id')->map(fn ($value) => (string) $value)->all())->toBe($ended);
});

it('closes the group for a free plan because no paid plan key maps to Masterix', function () {
    $account = $this->account();
    project($account, BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'));
    project($account, BillingDecision::allowed('free_plan', allowances: ['projects' => 1]));

    expect(Entitlements::capacity($account, LicenseType::Projects))->toBe(0)
        ->and(bindings())->toBeEmpty();
});

it('leaves no licences or binding when the enclosing transaction rolls back', function () {
    $account = $this->account();
    $owner = app(OwnerLocator::class)->reference($account);

    expect(fn () => DB::transaction(function () use ($account, $owner): void {
        app(EntitlementDriver::class)->apply($account, $owner,
            BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'), Date::now()->toDateTimeImmutable());
        throw new RuntimeException('Simulated later write failure');
    }))->toThrow(RuntimeException::class);

    expect(License::query()->count())->toBe(0)->and(bindings())->toBeEmpty();
});

it('never writes usage, leaving the native ledger as the single consumption authority', function () {
    $account = $this->account();
    project($account, BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'));
    project($account, BillingDecision::allowed('mapped', allowances: ['projects' => 20], planKey: 'scale'));

    expect(LicenseUsage::query()->count())->toBe(0);
});

it('refuses a plan key it cannot map and an inactive or missing external plan', function (string $planKey, string $reason) {
    $account = $this->account();
    config(['cashier-entitlements.masterix.plans' => ['pro' => $this->pro->getKey(),
        'retired' => $this->plan(3, active: false)->getKey(), 'ghost' => 987654]]);

    expect(fn () => project($account, BillingDecision::allowed('mapped', allowances: ['projects' => 1], planKey: $planKey)))
        ->toThrow(ReadFailure::class, $reason);
    expect(License::query()->count())->toBe(0)->and(bindings())->toBeEmpty();
})->with([['enterprise', 'unmapped_masterix_plan'], ['retired', 'unknown_masterix_plan'], ['ghost', 'unknown_masterix_plan']]);

it('refuses an owner whose key the published Masterix schema cannot store', function () {
    $account = $this->account();
    $owner = app(OwnerLocator::class)->reference($account);
    $uuid = new BillableOrganization(['id' => '8c792286-a444-4fa0-a57f-274b224080e0', 'stripe_id' => 'cus_1']);
    $uuid->exists = true;

    expect(fn () => app(EntitlementDriver::class)->apply($uuid, $owner,
        BillingDecision::allowed('mapped', allowances: ['projects' => 10], planKey: 'pro'), Date::now()->toDateTimeImmutable()))
        ->toThrow(ReadFailure::class, 'masterix_unsupported_owner_key');
});

it('refuses configuration that names an unknown driver or no plan map', function (array $config, string $reason) {
    config($config);
    expect(fn () => app(EntitlementDriver::class))->toThrow(ReadFailure::class, $reason);
})->with([
    [['cashier-entitlements.driver' => 'unsupported'], 'invalid_entitlement_driver'],
    [['cashier-entitlements.masterix.plans' => []], 'invalid_masterix_plans'],
    [['cashier-entitlements.masterix.plans' => ['pro' => '']], 'invalid_masterix_plans'],
]);

it('projects a real refresh onto Masterix and reverses it when the subscription is canceled', function () {
    $account = $this->account();
    $this->subscription($account);
    $fixture = new StripeFixture([
        StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()]),
        StripeFixture::page([StripeFixture::subscription(status: 'canceled')]), StripeFixture::page([]),
    ]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    $manager = app(RefreshManager::class);

    $owner = $manager->request($account, dispatch: false);
    expect($manager->refresh($owner))->toBe('applied')
        ->and(Entitlements::capacity($account, LicenseType::Projects))->toBe(10)
        ->and(app(LocalResolver::class)->for($owner)->limit('projects'))->toBe(10);

    $manager->request($account, dispatch: false);
    expect($manager->refresh($owner))->toBe('applied')
        ->and(Entitlements::capacity($account, LicenseType::Projects))->toBe(0)
        ->and(app(LocalResolver::class)->for($owner)->limit('projects'))->toBe(0);
    $fixture->assertReadOnly();
});

it('keeps the native projection and Masterix together when the driver refuses a refresh', function () {
    $account = $this->account();
    $this->subscription($account);
    config(['cashier-entitlements.masterix.plans' => ['scale' => $this->scale->getKey()]]);
    $fixture = new StripeFixture([
        StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()]),
    ]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    $manager = app(RefreshManager::class);
    $owner = $manager->request($account, dispatch: false);

    expect(fn () => $manager->refresh($owner))->toThrow(ReadFailure::class, 'unmapped_masterix_plan');

    expect(app(LocalResolver::class)->for($owner)->limit('projects'))->toBe(0)
        ->and(Entitlements::capacity($account, LicenseType::Projects))->toBe(0)
        ->and(app(NativeStateStore::class)->state($owner)['last_error'])->toBe('unmapped_masterix_plan')
        ->and(app(NativeStateStore::class)->state($owner)['completed_sequence'])->toBe(0)
        // The refusal is retryable, so the request stays outstanding past the failure backoff.
        ->and(app(NativeStateStore::class)->pending(Date::now()->addMinutes(2)->toDateTimeImmutable()))->toHaveCount(1);
});
