<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Billing;

final readonly class PriceMapping
{
    /** @param array<string, bool|int|null> $allowances */
    public function __construct(
        public string $planKey,
        public array $allowances,
        public bool $isBase = true,
        public bool $perUnit = false,
    ) {}
}
