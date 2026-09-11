<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Tests\TestCase;

pest()->extend(TestCase::class);

it('rejects a late writer from a separate PostgreSQL process after a replacement worker commits', function () {
    $socket = getenv('ENTITLEMENTS_PG_SOCKET');
    if (! $socket) {
        $this->markTestSkipped('Run scripts/test-postgres.sh for real PostgreSQL/process coverage.');
    }
    config(['database.connections.m3pg' => ['driver' => 'pgsql', 'host' => $socket, 'database' => 'postgres',
        'username' => get_current_user(), 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'disable']]);
    DB::setDefaultConnection('m3pg');
    (require __DIR__.'/../../database/migrations/create_cashier_entitlements_tables.php.stub')->up();
    $store = new NativeStateStore(DB::connection());
    $owner = new OwnerReference('organization', 42, 'm3pg');
    $at = new DateTimeImmutable('2026-09-11T12:00:00Z');
    $store->request($owner, $at);
    $process = proc_open([PHP_BINARY, __DIR__.'/../Fixtures/late-writer.php', $socket],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    expect(is_resource($process))->toBeTrue();
    try {
        stream_set_timeout($pipes[1], 10);
        expect(trim(fgets($pipes[1])))->toBe('claimed');
        // The first process is still alive, paused at its provider-read boundary.
        $later = $at->modify('+2 seconds');
        $claim = $store->claim($owner, $later);
        expect($store->complete($claim, BillingDecision::allowed('mapped', allowances: ['projects' => 5]), 'v1', $later, $later))->toBeTrue();
        fwrite($pipes[0], "continue\n");
        fclose($pipes[0]);
        expect(trim(fgets($pipes[1])))->toBe('fenced');
        expect($store->state($owner)['allowances'])->toBe(['projects' => 5])
            ->and($store->state($owner)['applied_version'])->toBe(1);
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($process);
        proc_close($process);
    }
    // Both processes now contend for first insertion, sequence increments and receipts.
    $contended = new OwnerReference('organization', 43, 'm3pg');
    $process = proc_open([PHP_BINARY, __DIR__.'/../Fixtures/late-writer.php', $socket, 'requests'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    try {
        stream_set_timeout($pipes[1], 10);
        expect(trim(fgets($pipes[1])))->toBe('ready');
        fwrite($pipes[0], "go\n");
        for ($i = 0; $i < 10; $i++) {
            $store->request($contended, $at);
            $store->request($contended, $at, 'evt_shared');
        }
        expect(trim(fgets($pipes[1])))->toBe('done');
        expect($store->state($contended)['requested_sequence'])->toBe(21);
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_terminate($process);
        proc_close($process);
    }
});
