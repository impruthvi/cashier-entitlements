<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Reconciliation\RefreshManager;

/** Only a registered alias and scoped key are serialized, never a model or Stripe payload. */
final class RefreshOwner implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 240;

    public function __construct(public readonly OwnerReference $owner) {}

    public function handle(RefreshManager $manager): void
    {
        $manager->refresh($this->owner);
    }
}
