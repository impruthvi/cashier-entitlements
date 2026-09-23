<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Resolution;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Impruthvi\CashierEntitlements\Billing\DecisionStatus;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapper;
use Impruthvi\CashierEntitlements\Overrides\NativeOverrides;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Usage\AdmissionResolver;
use Impruthvi\CashierEntitlements\Usage\MeterPeriods;
use Impruthvi\CashierEntitlements\Usage\NativeUsage;

final readonly class LocalResolver implements AdmissionResolver
{
    public function __construct(private NativeStateStore $store, private PriceCatalog $catalog, private FreshnessPolicy $freshness, private ?NativeOverrides $overrides = null, private MeterPeriods $meters = new MeterPeriods) {}

    public function for(OwnerReference $owner, ?DateTimeImmutable $at = null): OwnerAccess
    {
        return new OwnerAccess($this, $owner, $at);
    }

    /** One local read pinned to `$at`, so a batch of feature answers costs a single query. */
    public function snapshot(OwnerReference $owner, DateTimeImmutable $at): OwnerAccess
    {
        return new OwnerAccess($this, $owner, $at, $this->values($owner, $at));
    }

    /** @return list<string> */
    public function catalogFeatures(): array
    {
        return array_keys($this->catalog->features());
    }

    public function usageStore(): NativeUsage
    {
        return new NativeUsage($this->store, $this->catalog, $this->meters);
    }

    public function assertConnection(OwnerReference $owner, Connection $connection): void
    {
        if ($this->store->database($owner) !== $connection) {
            throw new ReadFailure('admission_connection_mismatch');
        }
    }

    public function limit(OwnerReference $owner, string $feature, DateTimeImmutable $at): ?int
    {
        return $this->for($owner, $at)->limit($feature);
    }

    /** @return array<string, bool|int|null> One local query for a batch of feature values. */
    public function values(OwnerReference $owner, DateTimeImmutable $at): array
    {
        return [...$this->baseValues($owner, $at), ...($this->overrides?->values($owner, $at) ?? [])];
    }

    /** @return array<string, bool|int|null> */
    private function baseValues(OwnerReference $owner, DateTimeImmutable $at): array
    {
        $validation = (new PriceMapper)->map($owner, [], $this->catalog, $at, true);
        if ($validation->status === DecisionStatus::Invalid) {
            throw new ReadFailure($validation->reason);
        }
        $state = $this->store->state($owner);
        if ($state === null || $state['projection'] === [] || $state['catalog_version'] !== $this->catalog->version
            || $state['projection']['status'] !== 'allowed' || $state['observed_at'] > $at->getTimestamp()) {
            return [];
        }
        $expiry = $state['projection']['valid_until'];
        if ($expiry !== null && $at >= DateTimeImmutable::createFromFormat('U.u', $expiry)) {
            return [];
        }
        if (($state['projection']['paid'] ?? true) && $this->freshness->maxStaleAgeSeconds !== null
            && $at->getTimestamp() - $state['observed_at'] >= $this->freshness->maxStaleAgeSeconds) {
            return [];
        }

        return $state['allowances'];
    }

    public function booleanFeature(string $feature): bool
    {
        return $this->catalog->booleanFeature($feature);
    }
}
