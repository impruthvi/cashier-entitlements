<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Resolution;

use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;

final readonly class FreshnessPolicy
{
    public function __construct(public ?int $maxStaleAgeSeconds = null, public bool $retainLastKnown = false)
    {
        if (($maxStaleAgeSeconds !== null) === $retainLastKnown || ($maxStaleAgeSeconds !== null && $maxStaleAgeSeconds < 1)) {
            throw new ReadFailure('explicit_freshness_policy_required');
        }
    }
}
