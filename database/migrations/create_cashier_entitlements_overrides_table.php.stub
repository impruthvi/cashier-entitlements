<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_entitlement_overrides', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('owner_id', 64);
            $table->string('feature');
            $table->string('kind', 10);
            $table->bigInteger('target_id')->nullable()->index();
            $table->text('allowance')->nullable();
            $table->text('reason');
            $table->string('actor');
            $table->string('effective_at', 30);
            $table->string('expires_at', 30)->nullable();
            $table->string('recorded_at', 30);
            $table->index(['owner_id', 'kind', 'effective_at'], 'ce_override_owner_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_entitlement_overrides');
    }
};
