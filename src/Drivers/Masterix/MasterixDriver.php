<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Drivers\Masterix;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\DecisionStatus;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Drivers\EntitlementDriver;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use LucaLongo\LaravelEntitlements\Enums\PlanTransitionMode;
use LucaLongo\LaravelEntitlements\Facades\Entitlements;
use LucaLongo\LaravelEntitlements\Models\License;
use LucaLongo\LaravelEntitlements\Models\Plan;
use LucaLongo\LaravelEntitlements\Support\EntitlementModels;

/**
 * Projects a billing decision onto Masterix plan assignments.
 *
 * Supported: assigning one plan per owner, transitioning it, and closing it. This package
 * owns exactly the licence group recorded in its own binding row, so assignment retries are
 * idempotent and unrelated groups belonging to the same owner are never touched. Usage stays
 * with the native ledger; this driver never calls `consume`, whose repeats are not idempotent.
 *
 * Refused rather than approximated: a transition Masterix rejects, most importantly a
 * downgrade below current usage, aborts the refresh so the previous higher grant stays and
 * nothing is recorded as applied.
 */
final readonly class MasterixDriver implements EntitlementDriver
{
    /** @param array<string, int|string> $plans Application plan key => Masterix plan key. */
    public function __construct(private Connection $connection, private NativeStateStore $store, private array $plans) {}

    public function apply(Model $subscriber, OwnerReference $owner, BillingDecision $decision, DateTimeImmutable $at): void
    {
        if ($subscriber->getConnection()->getName() !== $this->connection->getName()
            || $this->store->database($owner) !== $this->connection) {
            throw new ReadFailure('masterix_connection_mismatch');
        }
        // Upstream's published schema declares `morphs('subscriber')`, so a UUID owner has nowhere to bind.
        $key = $subscriber->getKey();
        if (! is_int($key) && ! (is_string($key) && ctype_digit($key))) {
            throw new ReadFailure('masterix_unsupported_owner_key');
        }
        $id = hash('sha256', json_encode([$this->store->ownerId($owner), 'masterix'], JSON_THROW_ON_ERROR));
        $binding = $this->bindings()->where('id', $id)->lockForUpdate()->first();
        $plan = $this->plan($decision);

        if ($plan === null) {
            if ($binding !== null) {
                $this->close($subscriber, (string) $binding->external_group_id, $at);
                $this->bindings()->where('id', $id)->delete();
            }

            return;
        }
        if ($binding !== null && (string) $binding->external_plan_id === (string) $plan->getKey()) {
            // Upstream assignment is additive, so an unchanged decision must never re-assign.
            $this->bindings()->where('id', $id)->update(['plan_key' => (string) $decision->planKey, 'bound_at' => $this->time($at)]);

            return;
        }
        $group = $binding === null
            ? $this->assign($subscriber, $plan, $at)
            : $this->transition($subscriber, (string) $binding->external_group_id, $plan);
        $row = ['owner_id' => $this->store->ownerId($owner), 'driver' => 'masterix', 'external_group_id' => $group,
            'external_plan_id' => (string) $plan->getKey(), 'plan_key' => (string) $decision->planKey, 'bound_at' => $this->time($at)];
        $this->bindings()->upsert([['id' => $id, ...$row]], ['id'], array_keys($row));
    }

    private function assign(Model $subscriber, Plan $plan, DateTimeImmutable $at): string
    {
        $anchor = Entitlements::assignPlan($subscriber, $plan, Date::instance($at))->first();
        if ($anchor === null) {
            throw new ReadFailure('masterix_plan_without_items');
        }

        return (string) $anchor->getKey();
    }

    private function transition(Model $subscriber, string $group, Plan $plan): string
    {
        $anchor = $this->group($subscriber)->whereKey($group)->first();
        if ($anchor === null) {
            throw new ReadFailure('masterix_binding_missing');
        }
        // Any upstream rejection, including a downgrade below usage, propagates and aborts the refresh.
        $transition = Entitlements::changePlan($anchor, $plan, PlanTransitionMode::Immediate);
        if ($transition->new_anchor_license_id === null) {
            throw new ReadFailure('masterix_transition_incomplete');
        }

        return (string) $transition->new_anchor_license_id;
    }

    /** Upstream has no close-assignment API, so end exactly the bound group by its own validity column. */
    private function close(Model $subscriber, string $group, DateTimeImmutable $at): void
    {
        $when = Date::instance($at);
        $this->group($subscriber)
            ->where(fn ($query) => $query->whereKey($group)->orWhere('parent_id', $group))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $when))
            ->update(['ends_at' => $when]);
    }

    /** @return \Illuminate\Database\Eloquent\Builder<License> */
    private function group(Model $subscriber): \Illuminate\Database\Eloquent\Builder
    {
        return EntitlementModels::license()::query()
            ->where('subscriber_type', $subscriber->getMorphClass())
            ->where('subscriber_id', $subscriber->getKey());
    }

    private function plan(BillingDecision $decision): ?Plan
    {
        if ($decision->status !== DecisionStatus::Allowed || $decision->planKey === null) {
            return null;
        }
        $external = $this->plans[$decision->planKey] ?? null;
        if ($external === null) {
            throw new ReadFailure('unmapped_masterix_plan');
        }
        $plan = EntitlementModels::plan()::query()->whereKey($external)->with('items')->first();
        if ($plan === null || $plan->is_active !== true) {
            throw new ReadFailure('unknown_masterix_plan');
        }

        return $plan;
    }

    private function bindings(): Builder
    {
        return $this->connection->table('cashier_entitlement_driver_bindings');
    }

    private function time(DateTimeImmutable $at): string
    {
        return $at->format(DATE_ATOM);
    }
}
