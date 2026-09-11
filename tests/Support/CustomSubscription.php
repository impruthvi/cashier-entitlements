<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use Laravel\Cashier\Subscription;

final class CustomSubscription extends Subscription
{
    protected $table = 'custom_subscriptions';

    public function getForeignKey(): string
    {
        return 'subscription_id';
    }
}
