<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Impruthvi\CashierEntitlements\Tests\Support\LicenseType;
use Impruthvi\CashierEntitlements\Tests\Support\MasterixTestCase;
use Impruthvi\CashierEntitlements\Tests\Support\Organization;
use LucaLongo\LaravelEntitlements\Enums\PlanTransitionMode;
use LucaLongo\LaravelEntitlements\Events\PlanAssigned;
use LucaLongo\LaravelEntitlements\Exceptions\InsufficientCapacityForTransition;
use LucaLongo\LaravelEntitlements\Exceptions\PlanCategoryExclusivityViolation;
use LucaLongo\LaravelEntitlements\Facades\Entitlements;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;

pest()->extend(MasterixTestCase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 10)->startOfDay());
});

it('boots both providers and grants numeric and boolean features to only the owner', function () {
    $owner = Organization::create();
    $other = Organization::create();
    Entitlements::assignPlan($owner, $this->plan(10), now());

    expect(Entitlements::capacity($owner, LicenseType::Projects))->toBe(10)
        ->and(Entitlements::allows($owner, LicenseType::Ai))->toBeTrue()
        ->and(Entitlements::capacity($other, LicenseType::Projects))->toBe(0)
        ->and(config('cashier-entitlements.enabled'))->toBeFalse()
        ->and(config('entitlements.type_enum'))->toBe(LicenseType::class);
});

it('characterizes raw assignment retries as duplicate grants requiring our own binding', function () {
    $owner = Organization::create();
    $plan = $this->plan(10);
    Entitlements::assignPlan($owner, $plan, now());
    Entitlements::assignPlan($owner, $plan, now());

    expect(Entitlements::capacity($owner, LicenseType::Projects))->toBe(20);
});

it('honors explicit exclusive categories rather than assuming their default', function () {
    $owner = Organization::create();
    $category = PlanCategory::create([
        'name' => ['en' => 'Base'], 'allows_multiple_active_plans' => false,
    ]);
    $plan = $this->plan(10, $category);
    Entitlements::assignPlan($owner, $plan, now());

    expect(fn () => Entitlements::assignPlan($owner, $plan, now()))
        ->toThrow(PlanCategoryExclusivityViolation::class);
    expect(Entitlements::capacity($owner, LicenseType::Projects))->toBe(10);
});

it('upgrades a plan while retaining consumed slots', function () {
    $owner = Organization::create();
    $anchor = Entitlements::assignPlan($owner, $this->plan(10), now())->first();
    Entitlements::consume($owner, LicenseType::Projects, Organization::create(), 3);
    Entitlements::changePlan($anchor, $this->plan(20), PlanTransitionMode::Immediate);

    expect(Entitlements::capacity($owner, LicenseType::Projects))->toBe(20)
        ->and(Entitlements::available($owner, LicenseType::Projects))->toBe(17);
});

it('rejects a downgrade below current usage and leaves the previous allowance active', function () {
    $owner = Organization::create();
    $anchor = Entitlements::assignPlan($owner, $this->plan(10), now())->first();
    Entitlements::consume($owner, LicenseType::Projects, Organization::create(), 8);

    expect(fn () => Entitlements::changePlan($anchor, $this->plan(5), PlanTransitionMode::Immediate))
        ->toThrow(InsufficientCapacityForTransition::class);
    expect(Entitlements::capacity($owner, LicenseType::Projects))->toBe(10)
        ->and(Entitlements::available($owner, LicenseType::Projects))->toBe(2);
});

it('can revoke one bound license group through a pinned model operation without affecting others', function () {
    $owner = Organization::create();
    $other = Organization::create();
    $anchor = Entitlements::assignPlan($owner, $this->plan(10), now())->first();
    Entitlements::assignPlan($other, $this->plan(7), now());
    $usage = Entitlements::consume($owner, LicenseType::Projects, Organization::create());

    // Feasibility probe only: upstream has no close-assignment API. A future driver
    // must bind provider identity, scope this write and preserve the usage history.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        DB::transaction(function () use ($anchor, $owner): void {
            License::query()
                ->where('subscriber_type', $owner->getMorphClass())
                ->where('subscriber_id', $owner->getKey())
                ->where(fn ($query) => $query->whereKey($anchor->getKey())->orWhere('parent_id', $anchor->getKey()))
                ->update(['ends_at' => now()]);
        });
    }

    expect(Entitlements::capacity($owner, LicenseType::Projects))->toBe(0)
        ->and(Entitlements::allows($owner, LicenseType::Ai))->toBeFalse()
        ->and(Entitlements::capacity($other, LicenseType::Projects))->toBe(7)
        ->and($usage->fresh())->not->toBeNull();
});

it('rolls assignment back with an enclosing transaction but emits its event before outer commit', function () {
    $owner = Organization::create();
    $plan = $this->plan(10);
    $events = 0;
    Event::listen(PlanAssigned::class, function () use (&$events): void {
        $events++;
    });

    expect(fn () => DB::transaction(function () use ($owner, $plan): void {
        Entitlements::assignPlan($owner, $plan, now());
        throw new RuntimeException('Simulated outer write failure');
    }))->toThrow(RuntimeException::class, 'Simulated outer write failure');

    expect(Entitlements::capacity($owner, LicenseType::Projects))->toBe(0)
        ->and($events)->toBe(1);
});

it('characterizes repeated consumption of the same subject as non-idempotent', function () {
    $owner = Organization::create();
    $subject = Organization::create();
    Entitlements::assignPlan($owner, $this->plan(10), now());
    Entitlements::consume($owner, LicenseType::Projects, $subject);
    Entitlements::consume($owner, LicenseType::Projects, $subject);

    expect(Entitlements::available($owner, LicenseType::Projects))->toBe(8);
});
