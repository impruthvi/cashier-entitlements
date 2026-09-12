<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Drivers;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;

/** Default: the native projection is already the applied state, so there is nothing to forward. */
final readonly class NativeOnlyDriver implements EntitlementDriver
{
    public function apply(Model $subscriber, OwnerReference $owner, BillingDecision $decision, DateTimeImmutable $at): void {}
}
