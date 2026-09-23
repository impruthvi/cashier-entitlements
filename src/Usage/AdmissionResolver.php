<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Usage;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;

interface AdmissionResolver
{
    public function assertConnection(OwnerReference $owner, Connection $connection): void;

    public function limit(OwnerReference $owner, string $feature, DateTimeImmutable $at): ?int;
}
