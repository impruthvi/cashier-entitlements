<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Reconciliation\RefreshManager;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Impruthvi\CashierEntitlements\Tests\Support\BillingIntegrationTestCase;
use Impruthvi\CashierEntitlements\Tests\Support\StripeFixture;

pest()->extend(BillingIntegrationTestCase::class);

it('executes a serialized Laravel database-queue job from durable request to local access', function () {
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_tables.php.stub')->up();
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config(['cashier-entitlements.enabled' => true, 'cashier-entitlements.freshness' => ['max_stale_age' => 60],
        'queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'connection' => 'testing',
            'table' => 'jobs', 'queue' => 'entitlements', 'retry_after' => 300, 'after_commit' => false]]);
    $fixture = new StripeFixture([StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()])]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]));
    $reference = app(RefreshManager::class)->request($this->organization());
    expect(Queue::size('entitlements'))->toBe(1)->and($fixture->requests)->toBe([]);
    $job = Queue::pop('entitlements');
    $job->fire();
    expect(Queue::size('entitlements'))->toBe(0)
        ->and(app(LocalResolver::class)->for($reference)->limit('projects'))->toBe(10);
    $fixture->assertReadOnly();
});
