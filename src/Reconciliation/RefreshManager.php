<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Reconciliation;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\DecisionStatus;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapper;
use Impruthvi\CashierEntitlements\Drivers\EntitlementDriver;
use Impruthvi\CashierEntitlements\Drivers\NativeOnlyDriver;
use Impruthvi\CashierEntitlements\Jobs\RefreshOwner;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Resolution\FreshnessPolicy;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;

final readonly class RefreshManager
{
    public function __construct(private Connection $connection, private NativeStateStore $store,
        private OwnerLocator $owners, private StripeSubscriptionSource $source, private PriceCatalog $catalog,
        private FreshnessPolicy $freshness, private EntitlementDriver $driver = new NativeOnlyDriver,
        private PriceMapper $mapper = new PriceMapper) {}

    public function request(Model $owner, ?string $eventId = null, bool $dispatch = true): OwnerReference
    {
        $this->enabled();
        $reference = $this->owners->reference($owner);
        $this->owners->customer($this->owners->find($reference));
        if ($this->store->request($reference, Date::now()->toDateTimeImmutable(), $eventId) && $dispatch) {
            $dispatch = function () use ($reference): void {
                try {
                    Bus::dispatch(new RefreshOwner($reference));
                } catch (\Throwable) {
                    // The committed request is the outbox. Pending recovery retries delivery.
                }
            };
            if ($this->connection->transactionLevel() > 0) {
                $this->connection->afterCommit($dispatch);
            } else {
                $dispatch();
            }
        }

        return $reference;
    }

    public function refresh(OwnerReference $owner): string
    {
        $this->enabled();
        if ($this->connection->transactionLevel() !== 0) {
            throw new ReadFailure('refresh_inside_transaction');
        }
        // Validate serialized owner context before touching a claim or reading a provider.
        $this->owners->validate($owner);
        $claim = $this->store->claim($owner, Date::now()->toDateTimeImmutable());
        if ($claim === null) {
            return 'no_work';
        }
        $stage = 'provider_failed';
        try {
            $model = $this->owners->find($owner);
            $customer = $this->owners->customer($model);
            if (! method_exists($model, 'subscriptions') || ! ($relation = $model->subscriptions()) instanceof HasMany) {
                throw new ReadFailure('unsupported_subscription_relation');
            }
            $known = [];
            foreach ($relation->limit(10001)->pluck('stripe_id') as $id) {
                if (! is_string($id)) {
                    throw new ReadFailure('invalid_local_subscription_id');
                }
                $known[] = $id;
            }
            $previous = $this->store->state($owner);
            if ($previous !== null && $previous['observations'] !== null) {
                foreach (json_decode($previous['observations'], true, flags: JSON_THROW_ON_ERROR) as $observation) {
                    if (! is_string($observation['id'] ?? null)) {
                        throw new ReadFailure('invalid_stored_observation');
                    }
                    $known[] = $observation['id'];
                }
            }
            $known = array_values(array_unique($known));
            $snapshot = $this->source->read($owner, $customer, Date::now()->toDateTimeImmutable(), $known);
            if ($this->freshness->maxStaleAgeSeconds !== null
                && Date::now()->getTimestamp() - $snapshot->observedAt->getTimestamp() >= $this->freshness->maxStaleAgeSeconds) {
                throw new ReadFailure('observation_expired_during_refresh');
            }
            $stage = 'mapping_failed';
            $type = config('cashier-entitlements.subscription_type', 'default');
            if (! is_string($type)) {
                throw new ReadFailure('invalid_subscription_type');
            }
            $decision = $this->mapper->map($owner, $snapshot->subscriptions, $this->catalog, Date::now()->toDateTimeImmutable(), true, $type);
            if ($decision->status === DecisionStatus::Invalid) {
                throw new ReadFailure($decision->reason);
            }
            $observations = [];
            foreach ($snapshot->subscriptions as $facts) {
                $items = [];
                foreach ($facts->items as $item) {
                    $items[] = ['id' => $item->id, 'price_id' => $item->priceId, 'quantity' => $item->quantity,
                        'period_start' => $item->periodStart?->format(DATE_ATOM), 'period_end' => $item->periodEnd?->format(DATE_ATOM)];
                }
                $observations[] = ['id' => $facts->id, 'customer_id' => $facts->customerId, 'type' => $facts->type, 'status' => $facts->status,
                    'trial_ends_at' => $facts->trialEndsAt?->format(DATE_ATOM), 'scheduled_ends_at' => $facts->scheduledEndsAt?->format(DATE_ATOM), 'items' => $items];
            }
            $stage = 'apply_failed';
            $applied = $this->connection->transaction(function () use ($owner, $customer, $claim, $decision, $snapshot, $observations): bool {
                $model = $this->owners->find($owner, lock: true);
                if ($this->owners->customer($model) !== $customer) {
                    throw new ReadFailure('customer_changed_during_refresh');
                }
                if (! $this->store->complete($claim, $decision, $this->catalog->version, $snapshot->observedAt,
                    Date::now()->toDateTimeImmutable(), $observations)) {
                    return false;
                }
                // A driver that refuses the change aborts this transaction, so nothing is applied.
                $this->driver->apply($model, $owner, $decision, Date::now()->toDateTimeImmutable());

                return true;
            }, 3);

            return $applied ? 'applied' : 'superseded';
        } catch (\Throwable $exception) {
            $reason = $exception instanceof ReadFailure ? $exception->getMessage() : $stage;
            try {
                $this->store->fail($claim, $reason, Date::now()->toDateTimeImmutable());
            } catch (\Throwable) {
                // A database outage still leaves the durable claim reclaimable on expiry.
            }
            throw new ReadFailure($reason);
        }
    }

    public function recover(int $limit = 100): int
    {
        $this->enabled();
        $count = 0;
        $deliveryFailed = false;
        foreach ($this->store->pending(Date::now()->toDateTimeImmutable(), $limit) as $owner) {
            try {
                $this->owners->find($owner);
                Bus::dispatch(new RefreshOwner($owner));
                $count++;
            } catch (\Throwable $exception) {
                $reason = $exception instanceof ReadFailure ? $exception->getMessage() : 'queue_dispatch_failed';
                $deliveryFailed = $deliveryFailed || ! $exception instanceof ReadFailure;
                $claim = $this->store->claim($owner, Date::now()->toDateTimeImmutable());
                if ($claim !== null) {
                    $this->store->fail($claim, $reason, Date::now()->toDateTimeImmutable());
                }
            }
        }
        if ($deliveryFailed) {
            throw new ReadFailure('queue_dispatch_failed');
        }

        return $count;
    }

    private function enabled(): void
    {
        if (config('cashier-entitlements.enabled') !== true) {
            throw new ReadFailure('application_disabled');
        }
    }
}
