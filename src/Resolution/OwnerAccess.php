<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Resolution;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;

/** No shared cache: each evaluation sees committed local state and the current time. */
final readonly class OwnerAccess
{
    public function __construct(private LocalResolver $resolver, private OwnerReference $owner, private ?DateTimeImmutable $at = null) {}

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

    /** @return array<string, bool|int|null> Snapshot for request-scoped batching. */
    public function all(): array
    {
        return $this->resolver->values($this->owner, $this->at ?? Date::now()->toDateTimeImmutable());
    }
}
