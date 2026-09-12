<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Billing;

use Impruthvi\CashierEntitlements\Resolution\UnknownFeature;

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

    public function booleanFeature(string $feature): bool
    {
        $features = $this->freeAllowances;
        foreach ($this->prices as $mapping) {
            $features = [...$features, ...$mapping->allowances];
        }
        if (! array_key_exists($feature, $features)) {
            throw new UnknownFeature($feature);
        }

        return is_bool($features[$feature]);
    }
}
