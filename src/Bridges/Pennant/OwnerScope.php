<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Bridges\Pennant;

use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use InvalidArgumentException;
use Laravel\Pennant\Contracts\FeatureScopeSerializeable;

/** Keeps every owner identity component in Pennant's process-cache key. */
final readonly class OwnerScope implements FeatureScopeSerializeable
{
    public function __construct(public OwnerReference $owner)
    {
        if (! $owner->isValid()) {
            throw new InvalidArgumentException('invalid_pennant_owner');
        }
    }

    public function featureScopeSerialize(): string
    {
        return 'cashier-entitlements:'.hash('sha256', json_encode(get_object_vars($this->owner), JSON_THROW_ON_ERROR));
    }
}
