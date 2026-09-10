<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Tests\Support;

use LucaLongo\LaravelEntitlements\Contracts\EntitlementStrategy;
use LucaLongo\LaravelEntitlements\Contracts\EntitlementType;
use LucaLongo\LaravelEntitlements\Strategies\BooleanStrategy;
use LucaLongo\LaravelEntitlements\Strategies\SlotStrategy;

enum LicenseType: string implements EntitlementType
{
    case Projects = 'projects';
    case Ai = 'ai';

    public function strategy(): EntitlementStrategy
    {
        return match ($this) {
            self::Projects => new SlotStrategy,
            self::Ai => new BooleanStrategy,
        };
    }
}
