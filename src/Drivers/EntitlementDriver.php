<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Drivers;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;

/**
 * Brings an external entitlement system into agreement with a decision.
 *
 * The refresh path calls this inside the same transaction and connection that commits the
 * native projection, so a rejected change leaves both untouched. A driver must be safe to
 * run again for an unchanged decision: a repeated refresh may not add capacity twice.
 */
interface EntitlementDriver
{
    public function apply(Model $subscriber, OwnerReference $owner, BillingDecision $decision, DateTimeImmutable $at): void;
}
