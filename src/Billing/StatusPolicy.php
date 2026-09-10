<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Billing;

use DateTimeImmutable;

final readonly class StatusPolicy
{
    public function __construct(public bool $allowPastDue = false, public bool $allowIncomplete = false) {}

    public function evaluate(SubscriptionFacts $facts, DateTimeImmutable $at): BillingDecision
    {
        if (! $facts->owner->isValid()) {
            return BillingDecision::invalid('invalid_owner');
        }
        if (trim($facts->id) === '' || trim($facts->customerId) === '' || trim($facts->type) === '') {
            return BillingDecision::invalid('invalid_subscription_identity');
        }
        if ($facts->observedAt > $at) {
            return BillingDecision::invalid('future_observation');
        }
        if (! in_array($facts->status, ['active', 'trialing', 'canceled', 'incomplete_expired', 'unpaid', 'paused', 'past_due', 'incomplete'], true)) {
            return BillingDecision::invalid('unknown_status');
        }
        if (in_array($facts->status, ['canceled', 'incomplete_expired', 'unpaid', 'paused'], true)
            || ($facts->status === 'past_due' && ! $this->allowPastDue)
            || ($facts->status === 'incomplete' && ! $this->allowIncomplete)) {
            return BillingDecision::denied('status_'.$facts->status);
        }

        if ($facts->scheduledEndsAt !== null && $facts->scheduledEndsAt <= $at) {
            return BillingDecision::denied('scheduled_end_reached');
        }

        $until = $facts->scheduledEndsAt;
        if ($facts->status === 'trialing') {
            if ($facts->trialEndsAt === null) {
                return BillingDecision::invalid('missing_trial_end');
            }
            if ($facts->trialEndsAt <= $at) {
                return BillingDecision::denied('trial_ended');
            }
            if ($until === null || $facts->trialEndsAt < $until) {
                $until = $facts->trialEndsAt;
            }
        }

        return BillingDecision::allowed('status_'.$facts->status, $until);
    }
}
