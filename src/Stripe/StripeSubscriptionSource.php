<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Stripe;

use DateTimeImmutable;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\SubscriptionFacts;
use Impruthvi\CashierEntitlements\Billing\SubscriptionItem;
use Impruthvi\CashierEntitlements\Reconciliation\BillingSnapshot;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;

final readonly class StripeSubscriptionSource
{
    public const API_VERSION = '2025-06-30.basil';

    private StripeClient $client;

    public function __construct(
        StripeClient $client,
        private string $providerContext = 'platform',
        private bool $liveMode = false,
        private int $maxPages = 100,
        private string $defaultSubscriptionType = 'default',
        private int $maxRecords = 10000,
    ) {
        if ($maxPages < 1 || $maxPages > 1000 || $maxRecords < 1 || $maxRecords > 100000 || trim($defaultSubscriptionType) === '') {
            throw new ReadFailure('invalid_source_configuration');
        }
        $key = $client->getApiKey();
        if ($key !== null && ! preg_match($liveMode ? '/^(sk|rk)_live_/' : '/^(sk|rk)_test_/', $key)) {
            throw new ReadFailure('provider_mode_mismatch');
        }
        // Older supported SDKs cannot expose their default account headers. Build a
        // dedicated read client from public credentials/base, never inherit context.
        $this->client = new StripeClient(['api_key' => $key, 'api_base' => $client->getApiBase(),
            'stripe_account' => $providerContext === 'platform' ? null : $providerContext,
            'stripe_version' => self::API_VERSION, 'max_network_retries' => 2]);
    }

    /** @return list<string> Complete discovery within the declared account/test-clock scope. */
    public function discoverCustomers(?string $testClock = null): array
    {
        if (! $this->liveMode && $testClock === null) {
            throw new ReadFailure('sandbox_scope_required');
        }
        if ($this->liveMode && $testClock !== null) {
            throw new ReadFailure('invalid_test_clock_scope');
        }
        $params = ['status' => 'all'];
        if ($testClock !== null) {
            $params['test_clock'] = $this->identifier($testClock);
        }
        $customers = [];
        foreach ($this->pages('subscriptions', $params) as $subscription) {
            if (($subscription['object'] ?? null) !== 'subscription' || ($subscription['livemode'] ?? null) !== $this->liveMode
                || ($testClock !== null && ($subscription['test_clock'] ?? null) !== $testClock)) {
                throw new ReadFailure('provider_identity_mismatch');
            }
            $customers[$this->identifier($subscription['customer'] ?? null)] = true;
        }

        return array_keys($customers);
    }

    public function customerMatchesScope(string $customerId, ?string $testClock): bool
    {
        try {
            $customer = $this->client->customers->retrieve($customerId, [], $this->options())->toArray();
        } catch (ApiErrorException $exception) {
            throw $this->failure($exception);
        } catch (\UnexpectedValueException) {
            throw new ReadFailure('malformed_provider_data');
        }
        if (($customer['id'] ?? null) !== $customerId || ($customer['livemode'] ?? null) !== $this->liveMode
            || ($customer['object'] ?? null) !== 'customer') {
            throw new ReadFailure('provider_identity_mismatch');
        }

        return $testClock === null || ($customer['test_clock'] ?? null) === $testClock;
    }

    /** @param list<string> $knownSubscriptionIds Local IDs to verify if absent from the list. */
    public function read(OwnerReference $owner, ?string $customerId, DateTimeImmutable $at, array $knownSubscriptionIds = []): BillingSnapshot
    {
        if (count($knownSubscriptionIds) > $this->maxRecords) {
            throw new ReadFailure('provider_record_limit');
        }
        foreach ($knownSubscriptionIds as $knownId) {
            $this->identifier($knownId);
        }
        if (! $owner->isValid() || $owner->providerContext !== $this->providerContext || $owner->liveMode !== $this->liveMode) {
            throw new ReadFailure('provider_context_mismatch');
        }
        if ($customerId === null) {
            if ($knownSubscriptionIds !== []) {
                throw new ReadFailure('missing_customer');
            }

            return new BillingSnapshot($owner, null, $at, []);
        }
        $this->identifier($customerId);
        $facts = [];
        $records = 0;
        foreach ($this->pages('subscriptions', ['customer' => $customerId, 'status' => 'all']) as $subscription) {
            $facts[] = $this->normalize($owner, $customerId, $at, $subscription, $records);
        }
        $absent = [];
        foreach (array_diff(array_unique($knownSubscriptionIds), array_column($facts, 'id')) as $id) {
            $this->identifier($id);
            try {
                $subscription = $this->client->subscriptions->retrieve($id, [], $this->options())->toArray();
            } catch (InvalidRequestException $exception) {
                if ($exception->getHttpStatus() === 404 && $exception->getStripeCode() === 'resource_missing') {
                    $absent[] = $id;

                    continue;
                }
                throw new ReadFailure('provider_unavailable');
            } catch (ApiErrorException $exception) {
                throw $this->failure($exception);
            } catch (\UnexpectedValueException) {
                throw new ReadFailure('malformed_provider_data');
            }
            if (($subscription['id'] ?? null) !== $id) {
                throw new ReadFailure('provider_identity_mismatch');
            }
            $facts[] = $this->normalize($owner, $customerId, $at, $subscription, $records);
        }

        return new BillingSnapshot($owner, $customerId, $at, $facts, $absent);
    }

    /** @param array<string, mixed> $subscription */
    private function normalize(OwnerReference $owner, string $customerId, DateTimeImmutable $at, array $subscription, int &$records): SubscriptionFacts
    {
        $this->countRecord($records);
        if (($subscription['customer'] ?? null) !== $customerId || ($subscription['livemode'] ?? null) !== $this->liveMode) {
            throw new ReadFailure('provider_identity_mismatch');
        }
        if (($subscription['object'] ?? null) !== 'subscription'
            || ! in_array($subscription['status'] ?? null, ['active', 'trialing', 'past_due', 'incomplete', 'incomplete_expired', 'canceled', 'unpaid', 'paused'], true)
            || ! is_array($subscription['metadata'] ?? null)
            || ! is_bool($subscription['cancel_at_period_end'] ?? null)
            || ! array_key_exists('cancel_at', $subscription) || ! array_key_exists('trial_end', $subscription)) {
            throw new ReadFailure('malformed_provider_data');
        }
        $type = $this->identifier($subscription['metadata']['type'] ?? $subscription['metadata']['name'] ?? $this->defaultSubscriptionType);
        $trial = $this->date($subscription['trial_end']);
        $end = $this->date($subscription['cancel_at']);
        if (($subscription['status'] === 'trialing' && $trial === null)
            || ($subscription['cancel_at_period_end'] && $end === null)) {
            throw new ReadFailure('missing_provider_boundary');
        }
        $id = $this->identifier($subscription['id'] ?? null);
        $items = [];
        foreach ($this->pages('subscriptionItems', ['subscription' => $id]) as $item) {
            $this->countRecord($records);
            $price = $item['price'] ?? null;
            if (($item['object'] ?? null) !== 'subscription_item' || ($item['subscription'] ?? null) !== $id
                || (isset($item['quantity']) && ! is_int($item['quantity']))
                || ! is_array($price) || ($price['livemode'] ?? null) !== $this->liveMode) {
                throw new ReadFailure('malformed_provider_item');
            }
            $start = $this->date($item['current_period_start'] ?? null);
            $finish = $this->date($item['current_period_end'] ?? null);
            if (($start === null) !== ($finish === null) || ($start !== null && $start >= $finish)) {
                throw new ReadFailure('malformed_provider_period');
            }
            $items[] = new SubscriptionItem(
                $this->identifier($item['id'] ?? null),
                $this->identifier($price['id'] ?? null),
                $item['quantity'] ?? null,
                $start,
                $finish,
            );
        }

        return new SubscriptionFacts($owner, $id, $customerId, $type,
            $subscription['status'], $at, $items, $trial, $end);
    }

    /**
     * Explicit SDK service calls keep pagination on a fixed endpoint, not a response URL.
     *
     * @param  array<string, mixed>  $params
     * @return \Generator<int, array<string, mixed>>
     */
    private function pages(string $service, array $params): \Generator
    {
        $seen = [];
        for ($page = 0; $page < $this->maxPages; $page++) {
            try {
                $response = $this->client->{$service}->all(['limit' => 100, ...$params], $this->options())->toArray();
            } catch (ApiErrorException $exception) {
                throw $this->failure($exception);
            } catch (\UnexpectedValueException) {
                throw new ReadFailure('malformed_provider_data');
            }
            if (($response['object'] ?? null) !== 'list' || ! is_array($response['data'] ?? null)
                || ! array_is_list($response['data']) || ! is_bool($response['has_more'] ?? null)
                || count($response['data']) > 100
                || ($response['has_more'] && $response['data'] === [])) {
                throw new ReadFailure('malformed_provider_page');
            }
            foreach ($response['data'] as $record) {
                if (! is_array($record)) {
                    throw new ReadFailure('malformed_provider_data');
                }
                $id = $this->identifier($record['id'] ?? null);
                if (isset($seen[$id])) {
                    throw new ReadFailure('duplicate_provider_id');
                }
                $seen[$id] = true;
                yield $record;
                $params['starting_after'] = $id;
            }
            if ($response['has_more'] === false) {
                return;
            }
        }
        throw new ReadFailure('provider_page_limit');
    }

    /** @return array{stripe_account?: string, stripe_version: string, max_network_retries: int} */
    private function options(): array
    {
        $options = ['stripe_version' => self::API_VERSION, 'max_network_retries' => 2];
        if ($this->providerContext !== 'platform') {
            $options['stripe_account'] = $this->providerContext;
        }

        return $options;
    }

    private function identifier(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new ReadFailure('malformed_provider_data');
        }

        return $value;
    }

    private function countRecord(int &$records): void
    {
        if (++$records > $this->maxRecords) {
            throw new ReadFailure('provider_record_limit');
        }
    }

    private function failure(ApiErrorException $exception): ReadFailure
    {
        return new ReadFailure(match (true) {
            $exception instanceof AuthenticationException, $exception instanceof PermissionException => 'provider_unauthorized',
            $exception instanceof RateLimitException => 'provider_rate_limited',
            default => 'provider_unavailable',
        });
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new ReadFailure('malformed_provider_date');
        }

        return new DateTimeImmutable('@'.$value);
    }
}
