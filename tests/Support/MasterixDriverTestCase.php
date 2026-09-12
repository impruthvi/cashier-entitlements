<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Cashier;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\LaravelEntitlementsServiceProvider;
use LucaLongo\LaravelEntitlements\Models\Plan;

/** Cashier billing and Masterix entitlements on one connection, as the driver requires. */
class MasterixDriverTestCase extends CashierTestCase
{
    private string $previousCustomer;

    private array $previousMorphMap;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LaravelEntitlementsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('entitlements.type_enum', LicenseType::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCustomer = Cashier::$customerModel;
        $this->previousMorphMap = Relation::morphMap();
        Cashier::useCustomerModel(BillableAccount::class);
        Relation::morphMap(['organization' => BillableAccount::class], false);

        Schema::create('billing_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_id')->nullable();
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('billing_owner_uuid');
            $table->string('type');
            $table->string('stripe_id');
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
        Schema::create('subscription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('stripe_id');
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->timestamps();
        });

        // Execute upstream's published stubs, in its provider's order, rather than a copy
        // of its schema that could hide an incompatible upstream change.
        foreach ([
            'create_entitlement_plan_categories_table',
            'create_entitlement_plans_table',
            'create_entitlement_plan_items_table',
            'create_entitlement_licenses_table',
            'create_entitlement_license_usages_table',
            'add_allows_multiple_active_plans_to_entitlement_plan_categories_table',
            'create_entitlement_plan_transitions_table',
            'add_unique_type_per_plan_to_entitlement_plan_items_table',
        ] as $migration) {
            (require __DIR__.'/../../vendor/masterix21/laravel-entitlements/database/migrations/'.$migration.'.php.stub')->up();
        }

        foreach (['create_cashier_entitlements_tables', 'create_cashier_entitlements_billing_periods_table',
            'create_cashier_entitlements_driver_bindings_table'] as $migration) {
            (require __DIR__.'/../../database/migrations/'.$migration.'.php.stub')->up();
        }
    }

    protected function tearDown(): void
    {
        Cashier::useCustomerModel($this->previousCustomer);
        Relation::morphMap($this->previousMorphMap, false);
        parent::tearDown();
    }

    public function account(?string $customer = 'cus_1'): BillableAccount
    {
        return BillableAccount::create(['stripe_id' => $customer]);
    }

    public function subscription(BillableAccount $owner, string $id = 'sub_1', string $status = 'active'): void
    {
        $subscription = $owner->subscriptions()->create(['type' => 'default', 'stripe_id' => $id,
            'stripe_status' => $status, 'stripe_price' => null, 'quantity' => null]);
        $subscription->items()->create(['stripe_id' => 'si_'.$id, 'stripe_product' => 'prod_1',
            'stripe_price' => 'price_base', 'quantity' => 1]);
    }

    public function plan(int $projects, bool $active = true): Plan
    {
        $plan = Plan::create([
            'name' => ['en' => 'Plan '.$projects],
            'billing_period' => BillingPeriod::Monthly,
            'is_recurring' => true,
            'is_active' => $active,
        ]);
        $plan->items()->createMany([
            ['type' => LicenseType::Projects->value, 'quantity' => $projects, 'is_flexible' => false],
            ['type' => LicenseType::Ai->value, 'quantity' => 1, 'is_flexible' => false],
        ]);

        return $plan;
    }
}
