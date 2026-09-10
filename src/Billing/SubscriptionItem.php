<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Billing;

use DateTimeImmutable;

final readonly class SubscriptionItem
{
    public function __construct(
        public string $id,
        public string $priceId,
        public ?int $quantity,
        public ?DateTimeImmutable $periodStart = null,
        public ?DateTimeImmutable $periodEnd = null,
    ) {}
}
