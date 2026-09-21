<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Tests\TestCase;
use Impruthvi\CashierEntitlements\Usage\MeterPeriods;
use Impruthvi\CashierEntitlements\Usage\NativeUsage;

pest()->extend(TestCase::class);

it('serializes concurrent PostgreSQL admissions so a one-unit limit admits exactly one process', function () {
    $socket = getenv('ENTITLEMENTS_PG_SOCKET');
    if (! $socket) {
        $this->markTestSkipped('Run scripts/test-postgres.sh for real PostgreSQL/process coverage.');
    }
    config(['database.connections.m4pg' => ['driver' => 'pgsql', 'host' => $socket, 'database' => 'postgres',
        'username' => get_current_user(), 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable']]);
    DB::setDefaultConnection('m4pg');
    // The cluster is shared across concurrency tests, so start from a known-empty schema.
    Schema::dropIfExists('projects');
    foreach (['create_cashier_entitlements_usage_tables', 'create_cashier_entitlements_billing_periods_table', 'create_cashier_entitlements_tables'] as $migration) {
        (require __DIR__.'/../../database/migrations/'.$migration.'.php.stub')->down();
    }
    foreach (['create_cashier_entitlements_tables', 'create_cashier_entitlements_billing_periods_table', 'create_cashier_entitlements_usage_tables'] as $migration) {
        (require __DIR__.'/../../database/migrations/'.$migration.'.php.stub')->up();
    }
    Schema::create('projects', fn (Blueprint $table) => $table->string('id')->primary());

    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 44, 'm4pg');
    $at = new DateTimeImmutable('2026-09-12T12:00:00Z');
    $store->request($owner, $at);
    $store->complete($store->claim($owner, $at), BillingDecision::allowed('mapped', allowances: ['projects' => 1]), 'v1', $at, $at);

    $spawn = fn (string $key, ?array &$pipes) => proc_open([PHP_BINARY, __DIR__.'/../Fixtures/usage-contender.php', $socket, $key],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    // proc_open pipes ignore stream_set_timeout, so wait for readability explicitly.
    $line = function ($pipe, int $seconds): ?string {
        $read = [$pipe];
        $write = $except = [];
        if (stream_select($read, $write, $except, $seconds) !== 1) {
            return null;
        }
        $value = fgets($pipe);

        return $value === false ? null : trim($value);
    };
    $holder = $spawn('holder', $holderPipes);
    $late = null;
    try {
        expect($line($holderPipes[1], 10))->toBe('holding');

        // The holder owns the row lock inside an open transaction; the late process must block, not read stale usage.
        $late = $spawn('late', $latePipes);
        expect($line($latePipes[1], 2))->toBeNull();

        fwrite($holderPipes[0], "commit\n");
        fclose($holderPipes[0]);
        expect($line($holderPipes[1], 10))->toBe('admitted');
        expect($line($latePipes[1], 10))->toBe('limit_exceeded');
    } finally {
        foreach ([$holderPipes ?? [], $latePipes ?? []] as $pipes) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
        foreach ([$holder, $late] as $process) {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
        }
    }

    $usage = new NativeUsage($store, new PriceCatalog('v1', [], ['projects' => 1]), new MeterPeriods(['projects' => 'lifetime']));
    expect($usage->usage($owner, 'projects', $at))->toBe(1)
        ->and(DB::table('projects')->pluck('id')->all())->toBe(['holder']);
});
