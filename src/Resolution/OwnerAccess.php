<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Resolution;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Usage\UsageReceipt;

/** No shared cache: each evaluation sees committed local state and the current time. */
final readonly class OwnerAccess
{
    /** @param array<string, bool|int|null>|null $snapshot One pre-read batch, reused by every feature below. */
    public function __construct(private LocalResolver $resolver, private OwnerReference $owner, private ?DateTimeImmutable $at = null, private ?array $snapshot = null) {}

    /** Boolean features answer access; numeric features answer their limit. */
    public function value(string $feature): bool|int|null
    {
        return $this->resolver->booleanFeature($feature) ? $this->can($feature) : $this->limit($feature);
    }

    public function can(string $feature): bool
    {
        if (! $this->resolver->booleanFeature($feature)) {
            throw new FeatureTypeMismatch('can_requires_boolean');
        }
        $value = $this->all()[$feature] ?? false;
        if (! is_bool($value)) {
            throw new FeatureTypeMismatch('invalid_boolean_grant');
        }

        return $value;
    }

    public function limit(string $feature): ?int
    {
        if ($this->resolver->booleanFeature($feature)) {
            throw new FeatureTypeMismatch('limit_requires_numeric');
        }
        $values = $this->all();
        $value = array_key_exists($feature, $values) ? $values[$feature] : 0;
        if ($value !== null && (! is_int($value) || $value < 0)) {
            throw new FeatureTypeMismatch('invalid_numeric_grant');
        }

        return $value;
    }

    public function usage(string $feature): int
    {
        return $this->resolver->usageStore()->usage($this->owner, $feature, $this->at ?? Date::now()->toDateTimeImmutable());
    }

    /** Informational only. Use NativeUsage::admit() for concurrent hard-limit enforcement. */
    public function remaining(string $feature): ?int
    {
        // Both reads use one evaluation instant, even if midnight falls between them.
        $at = $this->at ?? Date::now()->toDateTimeImmutable();
        $limit = $this->resolver->for($this->owner, $at)->limit($feature);
        $usage = $this->resolver->usageStore()->usage($this->owner, $feature, $at);

        return $limit === null ? null : $limit - $usage;
    }

    public function record(string $feature, int $quantity, string $idempotencyKey, ?DateTimeImmutable $occurredAt = null): UsageReceipt
    {
        return $this->resolver->usageStore()->record($this->owner, $feature, $quantity, $idempotencyKey, $this->at, $occurredAt);
    }

    /** @return array<string, bool|int|null> Snapshot for request-scoped batching. */
    public function all(): array
    {
        return $this->snapshot ?? $this->resolver->values($this->owner, $this->at ?? Date::now()->toDateTimeImmutable());
    }
}
