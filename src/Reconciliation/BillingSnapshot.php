<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Reconciliation;

use DateTimeImmutable;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\SubscriptionFacts;

/** A successful complete read, not an atomic point-in-time provider transaction. */
final readonly class BillingSnapshot
{
    /**
     * @param  list<SubscriptionFacts>  $subscriptions
     * @param  list<string>  $confirmedAbsentIds
     */
    public function __construct(
        public OwnerReference $owner,
        public ?string $customerId,
        public DateTimeImmutable $observedAt,
        public array $subscriptions,
        public array $confirmedAbsentIds = [],
    ) {}
}
