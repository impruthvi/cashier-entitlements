<?php

declare(strict_types=1);

use Impruthvi\CashierEntitlements\Tests\Support\PennantProbeDriver;
use Impruthvi\CashierEntitlements\Tests\Support\PennantTestCase;
use Laravel\Pennant\Feature;

pest()->extend(PennantTestCase::class);

beforeEach(function () {
    $probe = $this->probe = new PennantProbeDriver;
    Feature::extend('m0-probe', fn () => $probe);
});

it('preserves zero and unlimited values but does not interpret numeric quotas as booleans', function () {
    $this->probe->values = ['org:1' => ['projects' => 0, 'storage' => null]];

    expect(Feature::store('entitlements')->for('org:1')->value('projects'))->toBe(0)
        ->and(Feature::store('entitlements')->for('org:1')->active('projects'))->toBeTrue()
        ->and(Feature::store('entitlements')->for('org:1')->value('storage'))->toBeNull()
        ->and(Feature::store('entitlements')->for('org:1')->active('storage'))->toBeTrue();
});

it('keeps same-process values stale until its decorator cache is flushed', function () {
    $this->probe->values = ['org:1' => ['projects' => 10]];
    $store = Feature::store('entitlements');

    expect($store->for('org:1')->value('projects'))->toBe(10);
    $this->probe->values['org:1']['projects'] = 5;
    expect($store->for('org:1')->value('projects'))->toBe(10);
    $store->flushCache();
    expect($store->for('org:1')->value('projects'))->toBe(5);
});

it('requires cache invalidation when time alone expires an entitlement', function () {
    $this->travelTo(now()->startOfDay());
    $this->probe->values = ['org:1' => ['ai' => true]];
    $this->probe->expiresAt = now()->toImmutable()->addMinute();
    $store = Feature::store('entitlements');

    expect($store->for('org:1')->active('ai'))->toBeTrue();
    $this->travel(61)->seconds();
    expect($store->for('org:1')->active('ai'))->toBeTrue();
    $store->flushCache();
    expect($store->for('org:1')->active('ai'))->toBeFalse();
});

it('loads externally defined features for only the requested owner', function () {
    $this->probe->values = ['org:1' => ['ai' => true, 'projects' => 10], 'org:2' => ['other' => false]];

    expect(Feature::store('entitlements')->for('org:1')->all())->toBe(['ai' => true, 'projects' => 10]);
});

it('rejects mutations through the public store', function (string $operation) {
    $store = Feature::store('entitlements');
    match ($operation) {
        'define' => $store->define('ai', fn () => true),
        'set' => $store->for('org:1')->activate('ai'),
        'delete' => $store->for('org:1')->forget('ai'),
        'all' => $store->activateForEveryone('ai'),
        'purge' => $store->purge(),
    };
})->with(['define', 'set', 'delete', 'all', 'purge'])
    ->throws(LogicException::class, 'Entitlements are read-only in Pennant.');

it('rejects missing owner scope', function () {
    Feature::store('entitlements')->for(null)->value('ai');
})->throws(InvalidArgumentException::class, 'Explicit owner scope is required.');

it('reads boolean and numeric values through a separate store with owner isolation', function () {
    $this->probe->values = ['org:1' => ['ai' => true, 'projects' => 10], 'org:2' => ['ai' => false, 'projects' => 0]];

    expect(Feature::store('entitlements')->for('org:1')->active('ai'))->toBeTrue()
        ->and(Feature::store('entitlements')->for('org:1')->value('projects'))->toBe(10)
        ->and(Feature::store('entitlements')->for('org:2')->active('ai'))->toBeFalse()
        ->and(Feature::store('entitlements')->for('org:2')->value('projects'))->toBe(0)
        ->and(config('pennant.default'))->toBe('array');
});
