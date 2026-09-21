<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Resolution\FreshnessPolicy;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Usage\LimitExceeded;
use Impruthvi\CashierEntitlements\Usage\MeterPeriods;
use Impruthvi\CashierEntitlements\Usage\NativeUsage;

$capsule = new Manager;
$capsule->addConnection(['driver' => 'pgsql', 'host' => $argv[1], 'database' => 'postgres',
    'username' => get_current_user(), 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'sslmode' => 'disable'], 'm4pg');
$store = new NativeStateStore($capsule->getConnection('m4pg'));
$catalog = new PriceCatalog('v1', [], ['projects' => 1]);
$usage = new NativeUsage($store, $catalog, new MeterPeriods(['projects' => 'lifetime']));
$resolver = new LocalResolver($store, $catalog, new FreshnessPolicy(retainLastKnown: true));
$owner = new OwnerReference('organization', 44, 'm4pg');
$at = new DateTimeImmutable('2026-09-12T12:00:00Z');
$key = $argv[2];

// The domain write proves admission and application state commit or roll back together.
$create = function ($db, $receipt) use ($key): void {
    $db->table('projects')->insert(['id' => $key]);
    if ($key === 'holder') {
        // Hold the owner row lock open so the second process must queue behind this transaction.
        echo "holding\n";
        fflush(STDOUT);
        fgets(STDIN);
    }
};

try {
    $usage->admit($owner, 'projects', 1, $key, $resolver, $create, $at);
    echo "admitted\n";
} catch (LimitExceeded) {
    echo "limit_exceeded\n";
}
fflush(STDOUT);
