<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Usage;

final readonly class UsageReceipt
{
    public function __construct(public string $id, public UsagePeriod $period, public int $quantity, public int $total) {}
}
