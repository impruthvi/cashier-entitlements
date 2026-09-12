<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Commands\ReconcileCommand;
use Impruthvi\CashierEntitlements\Commands\RecoverCommand;
use Impruthvi\CashierEntitlements\Listeners\QueueRefreshFromWebhook;
use Impruthvi\CashierEntitlements\Overrides\NativeOverrides;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Reconciliation\CashierLocalProjector;
use Impruthvi\CashierEntitlements\Reconciliation\OwnerLocator;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Reconciliation\RefreshManager;
use Impruthvi\CashierEntitlements\Resolution\FreshnessPolicy;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Impruthvi\CashierEntitlements\Usage\MeterPeriods;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class CashierEntitlementsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('cashier-entitlements')->hasConfigFile()->hasCommands([ReconcileCommand::class, RecoverCommand::class])
            ->hasMigrations([
                'create_cashier_entitlements_tables',
                'create_cashier_entitlements_usage_tables',
                'create_cashier_entitlements_overrides_table',
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(NativeStateStore::class, fn () => new NativeStateStore($this->connection()));
        $this->app->bind(OwnerLocator::class, fn () => new OwnerLocator($this->connection(), $this->context(), $this->liveMode()));
        $this->app->bind(FreshnessPolicy::class, function () {
            $policy = config('cashier-entitlements.freshness', []);
            if (! is_array($policy) || (isset($policy['max_stale_age']) && ! is_int($policy['max_stale_age']))
                || (isset($policy['retain_last_known']) && ! is_bool($policy['retain_last_known']))) {
                throw new ReadFailure('invalid_freshness_policy');
            }

            return new FreshnessPolicy($policy['max_stale_age'] ?? null, $policy['retain_last_known'] ?? false);
        });
        $this->app->bind(MeterPeriods::class, fn () => new MeterPeriods($this->meters()));
        // Overrides stay opt-in so an installation that never grants one keeps a single-query resolve.
        $this->app->bind(LocalResolver::class, fn () => new LocalResolver(
            $this->app->make(NativeStateStore::class),
            $this->app->make(PriceCatalog::class),
            $this->app->make(FreshnessPolicy::class),
            $this->overridesEnabled() ? $this->app->make(NativeOverrides::class) : null,
        ));
        $this->app->when(RefreshManager::class)->needs(Connection::class)->give(fn () => $this->connection());
        $this->app->bind(StripeSubscriptionSource::class, function (): StripeSubscriptionSource {
            if (! class_exists(Cashier::class)) {
                throw new ReadFailure('cashier_not_installed');
            }

            return new StripeSubscriptionSource(Cashier::stripe(), $this->context(), $this->liveMode());
        });
        $this->app->bind(CashierLocalProjector::class, fn (): CashierLocalProjector => new CashierLocalProjector($this->context(), $this->liveMode()));
    }

    private function connection(): Connection
    {
        $name = config('cashier-entitlements.connection');
        if ($name !== null && (! is_string($name) || trim($name) === '')) {
            throw new ReadFailure('invalid_state_connection');
        }

        return DB::connection($name);
    }

    public function packageBooted(): void
    {
        if (class_exists(WebhookHandled::class)) {
            Event::listen(WebhookHandled::class, QueueRefreshFromWebhook::class);
        }
    }

    private function context(): string
    {
        $context = config('cashier-entitlements.provider_context');
        if (! is_string($context) || trim($context) === '') {
            throw new ReadFailure('invalid_provider_context');
        }

        return $context;
    }

    private function overridesEnabled(): bool
    {
        $enabled = config('cashier-entitlements.overrides', false);
        if (! is_bool($enabled)) {
            throw new ReadFailure('invalid_override_setting');
        }

        return $enabled;
    }

    /** @return array<string, string> */
    private function meters(): array
    {
        $meters = config('cashier-entitlements.meters', []);
        if (! is_array($meters)) {
            throw new ReadFailure('invalid_meter_rules');
        }
        foreach ($meters as $feature => $rule) {
            if (! is_string($feature) || ! is_string($rule)
                || (! in_array($rule, ['calendar_day', 'calendar_month'], true) && ! str_starts_with($rule, 'billing:'))
                || (str_starts_with($rule, 'billing:') && trim(substr($rule, 8)) === '')) {
                throw new ReadFailure('invalid_meter_rules');
            }
        }

        return $meters;
    }

    private function liveMode(): bool
    {
        $mode = config('cashier-entitlements.live_mode');
        if (! is_bool($mode)) {
            throw new ReadFailure('invalid_provider_mode');
        }

        return $mode;
    }
}
