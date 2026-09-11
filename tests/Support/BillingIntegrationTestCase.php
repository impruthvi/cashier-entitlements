<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Cashier;

class BillingIntegrationTestCase extends CashierTestCase
{
    private string $previousCustomer;

    private array $previousMorphMap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCustomer = Cashier::$customerModel;
        $this->previousMorphMap = Relation::morphMap();
        Cashier::useCustomerModel(BillableOrganization::class);
        Relation::morphMap(['organization' => BillableOrganization::class], false);
        Schema::create('billing_organizations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('stripe_id')->nullable();
            $table->timestamps();
        });
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('billing_owner_uuid');
            $table->string('type');
            $table->string('stripe_id');
            $table->string('stripe_status');
            $table->string('stripe_price')->nullable();
            $table->integer('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
        Schema::create('subscription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id');
            $table->string('stripe_id');
            $table->string('stripe_product');
            $table->string('stripe_price');
            $table->integer('quantity')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Cashier::useCustomerModel($this->previousCustomer);
        Relation::morphMap($this->previousMorphMap, false);
        parent::tearDown();
    }

    public function organization(string $id = '8c792286-a444-4fa0-a57f-274b224080e0', ?string $customer = 'cus_1'): BillableOrganization
    {
        return BillableOrganization::create(['id' => $id, 'stripe_id' => $customer]);
    }

    public function subscription(BillableOrganization $owner, string $id = 'sub_1', string $status = 'active'): void
    {
        $subscription = $owner->subscriptions()->create(['type' => 'default', 'stripe_id' => $id,
            'stripe_status' => $status, 'stripe_price' => null, 'quantity' => null]);
        $subscription->items()->create(['stripe_id' => 'si_'.$id, 'stripe_product' => 'prod_1',
            'stripe_price' => 'price_base', 'quantity' => 1]);
    }
}
