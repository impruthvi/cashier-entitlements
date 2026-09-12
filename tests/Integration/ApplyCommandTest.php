<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapping;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Impruthvi\CashierEntitlements\Tests\Support\BillingIntegrationTestCase;
use Impruthvi\CashierEntitlements\Tests\Support\StripeFixture;

pest()->extend(BillingIntegrationTestCase::class);

it('only applies an explicit owner with installation enablement and a freshness policy', function () {
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_tables.php.stub')->up();
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_billing_periods_table.php.stub')->up();
    $owner = $this->organization();
    config(['cashier-entitlements.enabled' => true, 'cashier-entitlements.freshness' => ['retain_last_known' => true]]);
    Bus::fake();
    $fixture = new StripeFixture([StripeFixture::page([StripeFixture::subscription()]), StripeFixture::page([StripeFixture::item()])]);
    app()->instance(StripeSubscriptionSource::class, new StripeSubscriptionSource($fixture->client()));
    app()->instance(PriceCatalog::class, new PriceCatalog('v1', ['price_base' => new PriceMapping('pro', ['projects' => 10])]));
    expect(Artisan::call('entitlements:reconcile', ['--owner-type' => 'organization', '--owner' => $owner->getKey(), '--apply' => true, '--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['mode' => 'apply', 'result' => 'applied']);
    expect(Artisan::call('entitlements:recover', ['--json' => true]))->toBe(0);
});
