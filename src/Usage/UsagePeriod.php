<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Usage;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class UsagePeriod
{
    public DateTimeImmutable $start;

    public DateTimeImmutable $end;

    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        if ($end <= $start) {
            throw new InvalidArgumentException('invalid_usage_period');
        }
        $this->start = $start->setTimezone(new DateTimeZone('UTC'));
        $this->end = $end->setTimezone(new DateTimeZone('UTC'));
    }
}
