<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Billing;

use DateTimeImmutable;

final readonly class SubscriptionFacts
{
    /** @param list<SubscriptionItem> $items */
    public function __construct(
        public OwnerReference $owner,
        public string $id,
        public string $customerId,
        public string $type,
        public string $status,
        public DateTimeImmutable $observedAt,
        public array $items,
        public ?DateTimeImmutable $trialEndsAt = null,
        public ?DateTimeImmutable $scheduledEndsAt = null,
    ) {}
}
