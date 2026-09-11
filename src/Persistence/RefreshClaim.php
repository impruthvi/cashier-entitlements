<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Persistence;

use Impruthvi\CashierEntitlements\Billing\OwnerReference;

final readonly class RefreshClaim
{
    public function __construct(public OwnerReference $owner, public int $generation, public int $sequence) {}
}
