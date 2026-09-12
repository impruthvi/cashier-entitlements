<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Bridges\Pennant;

use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Resolution\OwnerAccess;
use InvalidArgumentException;
use Laravel\Pennant\Contracts\DefinesFeaturesExternally;
use Laravel\Pennant\Contracts\Driver;
use LogicException;

/**
 * Read-only view of applied entitlements.
 *
 * Pennant's decorator, not this driver, owns snapshot caching: a value read here stays
 * cached for the rest of the request or job even after the grant changes or expires.
 * Use the native resolver wherever an authorization decision must be strictly fresh.
 */
final readonly class NativeDriver implements DefinesFeaturesExternally, Driver
{
    /** @param Closure(): LocalResolver $resolver Resolve current application bindings on a cache miss. */
    public function __construct(private Closure $resolver) {}

    public function get(string $feature, mixed $scope): mixed
    {
        return $this->getAll([$feature => [$scope]])[$feature][0];
    }

    public function getAll(array $features): array
    {
        $resolver = ($this->resolver)();
        $at = Date::now()->toDateTimeImmutable();
        $owners = [];
        $values = [];
        foreach ($features as $feature => $scopes) {
            foreach ($scopes as $index => $scope) {
                $owner = $this->snapshot($resolver, $scope, $at, $owners);
                $values[$feature][$index] = $owner->value((string) $feature);
            }
        }

        return $values;
    }

    /** @return list<string> */
    public function defined(): array
    {
        return ($this->resolver)()->catalogFeatures();
    }

    /**
     * The catalog is application-owned, so every valid owner is offered the same features.
     *
     * @return list<string>
     */
    public function definedFeaturesForScope(mixed $scope): array
    {
        $this->owner($scope);

        return $this->defined();
    }

    public function define(string $feature, callable $resolver): void
    {
        throw new LogicException('Entitlements are read-only in Pennant.');
    }

    public function set(string $feature, mixed $scope, mixed $value): void
    {
        throw new LogicException('Entitlements are read-only in Pennant.');
    }

    public function setForAllScopes(string $feature, mixed $value): void
    {
        throw new LogicException('Entitlements are read-only in Pennant.');
    }

    public function delete(string $feature, mixed $scope): void
    {
        throw new LogicException('Entitlements are read-only in Pennant.');
    }

    /** @param list<string>|null $features */
    public function purge(?array $features): void
    {
        throw new LogicException('Entitlements are read-only in Pennant.');
    }

    /** @param array<string, OwnerAccess> $owners Reuses one local read per owner across the batch. */
    private function snapshot(LocalResolver $resolver, mixed $scope, DateTimeImmutable $at, array &$owners): OwnerAccess
    {
        $owner = $this->owner($scope);
        $key = $owner->featureScopeSerialize();
        $owners[$key] ??= $resolver->snapshot($owner->owner, $at);

        return $owners[$key];
    }

    /** @phpstan-assert OwnerScope $scope */
    private function owner(mixed $scope): OwnerScope
    {
        if (! $scope instanceof OwnerScope) {
            throw new InvalidArgumentException('explicit_pennant_owner_scope_required');
        }

        return $scope;
    }
}
