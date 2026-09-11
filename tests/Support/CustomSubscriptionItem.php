<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use Laravel\Cashier\SubscriptionItem;

final class CustomSubscriptionItem extends SubscriptionItem
{
    protected $table = 'custom_subscription_items';
}
