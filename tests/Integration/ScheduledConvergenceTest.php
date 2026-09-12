<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Jobs\RefreshOwner;
use Impruthvi\CashierEntitlements\Persistence\AuditRunStore;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Tests\Support\ScheduledBillingTestCase;

pest()->extend(ScheduledBillingTestCase::class);

it('executes successive scheduled batches through completion before beginning a new scan', function () {
    foreach (['create_cashier_entitlements_tables', 'create_cashier_entitlements_audit_runs_table'] as $migration) {
        (require __DIR__.'/../../database/migrations/'.$migration.'.php.stub')->up();
    }
    config(['cashier-entitlements.enabled' => true, 'cashier-entitlements.freshness' => ['max_stale_age' => 3600]]);
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]));
    Date::setTestNow('2026-09-12T12:00:00Z');
    Bus::fake();
    $otherRun = app(AuditRunStore::class)->open('another_scope', Date::now()->toDateTimeImmutable());
    foreach (['1', '2', '3'] as $id) {
        $last = $this->organization($id, 'cus_'.$id);
    }
    $event = collect(app(Schedule::class)->dueEvents(app()))
        ->first(fn ($event) => str_contains($event->command ?? '', 'entitlements:sweep'));
    expect($event)->not->toBeNull();
    // Execute the exact registered command and its arguments, without spawning a different app.
    $command = substr($event->command, strpos($event->command, 'entitlements:sweep'));
    expect(Artisan::call($command))->toBe(1);
    $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($first)->toMatchArray(['complete' => false, 'examined' => 2, 'requested' => 2]);

    $this->travel(1)->hours();
    expect(Artisan::call($command))->toBe(0);
    $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($second)->toMatchArray(['run' => $first['run'], 'complete' => true, 'examined' => 3, 'requested' => 3])
        ->and(app(NativeStateStore::class)->state(app(OwnerLocator::class)->reference($last))['requested_sequence'])->toBe(1);

    $this->travel(1)->hours();
    expect(Artisan::call($command))->toBe(1);
    $third = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($third['run'])->not->toBe($first['run'])
        ->and($third)->toMatchArray(['complete' => false, 'examined' => 2, 'requested' => 0]);
    Bus::assertDispatchedTimes(RefreshOwner::class, 3);
    expect(app(AuditRunStore::class)->unfinished('another_scope'))->toBe($otherRun);
});
