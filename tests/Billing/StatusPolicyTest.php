<?php

declare(strict_types=1);

use Impruthvi\CashierEntitlements\Billing\DecisionStatus;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\StatusPolicy;
use Impruthvi\CashierEntitlements\Tests\Support\BillingFixtures as F;

it('allows active billing facts with an explicit evaluation time', function () {
    $result = (new StatusPolicy)->evaluate(F::subscription(), F::at());

    expect($result->status)->toBe(DecisionStatus::Allowed)
        ->and($result->reason)->toBe('status_active')
        ->and($result->validUntil)->toBeNull();
});

it('denies terminal and default payment-failure states regardless of future dates', function (string $status, ?string $end) {
    $facts = F::subscription([
        'status' => $status,
        'trialEndsAt' => F::at()->modify('+1 day'),
        'scheduledEndsAt' => $end === null ? null : F::at()->modify($end),
    ]);

    expect((new StatusPolicy)->evaluate($facts, F::at())->status)->toBe(DecisionStatus::Denied);
})->with(['canceled', 'incomplete_expired', 'unpaid', 'paused', 'past_due', 'incomplete'])
    ->with([null, '-1 second', '+1 day']);

it('evaluates trial and scheduled ends as exclusive boundaries', function (string $status, string $boundary, DecisionStatus $expected) {
    $end = F::at()->modify($boundary);
    $facts = F::subscription($status === 'trialing'
        ? ['status' => $status, 'trialEndsAt' => $end]
        : ['scheduledEndsAt' => $end]);
    $result = (new StatusPolicy)->evaluate($facts, F::at());

    expect($result->status)->toBe($expected)
        ->and($result->validUntil)->toEqual($expected === DecisionStatus::Allowed ? $end : null);
})->with(['active', 'trialing'])->with([
    ['-1 microsecond', DecisionStatus::Denied],
    ['+0 seconds', DecisionStatus::Denied],
    ['+1 microsecond', DecisionStatus::Allowed],
]);

it('allows payment-failure states only when individually opted in and before a scheduled end', function (string $status) {
    $policy = new StatusPolicy(allowPastDue: $status === 'past_due', allowIncomplete: $status === 'incomplete');
    $facts = F::subscription(['status' => $status, 'scheduledEndsAt' => F::at()->modify('+1 day')]);
    expect($policy->evaluate($facts, F::at())->status)->toBe(DecisionStatus::Allowed)
        ->and($policy->evaluate($facts, F::at())->validUntil)->toEqual(F::at()->modify('+1 day'))
        ->and($policy->evaluate($facts, F::at()->modify('+1 day'))->status)->toBe(DecisionStatus::Denied);
})->with(['past_due', 'incomplete']);

it('never allows a terminal status even with both payment options enabled', function (string $status) {
    expect((new StatusPolicy(true, true))->evaluate(F::subscription(['status' => $status]), F::at())->status)
        ->toBe(DecisionStatus::Denied);
})->with(['canceled', 'incomplete_expired', 'unpaid', 'paused']);

it('keeps past-due and incomplete opt-ins independent', function () {
    expect((new StatusPolicy(allowPastDue: true))->evaluate(F::subscription(['status' => 'incomplete']), F::at())->status)
        ->toBe(DecisionStatus::Denied)
        ->and((new StatusPolicy(allowIncomplete: true))->evaluate(F::subscription(['status' => 'past_due']), F::at())->status)
        ->toBe(DecisionStatus::Denied);
});

it('compares instants rather than timezone labels and does not mutate facts', function () {
    $end = new DateTimeImmutable('2026-09-10T17:30:00+05:30');
    $facts = F::subscription(['scheduledEndsAt' => $end]);
    expect((new StatusPolicy)->evaluate($facts, F::at())->status)->toBe(DecisionStatus::Denied)
        ->and($facts->scheduledEndsAt->format(DATE_ATOM))->toBe('2026-09-10T17:30:00+05:30');
});

it('caps a trial at the earlier of trial and scheduled end', function () {
    $result = (new StatusPolicy)->evaluate(F::subscription([
        'status' => 'trialing', 'trialEndsAt' => F::at()->modify('+2 days'),
        'scheduledEndsAt' => F::at()->modify('+1 day'),
    ]), F::at());
    expect($result->validUntil)->toEqual(F::at()->modify('+1 day'));
});

it('requires an end for a trial instead of creating unlimited trial access', function () {
    $result = (new StatusPolicy)->evaluate(F::subscription(['status' => 'trialing']), F::at());
    expect($result->status)->toBe(DecisionStatus::Invalid)->and($result->reason)->toBe('missing_trial_end');
});

it('returns invalid for unsupported or malformed billing facts', function (array $overrides, string $reason) {
    $result = (new StatusPolicy)->evaluate(F::subscription($overrides), F::at());
    expect($result->status)->toBe(DecisionStatus::Invalid)
        ->and($result->reason)->toBe($reason)
        ->and($result->allowances)->toBe([]);
})->with([
    [['status' => 'future_status'], 'unknown_status'],
    [['status' => ''], 'unknown_status'],
    [['id' => ''], 'invalid_subscription_identity'],
    [['customerId' => ' '], 'invalid_subscription_identity'],
    [['type' => ''], 'invalid_subscription_identity'],
    [['owner' => new OwnerReference('', 42)], 'invalid_owner'],
    [['owner' => new OwnerReference('organization', '')], 'invalid_owner'],
    [['owner' => new OwnerReference('organization', 42, connection: '')], 'invalid_owner'],
    [['owner' => new OwnerReference('organization', 42, providerContext: '')], 'invalid_owner'],
    [['observedAt' => F::at()->modify('+1 second')], 'future_observation'],
]);
