<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements;

use Impruthvi\CashierEntitlements\Commands\ReconcileCommand;
use Impruthvi\CashierEntitlements\Reconciliation\CashierLocalProjector;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Laravel\Cashier\Cashier;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class CashierEntitlementsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('cashier-entitlements')->hasConfigFile()->hasCommand(ReconcileCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->bind(StripeSubscriptionSource::class, function (): StripeSubscriptionSource {
            if (! class_exists(Cashier::class)) {
                throw new ReadFailure('cashier_not_installed');
            }

            return new StripeSubscriptionSource(Cashier::stripe(), $this->context(), $this->liveMode());
        });
        $this->app->bind(CashierLocalProjector::class, fn (): CashierLocalProjector => new CashierLocalProjector($this->context(), $this->liveMode()));
    }

    private function context(): string
    {
        $context = config('cashier-entitlements.provider_context');
        if (! is_string($context) || trim($context) === '') {
            throw new ReadFailure('invalid_provider_context');
        }

        return $context;
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
