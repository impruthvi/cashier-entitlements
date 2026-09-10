<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Laravel\Pennant\Contracts\DefinesFeaturesExternally;
use Laravel\Pennant\Contracts\Driver;
use LogicException;

/** Test-only source to exercise Pennant's real decorator; not a shipped bridge. */
final class PennantProbeDriver implements DefinesFeaturesExternally, Driver
{
    /** @var array<string, array<string, bool|int|null>> */
    public array $values = [];

    public ?CarbonImmutable $expiresAt = null;

    public function get(string $feature, mixed $scope): mixed
    {
        if (! is_string($scope) || $scope === '') {
            throw new InvalidArgumentException('Explicit owner scope is required.');
        }

        if (! array_key_exists($feature, $this->values[$scope] ?? [])) {
            throw new InvalidArgumentException('Unknown feature for owner.');
        }

        return $this->expiresAt?->isPast() ? false : $this->values[$scope][$feature];
    }

    public function getAll(array $features): array
    {
        $result = [];
        foreach ($features as $feature => $scopes) {
            $result[$feature] = array_map(fn ($scope) => $this->get($feature, $scope), $scopes);
        }

        return $result;
    }

    public function defined(): array
    {
        return array_values(array_unique(array_merge(...array_values(array_map(array_keys(...), $this->values)))));
    }

    public function definedFeaturesForScope(mixed $scope): array
    {
        if (! is_string($scope) || $scope === '') {
            throw new InvalidArgumentException('Explicit owner scope is required.');
        }

        return array_keys($this->values[$scope] ?? []);
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

    public function purge(?array $features): void
    {
        throw new LogicException('Entitlements are read-only in Pennant.');
    }
}
