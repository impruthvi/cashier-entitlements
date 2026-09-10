<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Billing;

use DateTimeImmutable;

final readonly class PriceMapper
{
    public function __construct(private StatusPolicy $policy = new StatusPolicy) {}

    /** @param list<SubscriptionFacts> $subscriptions */
    public function map(OwnerReference $owner, array $subscriptions, PriceCatalog $catalog, DateTimeImmutable $at, bool $complete, string $subscriptionType = 'default'): BillingDecision
    {
        if (! $complete) {
            return BillingDecision::invalid('incomplete_snapshot');
        }
        if (! $owner->isValid()) {
            return BillingDecision::invalid('invalid_owner');
        }
        if ($owner->providerContext !== $catalog->providerContext || $owner->liveMode !== $catalog->liveMode) {
            return BillingDecision::invalid('catalog_context_mismatch');
        }
        if (trim($subscriptionType) === '') {
            return BillingDecision::invalid('invalid_subscription_type');
        }
        if (($error = $this->catalogError($catalog)) !== null) {
            return BillingDecision::invalid($error);
        }

        // Validate the whole observation's identity before selecting a billing domain.
        $seen = [];
        foreach ($subscriptions as $facts) {
            if (! $facts instanceof SubscriptionFacts) {
                return BillingDecision::invalid('invalid_subscription');
            }
            if (! $facts->owner->equals($owner)) {
                return BillingDecision::invalid('owner_mismatch');
            }
            if (isset($seen[$facts->id])) {
                return BillingDecision::invalid('duplicate_subscription');
            }
            $seen[$facts->id] = true;
        }
        usort($subscriptions, fn (SubscriptionFacts $a, SubscriptionFacts $b): int => strcmp($a->id, $b->id));

        $base = null;
        $until = null;
        $eligible = false;
        $contributions = [];
        $seenItems = [];
        $customer = null;
        foreach ($subscriptions as $facts) {
            if ($facts->type !== $subscriptionType) {
                continue;
            }
            $decision = $this->policy->evaluate($facts, $at);
            if ($decision->status === DecisionStatus::Invalid) {
                return $decision;
            }
            if ($decision->status === DecisionStatus::Denied) {
                continue;
            }
            $eligible = true;
            if ($customer !== null && $customer !== $facts->customerId) {
                return BillingDecision::invalid('conflicting_customers');
            }
            $customer = $facts->customerId;
            if ($facts->items === []) {
                return BillingDecision::invalid('missing_items');
            }
            if ($decision->validUntil !== null && ($until === null || $decision->validUntil < $until)) {
                $until = $decision->validUntil;
            }
            // Validate item identity before mapping; duplicates must never add grants.
            $items = $facts->items;
            foreach ($items as $item) {
                if (! $item instanceof SubscriptionItem || trim($item->id) === '' || trim($item->priceId) === '') {
                    return BillingDecision::invalid('invalid_item');
                }
                if (isset($seenItems[$item->id])) {
                    return BillingDecision::invalid('duplicate_item');
                }
                $seenItems[$item->id] = true;
                if (($item->periodStart === null) !== ($item->periodEnd === null)
                    || ($item->periodStart !== null && $item->periodStart >= $item->periodEnd)) {
                    return BillingDecision::invalid('invalid_item_period');
                }
            }
            usort($items, fn (SubscriptionItem $a, SubscriptionItem $b): int => strcmp($a->id, $b->id));
            foreach ($items as $item) {
                $quantity = $item->quantity;
                if ($quantity === null || $quantity < 1) {
                    return BillingDecision::invalid('invalid_quantity');
                }
                $mapping = $catalog->prices[$item->priceId] ?? null;
                if ($mapping === null) {
                    return BillingDecision::invalid('unknown_price');
                }
                if (! $mapping->perUnit && $quantity !== 1) {
                    return BillingDecision::invalid('quantity_requires_per_unit');
                }
                if ($mapping->isBase) {
                    if ($base !== null) {
                        return BillingDecision::invalid('conflicting_bases');
                    }
                    $base = $mapping;
                }
                foreach ($mapping->allowances as $feature => $value) {
                    $contributions[$feature][] = [$value, $mapping->perUnit ? $quantity : 1];
                }
            }
        }
        if ($base === null) {
            if ($eligible) {
                return BillingDecision::invalid('missing_base');
            }
            if ($catalog->freeAllowances === []) {
                return BillingDecision::denied('no_eligible_subscription');
            }
            $allowances = $catalog->freeAllowances;
            ksort($allowances);

            return BillingDecision::allowed('free_plan', allowances: $allowances);
        }
        $allowances = [];
        foreach ($contributions as $feature => $values) {
            if (is_bool($values[0][0])) {
                $allowances[$feature] = in_array(true, array_column($values, 0), true);
            } elseif (in_array(null, array_column($values, 0), true)) {
                $allowances[$feature] = null;
            } else {
                $total = 0;
                foreach ($values as [$value, $quantity]) {
                    // PHP promotes overflowing integers to floats; reject before arithmetic.
                    if ($value > intdiv(PHP_INT_MAX, $quantity)) {
                        return BillingDecision::invalid('allowance_overflow');
                    }
                    $amount = $value * $quantity;
                    if ($total > PHP_INT_MAX - $amount) {
                        return BillingDecision::invalid('allowance_overflow');
                    }
                    $total += $amount;
                }
                $allowances[$feature] = $total;
            }
        }
        ksort($allowances);

        return BillingDecision::allowed('mapped', $until, $allowances, $base->planKey);
    }

    private function catalogError(PriceCatalog $catalog): ?string
    {
        if (trim($catalog->version) === '') {
            return 'invalid_catalog';
        }
        $types = [];
        $error = $this->allowanceError($catalog->freeAllowances, $types);
        if ($error !== null) {
            return $error;
        }
        foreach ($catalog->prices as $price => $mapping) {
            if (! is_string($price) || trim($price) === '' || ! $mapping instanceof PriceMapping || trim($mapping->planKey) === '') {
                return 'invalid_catalog';
            }
            if (($error = $this->allowanceError($mapping->allowances, $types)) !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $allowances
     * @param  array<string, string>  $types
     */
    private function allowanceError(array $allowances, array &$types): ?string
    {
        foreach ($allowances as $feature => $value) {
            if (! is_string($feature) || trim($feature) === ''
                || (! is_bool($value) && ! is_int($value) && $value !== null)
                || (is_int($value) && $value < 0)) {
                return 'invalid_allowance';
            }
            $type = is_bool($value) ? 'boolean' : 'numeric';
            if (isset($types[$feature]) && $types[$feature] !== $type) {
                return 'feature_type_mismatch';
            }
            $types[$feature] = $type;
        }

        return null;
    }
}
