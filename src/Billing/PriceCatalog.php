<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Billing;

final readonly class PriceCatalog
{
    /**
     * @param  array<string, PriceMapping>  $prices
     * @param  array<string, bool|int|null>  $freeAllowances
     */
    public function __construct(
        public string $version,
        public array $prices,
        public array $freeAllowances = [],
        public string $providerContext = 'platform',
        public bool $liveMode = false,
    ) {}
}
