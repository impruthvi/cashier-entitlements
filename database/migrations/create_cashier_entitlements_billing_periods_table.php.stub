<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Early M4 installs created this table in the core migration. Preserve their history.
        if (Schema::hasTable('cashier_entitlement_billing_periods')) {
            return;
        }
        Schema::create('cashier_entitlement_billing_periods', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->char('owner_id', 64);
            $table->string('price_id');
            $table->string('item_id');
            $table->bigInteger('period_start');
            $table->bigInteger('period_end');
            $table->index(['owner_id', 'price_id'], 'ce_billing_period_owner_price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_entitlement_billing_periods');
    }
};
