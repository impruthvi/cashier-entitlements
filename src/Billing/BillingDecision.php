<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Billing;

use DateTimeImmutable;

/** A calculation result, not an applied grant or an authorization facade. */
final readonly class BillingDecision
{
    /** @param array<string, bool|int|null> $allowances */
    private function __construct(
        public DecisionStatus $status,
        public string $reason,
        public ?DateTimeImmutable $validUntil = null,
        public array $allowances = [],
        public ?string $planKey = null,
    ) {}

    /** @param array<string, bool|int|null> $allowances */
    public static function allowed(string $reason, ?DateTimeImmutable $validUntil = null, array $allowances = [], ?string $planKey = null): self
    {
        return new self(DecisionStatus::Allowed, $reason, $validUntil, $allowances, $planKey);
    }

    public static function denied(string $reason): self
    {
        return new self(DecisionStatus::Denied, $reason);
    }

    public static function invalid(string $reason): self
    {
        return new self(DecisionStatus::Invalid, $reason);
    }
}
