<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Billing;

final readonly class OwnerReference
{
    public string $key;

    public function __construct(
        public string $type,
        int|string $key,
        public string $connection = 'default',
        public string $providerContext = 'platform',
        public bool $liveMode = false,
    ) {
        $this->key = (string) $key;
    }

    public function isValid(): bool
    {
        return trim($this->type) !== '' && trim($this->key) !== ''
            && trim($this->connection) !== '' && trim($this->providerContext) !== '';
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->key === $other->key
            && $this->connection === $other->connection
            && $this->providerContext === $other->providerContext
            && $this->liveMode === $other->liveMode;
    }
}
