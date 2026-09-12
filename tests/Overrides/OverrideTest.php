<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Overrides\NativeOverrides;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Resolution\FeatureTypeMismatch;
use Impruthvi\CashierEntitlements\Resolution\FreshnessPolicy;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Resolution\UnknownFeature;
use Impruthvi\CashierEntitlements\Tests\TestCase;

pest()->extend(TestCase::class);

beforeEach(function () {
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_tables.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_overrides_table.php.stub')->up();
});

it('resolves active overrides by effective start then recorded sequence and restores earlier grants', function () {
    $store = new NativeStateStore(DB::connection());
    $catalog = new PriceCatalog('v1', [], ['projects' => 1, 'ai' => false]);
    $overrides = new NativeOverrides($store, $catalog);
    $resolver = new LocalResolver($store, $catalog, new FreshnessPolicy(retainLastKnown: true), $overrides);
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-12T12:00:00Z');
    $first = $overrides->grant($owner, 'projects', 5, 'support extension', 'admin:1', $at, $at->modify('+1 day'));
    $newest = $overrides->grant($owner, 'projects', 10, 'short extension', 'admin:2', $at->modify('+1 hour'), $at->modify('+2 hours'));
    expect($resolver->for($owner, $at->modify('-1 microsecond'))->limit('projects'))->toBe(0)
        ->and($resolver->for($owner, $at)->limit('projects'))->toBe(5)
        ->and($resolver->for($owner, $at->modify('+1 hour'))->limit('projects'))->toBe(10)
        ->and($resolver->for($owner, $at->modify('+2 hours'))->limit('projects'))->toBe(5);
    $equal = $overrides->grant($owner, 'projects', null, 'unlimited extension', 'admin:3', $at->modify('+1 hour'), $at->modify('+3 hours'));
    expect($resolver->for($owner, $at->modify('+1 hour'))->limit('projects'))->toBeNull();
    $overrides->revoke($owner, $equal, 'ended early', 'admin:3', $at->modify('+90 minutes'));
    expect($resolver->for($owner, $at->modify('+90 minutes'))->limit('projects'))->toBe(10)
        ->and($resolver->for($owner, $at->modify('+1 day'))->limit('projects'))->toBe(0)
        ->and(array_column($overrides->history($owner), 'id'))->toContain($first, $newest, $equal);
});

it('refuses a grant the catalog cannot represent so resolution never reads an unusable allowance', function () {
    $store = new NativeStateStore(DB::connection());
    $overrides = new NativeOverrides($store, new PriceCatalog('v1', [], ['projects' => 1, 'ai' => false]));
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-12T12:00:00Z');
    $day = $at->modify('+1 day');

    expect(fn () => $overrides->grant($owner, 'unmapped', 5, 'why', 'admin:1', $at, $day))
        ->toThrow(UnknownFeature::class)
        ->and(fn () => $overrides->grant($owner, 'projects', true, 'why', 'admin:1', $at, $day))
        ->toThrow(FeatureTypeMismatch::class, 'override_type_mismatch')
        ->and(fn () => $overrides->grant($owner, 'ai', 5, 'why', 'admin:1', $at, $day))
        ->toThrow(FeatureTypeMismatch::class, 'override_type_mismatch');

    foreach ([[-1, 'why', 'admin:1', $day], [5, ' ', 'admin:1', $day], [5, 'why', ' ', $day], [5, 'why', 'admin:1', $at]] as [$allowance, $reason, $actor, $expires]) {
        expect(fn () => $overrides->grant($owner, 'projects', $allowance, $reason, $actor, $at, $expires))
            ->toThrow(InvalidArgumentException::class, 'invalid_override_grant');
    }

    expect(fn () => $overrides->revoke($owner, 9999, 'why', 'admin:1', $at))->toThrow(InvalidArgumentException::class, 'override_grant_not_found')
        ->and(fn () => $overrides->history($owner, limit: 1001))->toThrow(InvalidArgumentException::class, 'invalid_history_page')
        ->and($overrides->history($owner))->toBe([]);
});
