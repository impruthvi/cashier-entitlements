<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Reconciliation;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\SubscriptionFacts;
use Impruthvi\CashierEntitlements\Billing\SubscriptionItem;
use Laravel\Cashier\Cashier;

final readonly class CashierLocalProjector
{
    public function __construct(private string $providerContext = 'platform', private bool $liveMode = false) {}

    public function read(Model $billable, DateTimeImmutable $at): BillingSnapshot
    {
        if (! class_exists(Cashier::class) || ! is_a($billable, Cashier::$customerModel) || ! $billable->exists) {
            throw new ReadFailure('invalid_billable');
        }
        // Do not trust previously loaded customer attributes or subscription relations.
        $billable = $billable->newQuery()->find($billable->getKey());
        if (! $billable instanceof Model || ! method_exists($billable, 'subscriptions')) {
            throw new ReadFailure('unknown_owner');
        }
        $owner = new OwnerReference($billable->getMorphClass(), $this->key($billable->getKey()),
            $this->text($billable->getConnection()->getName()), $this->providerContext, $this->liveMode);
        if (! $owner->isValid()) {
            throw new ReadFailure('invalid_owner');
        }
        $customer = $billable->getAttribute('stripe_id');
        if ($customer !== null) {
            $customer = $this->text($customer);
            if ($billable->newQueryWithoutScopes()->where('stripe_id', $customer)->count() !== 1) {
                throw new ReadFailure('ambiguous_customer');
            }
        }
        $relation = $billable->subscriptions();
        if (! $relation instanceof HasMany) {
            throw new ReadFailure('unsupported_subscription_relation');
        }
        $facts = [];
        $seen = [];
        $seenItems = [];
        $subscriptions = $relation->limit(10001)->get();
        if ($subscriptions->count() > 10000) {
            throw new ReadFailure('local_record_limit');
        }
        foreach ($subscriptions as $subscription) {
            if ($subscription->getConnection()->getName() !== $owner->connection || ! method_exists($subscription, 'items')) {
                throw new ReadFailure('unsupported_subscription_connection');
            }
            if ($customer === null) {
                throw new ReadFailure('missing_customer');
            }
            $id = $this->text($subscription->getAttribute('stripe_id'));
            if (isset($seen[$id])) {
                throw new ReadFailure('duplicate_local_subscription');
            }
            $seen[$id] = true;
            $items = [];
            $itemRelation = $subscription->items();
            if (! $itemRelation instanceof HasMany) {
                throw new ReadFailure('unsupported_item_relation');
            }
            $rows = $itemRelation->limit(10001)->get();
            foreach ($rows as $item) {
                $itemId = $this->text($item->getAttribute('stripe_id'));
                if ($item->getConnection()->getName() !== $owner->connection || isset($seenItems[$itemId])) {
                    throw new ReadFailure('invalid_local_item_identity');
                }
                $seenItems[$itemId] = true;
                if (count($seenItems) > 10000) {
                    throw new ReadFailure('local_record_limit');
                }
                $quantity = $item->getAttribute('quantity');
                if ($quantity !== null && ! is_int($quantity)) {
                    throw new ReadFailure('invalid_local_quantity');
                }
                $items[] = new SubscriptionItem($itemId, $this->text($item->getAttribute('stripe_price')), $quantity);
            }
            $facts[] = new SubscriptionFacts($owner, $id, $customer,
                $this->text($subscription->getAttribute('type')), $this->text($subscription->getAttribute('stripe_status')),
                $at, $items, $this->date($subscription->getAttribute('trial_ends_at')), $this->date($subscription->getAttribute('ends_at')));
        }

        return new BillingSnapshot($owner, $customer, $at, $facts);
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new ReadFailure('malformed_local_data');
        }

        return $value;
    }

    private function key(mixed $value): int|string
    {
        if (! is_int($value) && ! is_string($value)) {
            throw new ReadFailure('invalid_owner');
        }

        return $value;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! $value instanceof DateTimeInterface) {
            throw new ReadFailure('malformed_local_date');
        }

        return DateTimeImmutable::createFromInterface($value);
    }
}
