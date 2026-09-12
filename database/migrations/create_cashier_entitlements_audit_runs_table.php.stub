<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_entitlement_audit_runs', function (Blueprint $table) {
            $table->char('id', 64)->primary();
            $table->string('scope');
            // A resumable cursor over owner keys. An incomplete scan cannot prove absence,
            // so completed_at stays null until the scope is exhausted.
            $table->text('cursor')->nullable();
            $table->bigInteger('started_at');
            $table->bigInteger('heartbeat_at');
            $table->bigInteger('completed_at')->nullable();
            $table->bigInteger('examined')->default(0);
            $table->bigInteger('requested')->default(0);
            $table->bigInteger('failed')->default(0);
            $table->string('last_error')->nullable();
            $table->index(['scope', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_entitlement_audit_runs');
    }
};
