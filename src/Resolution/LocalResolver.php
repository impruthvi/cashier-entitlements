<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Resolution;

use DateTimeImmutable;
use Impruthvi\CashierEntitlements\Billing\DecisionStatus;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapper;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;

final readonly class LocalResolver
{
    public function __construct(private NativeStateStore $store, private PriceCatalog $catalog, private FreshnessPolicy $freshness) {}

    public function for(OwnerReference $owner, ?DateTimeImmutable $at = null): OwnerAccess
    {
        return new OwnerAccess($this, $owner, $at);
    }

    /** @return array<string, bool|int|null> One local query for a batch of feature values. */
    public function values(OwnerReference $owner, DateTimeImmutable $at): array
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
        $features = $this->catalog->freeAllowances;
        foreach ($this->catalog->prices as $mapping) {
            $features = [...$features, ...$mapping->allowances];
        }
        if (! array_key_exists($feature, $features)) {
            throw new UnknownFeature($feature);
        }

        return is_bool($features[$feature]);
    }
}
