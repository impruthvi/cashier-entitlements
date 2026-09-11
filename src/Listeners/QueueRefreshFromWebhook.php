<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Reconciliation\RefreshManager;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;

final class QueueRefreshFromWebhook
{
    public function handle(WebhookHandled $event): void
    {
        if (config('cashier-entitlements.enabled') !== true) {
            return;
        }
        $payload = $event->payload;
        if (! in_array($payload['type'] ?? null, ['customer.subscription.created', 'customer.subscription.updated',
            'customer.subscription.deleted', 'invoice.payment_succeeded', 'invoice.payment_failed'], true)) {
            return;
        }
        $request = app('request');
        $secret = config('cashier.webhook.secret');
        if (! $request instanceof Request || ! is_string($secret) || $secret === '' || $request->json()->all() !== $payload) {
            throw new ReadFailure('unverified_webhook');
        }
        try {
            (new VerifyWebhookSignature)->handle($request, fn () => null);
        } catch (\Throwable) {
            throw new ReadFailure('unverified_webhook');
        }
        if (($payload['livemode'] ?? null) !== config('cashier-entitlements.live_mode')
            || ($payload['account'] ?? 'platform') !== config('cashier-entitlements.provider_context')) {
            throw new ReadFailure('webhook_context_mismatch');
        }
        $customer = $payload['data']['object']['customer'] ?? null;
        $id = $payload['id'] ?? null;
        if (! is_string($customer) || trim($customer) === '' || ! is_string($id) || trim($id) === '') {
            throw new ReadFailure('malformed_webhook_identity');
        }
        $class = Cashier::$customerModel;
        if (! is_subclass_of($class, Model::class)) {
            throw new ReadFailure('invalid_billable');
        }
        $connection = config('cashier-entitlements.connection');
        if ($connection !== null && ! is_string($connection)) {
            throw new ReadFailure('invalid_state_connection');
        }
        $owners = (new $class)->setConnection($connection)->newQuery()->where('stripe_id', $customer)->limit(2)->get();
        if ($owners->isEmpty()) {
            Log::warning('cashier_entitlements_unknown_customer', ['event_id' => $id]);

            return;
        }
        if ($owners->count() !== 1) {
            throw new ReadFailure('ambiguous_customer');
        }
        app(RefreshManager::class)->request($owners->first(), $id);
    }
}
