<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_entitlement_driver_bindings', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->char('owner_id', 64);
            $table->string('driver', 40);
            // The external group this package created, so a repeated refresh transitions it
            // instead of assigning a second one.
            $table->string('external_group_id');
            $table->string('external_plan_id');
            $table->string('plan_key');
            $table->string('bound_at', 30);
            $table->unique(['owner_id', 'driver'], 'ce_driver_binding_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_entitlement_driver_bindings');
    }
};
