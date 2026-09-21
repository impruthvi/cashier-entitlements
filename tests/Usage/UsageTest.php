<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Resolution\FeatureTypeMismatch;
use Impruthvi\CashierEntitlements\Resolution\FreshnessPolicy;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Resolution\UnknownFeature;
use Impruthvi\CashierEntitlements\Tests\TestCase;
use Impruthvi\CashierEntitlements\Usage\IdempotencyConflict;
use Impruthvi\CashierEntitlements\Usage\LimitExceeded;
use Impruthvi\CashierEntitlements\Usage\MeterPeriods;
use Impruthvi\CashierEntitlements\Usage\NativeUsage;

pest()->extend(TestCase::class);

beforeEach(function () {
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_tables.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_billing_periods_table.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_usage_tables.php.stub')->up();
});

it('atomically admits domain work once and rolls back both usage and work on failure', function () {
    $store = new NativeStateStore(DB::connection());
    $catalog = new PriceCatalog('v1', [], ['projects' => 1]);
    $usage = new NativeUsage($store, $catalog, new MeterPeriods(['projects' => 'calendar_day']));
    $resolver = new LocalResolver($store, $catalog, new FreshnessPolicy(retainLastKnown: true));
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-12T12:00:00Z');
    $store->request($owner, $at);
    $store->complete($store->claim($owner, $at), BillingDecision::allowed('free_plan', allowances: ['projects' => 1]), 'v1', $at, $at);
    Schema::create('projects', fn ($table) => $table->string('id')->primary());
    $create = function ($db, $receipt) {
        $db->table('projects')->insert(['id' => $receipt->id]);
    };
    expect(fn () => $usage->admit($owner, 'projects', 1, 'one', $resolver, function ($db, $receipt) use ($create) {
        $create($db, $receipt);
        throw new RuntimeException('domain_failed');
    }, $at))->toThrow(RuntimeException::class, 'domain_failed');
    expect($usage->usage($owner, 'projects', $at))->toBe(0)->and(DB::table('projects')->count())->toBe(0);
    $receipt = $usage->admit($owner, 'projects', 1, 'one', $resolver, $create, $at);
    expect($usage->admit($owner, 'projects', 1, 'one', $resolver, $create, $at))->toEqual($receipt)
        ->and(fn () => $usage->admit($owner, 'projects', 1, 'two', $resolver, $create, $at))->toThrow(LimitExceeded::class)
        ->and(DB::table('projects')->count())->toBe(1)
        ->and($usage->usage($owner, 'projects', $at))->toBe(1);
    expect(fn () => $usage->record($owner, 'projects', 1, 'one', $at))->toThrow(IdempotencyConflict::class);
    $usage->record($owner, 'projects', 2, 'measured-overage', $at);
    expect($usage->usage($owner, 'projects', $at))->toBe(3);
});

it('retains observed billing item periods across refreshes and refuses to guess missing boundaries', function () {
    $store = new NativeStateStore(DB::connection());
    $catalog = new PriceCatalog('v1', [], ['projects' => 10]);
    $usage = new NativeUsage($store, $catalog, new MeterPeriods(['projects' => 'billing:price_pro']));
    $owner = new OwnerReference('organization', 42, 'testing');
    $september = new DateTimeImmutable('2026-09-15T00:00:00Z');
    $october = new DateTimeImmutable('2026-10-15T00:00:00Z');
    expect(fn () => $usage->usage($owner, 'projects', $september))->toThrow(ReadFailure::class);
    foreach ([$september, $october] as $at) {
        $store->request($owner, $at);
        $store->complete($store->claim($owner, $at), BillingDecision::allowed('mapped', allowances: ['projects' => 10]), 'v1', $at, $at, [
            ['id' => 'sub_1', 'items' => [['id' => 'si_1', 'price_id' => 'price_pro', 'period_start' => $at->format(DATE_ATOM), 'period_end' => $at->modify('+1 month')->format(DATE_ATOM)]]],
        ]);
        if ($at === $september) {
            $first = $usage->record($owner, 'projects', 2, 'original', $at);
        }
    }
    expect($usage->record($owner, 'projects', 2, 'original', $october))->toEqual($first);
    $usage->record($owner, 'projects', 3, 'late', $october, $september->modify('+1 day'));
    expect($usage->usage($owner, 'projects', $september))->toBe(5)
        ->and($usage->usage($owner, 'projects', $october))->toBe(0)
        ->and(fn () => $usage->usage($owner, 'projects', $october->modify('+1 month')))->toThrow(ReadFailure::class);
});

it('rejects invalid increments and checked integer overflow without leaving a receipt', function () {
    $usage = new NativeUsage(new NativeStateStore(DB::connection()), new PriceCatalog('v1', [], ['projects' => null, 'ai' => true]), new MeterPeriods(['projects' => 'calendar_day', 'ai' => 'calendar_day']));
    $owner = new OwnerReference('organization', 42, 'testing');
    $at = new DateTimeImmutable('2026-09-12T12:00:00Z');
    foreach ([[0, 'zero'], [-1, 'negative'], [1, ' '], [1, str_repeat('x', 256)]] as [$quantity, $key]) {
        expect(fn () => $usage->record($owner, 'projects', $quantity, $key, $at))->toThrow(InvalidArgumentException::class);
    }
    expect(fn () => $usage->record($owner, 'missing', 1, 'key', $at))->toThrow(UnknownFeature::class)
        ->and(fn () => $usage->usage($owner, 'ai', $at))->toThrow(FeatureTypeMismatch::class)
        ->and(fn () => $usage->record($owner, 'projects', 1, 'future', $at, $at->modify('+1 second')))->toThrow(InvalidArgumentException::class);
    $usage->record($owner, 'projects', PHP_INT_MAX, 'full', $at);
    expect(fn () => $usage->record($owner, 'projects', 1, 'overflow', $at))->toThrow(OverflowException::class)
        ->and($usage->usage($owner, 'projects', $at))->toBe(PHP_INT_MAX);
    expect($usage->record($owner, 'projects', 1, 'overflow', $at->modify('+1 day'))->total)->toBe(1);
});

it('counts an operation once and returns its original period and result after midnight', function () {
    $usage = new NativeUsage(new NativeStateStore(DB::connection()), new PriceCatalog('v1', [], ['projects' => 2]), new MeterPeriods(['projects' => 'calendar_day']));
    $owner = new OwnerReference('organization', 42, 'testing');
    $before = new DateTimeImmutable('2026-09-12T23:59:59.999999Z');
    $after = new DateTimeImmutable('2026-09-13T00:00:00Z');
    $first = $usage->record($owner, 'projects', 3, 'operation', $before);
    $retry = $usage->record($owner, 'projects', 3, 'operation', $after);
    expect($retry)->toEqual($first)
        ->and($first->total)->toBe(3)
        ->and($first->period->start->format(DATE_ATOM))->toBe('2026-09-12T00:00:00+00:00')
        ->and($usage->usage($owner, 'projects', $before))->toBe(3)
        ->and($usage->usage($owner, 'projects', $after))->toBe(0);
});

it('keeps lifetime usage and admission in one period across calendar boundaries', function () {
    $store = new NativeStateStore(DB::connection());
    $catalog = new PriceCatalog('v1', [], ['projects' => 1]);
    $usage = new NativeUsage($store, $catalog, new MeterPeriods(['projects' => 'lifetime']));
    $resolver = new LocalResolver($store, $catalog, new FreshnessPolicy(retainLastKnown: true));
    $owner = new OwnerReference('organization', 42, 'testing');
    $createdAt = new DateTimeImmutable('2026-09-12T12:00:00Z');
    $yearsLater = new DateTimeImmutable('2031-01-01T00:00:00Z');
    $store->request($owner, $createdAt);
    $store->complete($store->claim($owner, $createdAt), BillingDecision::allowed('mapped', allowances: ['projects' => 1]), 'v1', $createdAt, $createdAt);
    Schema::create('projects', fn ($table) => $table->string('id')->primary());

    $receipt = $usage->admit(
        $owner,
        'projects',
        1,
        'first-project',
        $resolver,
        fn ($db, $usageReceipt) => $db->table('projects')->insert(['id' => $usageReceipt->id]),
        $createdAt,
    );

    expect($receipt->period->start->format(DATE_ATOM))->toBe('1970-01-01T00:00:00+00:00')
        ->and($receipt->period->end->format(DATE_ATOM))->toBe('9999-12-31T23:59:59+00:00')
        ->and($usage->usage($owner, 'projects', $yearsLater))->toBe(1)
        ->and(fn () => $usage->admit(
            $owner,
            'projects',
            1,
            'second-project',
            $resolver,
            fn ($db, $usageReceipt) => $db->table('projects')->insert(['id' => $usageReceipt->id]),
            $yearsLater,
        ))->toThrow(LimitExceeded::class)
        ->and(DB::table('projects')->count())->toBe(1);
});

it('rejects conflicting retries without changing usage and routes explicit late occurrences in UTC', function () {
    $usage = new NativeUsage(new NativeStateStore(DB::connection()), new PriceCatalog('v1', [], ['projects' => 2]), new MeterPeriods(['projects' => 'calendar_month']));
    $owner = new OwnerReference('organization', 42, 'testing');
    $now = new DateTimeImmutable('2026-10-02T00:00:00Z');
    $occurred = new DateTimeImmutable('2026-10-01T00:30:00+01:00');
    $receipt = $usage->record($owner, 'projects', 2, 'late', $now, $occurred);
    expect($receipt->period->start->format(DATE_ATOM))->toBe('2026-09-01T00:00:00+00:00')
        ->and($usage->usage($owner, 'projects', $occurred))->toBe(2)
        ->and($usage->usage($owner, 'projects', $now))->toBe(0)
        ->and(fn () => $usage->record($owner, 'projects', 3, 'late', $now, $occurred))->toThrow(IdempotencyConflict::class)
        ->and(fn () => $usage->record($owner, 'projects', 2, 'late', $now))->toThrow(IdempotencyConflict::class)
        ->and($usage->usage($owner, 'projects', $occurred))->toBe(2);
});
