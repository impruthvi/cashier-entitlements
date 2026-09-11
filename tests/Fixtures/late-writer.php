<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;

$capsule = new Manager;
$capsule->addConnection(['driver' => 'pgsql', 'host' => $argv[1], 'database' => 'postgres',
    'username' => get_current_user(), 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'sslmode' => 'disable'], 'm3pg');
$store = new NativeStateStore($capsule->getConnection('m3pg'));
$at = new DateTimeImmutable('2026-09-11T12:00:00Z');
if (($argv[2] ?? null) === 'requests') {
    $owner = new OwnerReference('organization', 43, 'm3pg');
    echo "ready\n";
    fflush(STDOUT);
    fgets(STDIN);
    for ($i = 0; $i < 10; $i++) {
        $store->request($owner, $at);
        $store->request($owner, $at, 'evt_shared');
    }
    echo "done\n";
    exit;
}
$owner = new OwnerReference('organization', 42, 'm3pg');
$claim = $store->claim($owner, $at, 1);
if ($claim === null) {
    exit(2);
}
echo "claimed\n";
fflush(STDOUT);
fgets(STDIN);
$applied = $store->complete($claim, BillingDecision::allowed('mapped', allowances: ['projects' => 99]), 'v1', $at, $at->modify('+2 seconds'));
echo $applied ? "incorrectly_applied\n" : "fenced\n";
