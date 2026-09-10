<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Impruthvi\CashierEntitlements\Tests\TestCase;
use LucaLongo\LaravelEntitlements\Enums\BillingPeriod;
use LucaLongo\LaravelEntitlements\LaravelEntitlementsServiceProvider;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Models\PlanCategory;

class MasterixTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LaravelEntitlementsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('entitlements.type_enum', LicenseType::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        // Execute upstream's published migration stubs, in its provider's order.
        // Duplicating its schema here could hide an incompatible upstream change.
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
    }

    public function plan(int $projects, ?PlanCategory $category = null): Plan
    {
        $plan = Plan::create([
            'plan_category_id' => $category?->getKey(),
            'name' => ['en' => 'Test plan'],
            'billing_period' => BillingPeriod::Monthly,
            'is_recurring' => true,
            'is_active' => true,
        ]);
        $plan->items()->createMany([
            ['type' => LicenseType::Projects->value, 'quantity' => $projects, 'is_flexible' => false],
            ['type' => LicenseType::Ai->value, 'quantity' => 1, 'is_flexible' => false],
        ]);

        return $plan;
    }
}
