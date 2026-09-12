<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_entitlement_usage_counters', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->bigInteger('total');
        });
        Schema::create('cashier_entitlement_usage_events', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->char('owner_id', 64)->index();
            $table->string('feature');
            $table->string('operation_key');
            $table->char('payload_hash', 64);
            $table->string('period_start', 40);
            $table->string('period_end', 40);
            $table->string('occurred_at', 40);
            $table->bigInteger('quantity');
            $table->bigInteger('total');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_entitlement_usage_events');
        Schema::dropIfExists('cashier_entitlement_usage_counters');
    }
};
