<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Reconciliation;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\DecisionStatus;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Billing\PriceMapper;
use Impruthvi\CashierEntitlements\Billing\SubscriptionFacts;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;

final readonly class DryRunReconciler
{
    public function __construct(
        private StripeSubscriptionSource $source,
        private CashierLocalProjector $local,
        private PriceMapper $mapper = new PriceMapper,
    ) {}

    /** @return array<string, mixed> Version 1 diagnostic schema; never an applied grant. */
    public function run(Model $owner, PriceCatalog $catalog, DateTimeImmutable $at, string $subscriptionType = 'default'): array
    {
        $local = $this->local->read($owner, $at);
        $remote = $this->source->read($local->owner, $local->customerId, $at, array_column($local->subscriptions, 'id'));
        $localDecision = $this->mapper->map($local->owner, $local->subscriptions, $catalog, $at, true, $subscriptionType);
        $proposed = $this->mapper->map($remote->owner, $remote->subscriptions, $catalog, $at, true, $subscriptionType);
        $differences = [];
        $localById = array_column($local->subscriptions, null, 'id');
        foreach ($remote->subscriptions as $facts) {
            $existing = $localById[$facts->id] ?? null;
            if ($existing === null) {
                $differences[] = ['subscription_id' => $facts->id, 'kind' => $facts->status === 'incomplete_expired' ? 'expected_missing_local' : 'missing_local', 'fields' => []];

                continue;
            }
            $fields = [];
            $before = $this->comparable($existing);
            foreach ($this->comparable($facts) as $key => $value) {
                if ($before[$key] !== $value) {
                    $fields[] = $key;
                }
            }
            if ($fields !== []) {
                $differences[] = ['subscription_id' => $facts->id, 'kind' => 'changed', 'fields' => $fields];
            }
            unset($localById[$facts->id]);
        }
        foreach ($localById as $id => $facts) {
            if (! in_array($id, $remote->confirmedAbsentIds, true)) {
                throw new ReadFailure('unconfirmed_provider_absence');
            }
            $differences[] = ['subscription_id' => $id, 'kind' => 'missing_provider_confirmed', 'fields' => []];
        }
        usort($differences, fn (array $a, array $b): int => strcmp($a['subscription_id'], $b['subscription_id']));
        $errors = [];
        foreach (['local' => $localDecision, 'provider' => $proposed] as $side => $decision) {
            if ($decision->status === DecisionStatus::Invalid) {
                $errors[] = $side.':'.$decision->reason;
            }
        }
        $drift = array_filter($differences, fn (array $difference): bool => $difference['kind'] !== 'expected_missing_local');

        return ['schema_version' => 1, 'mode' => 'dry-run', 'complete' => true,
            'owner' => get_object_vars($remote->owner), 'observed_at' => $at->format(DATE_ATOM),
            'catalog_version' => $catalog->version,
            'local_decision' => $this->decision($localDecision), 'proposed_decision' => $this->decision($proposed),
            'differences' => $differences, 'errors' => $errors,
            'exit_code' => $errors !== [] ? 2 : ($drift !== [] ? 1 : 0)];
    }

    /** @return array<string, mixed> */
    private function comparable(SubscriptionFacts $facts): array
    {
        $items = [];
        foreach ($facts->items as $item) {
            $items[$item->id] = ['price' => $item->priceId, 'quantity' => $item->quantity];
        }
        ksort($items);

        // Stock Cashier does not persist item periods; unknown is not contradictory.
        return ['customer' => $facts->customerId, 'type' => $facts->type, 'status' => $facts->status,
            'trial_ends_at' => $facts->trialEndsAt?->format('U.u'),
            'scheduled_ends_at' => $facts->scheduledEndsAt?->format('U.u'), 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function decision(BillingDecision $decision): array
    {
        return ['status' => $decision->status->value, 'reason' => $decision->reason,
            'allowances' => $decision->allowances, 'plan_key' => $decision->planKey,
            'valid_until' => $decision->validUntil?->format(DATE_ATOM)];
    }
}
