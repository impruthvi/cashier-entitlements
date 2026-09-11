<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use PHPUnit\Framework\Assert;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;

final class StripeFixture implements ClientInterface
{
    public array $requests = [];

    public function __construct(private array $responses) {}

    public function client(): StripeClient
    {
        ApiRequestor::setHttpClient($this);

        return new StripeClient('sk_test_fixture_not_a_secret');
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = compact('method', 'absUrl', 'headers', 'params', 'maxNetworkRetries');
        if ($this->responses === []) {
            throw new \RuntimeException('Unexpected HTTP request');
        }
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }

        return [json_encode($response, JSON_THROW_ON_ERROR), 200, []];
    }

    public function assertReadOnly(): void
    {
        foreach ($this->requests as $request) {
            Assert::assertSame('get', $request['method']);
        }
        Assert::assertSame([], $this->responses);
    }

    public static function page(array $data, bool $more = false): array
    {
        return ['object' => 'list', 'url' => '/v1/subscriptions', 'data' => $data, 'has_more' => $more];
    }

    public static function subscription(string $id = 'sub_1', string $status = 'active'): array
    {
        return ['object' => 'subscription', 'id' => $id, 'customer' => 'cus_1', 'livemode' => false,
            'status' => $status, 'metadata' => ['type' => 'default'], 'trial_end' => null,
            'cancel_at' => null, 'cancel_at_period_end' => false, 'canceled_at' => null];
    }

    public static function item(string $id = 'si_base', string $price = 'price_base', string $subscription = 'sub_1'): array
    {
        return ['object' => 'subscription_item', 'id' => $id, 'subscription' => $subscription,
            'price' => ['id' => $price, 'object' => 'price', 'livemode' => false], 'quantity' => 1,
            'current_period_start' => 1789084800, 'current_period_end' => 1791676800];
    }
}
