<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use DateTimeImmutable;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\SubscriptionFacts;
use Impruthvi\CashierEntitlements\Billing\SubscriptionItem;

final class BillingFixtures
{
    public static function at(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-10T12:00:00+00:00');
    }

    public static function owner(): OwnerReference
    {
        return new OwnerReference('organization', 42);
    }

    public static function subscription(array $overrides = []): SubscriptionFacts
    {
        return new SubscriptionFacts(...($overrides + [
            'owner' => self::owner(),
            'id' => 'sub_1',
            'customerId' => 'cus_1',
            'type' => 'default',
            'status' => 'active',
            'observedAt' => self::at(),
            'items' => [new SubscriptionItem('si_1', 'price_base', 1)],
        ]));
    }
}
