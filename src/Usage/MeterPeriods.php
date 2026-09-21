<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Usage;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;

final readonly class MeterPeriods
{
    /** @param array<string, string> $rules */
    public function __construct(public array $rules = []) {}

    public function period(string $feature, DateTimeImmutable $at, Connection $db, string $ownerId): UsagePeriod
    {
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        $rule = $this->rules[$feature] ?? null;
        if (is_string($rule) && str_starts_with($rule, 'billing:')) {
            $periods = $db->table('cashier_entitlement_billing_periods')->where('owner_id', $ownerId)
                ->where('price_id', substr($rule, 8))->where('period_start', '<=', $at->getTimestamp())
                ->where('period_end', '>', $at->getTimestamp())->select('period_start', 'period_end')->distinct()->limit(2)->get()->all();
            if (count($periods) !== 1) {
                throw new ReadFailure('missing_or_ambiguous_billing_period');
            }

            return new UsagePeriod(new DateTimeImmutable('@'.$periods[0]->period_start), new DateTimeImmutable('@'.$periods[0]->period_end));
        }
        if ($rule === 'lifetime') {
            return new UsagePeriod(
                new DateTimeImmutable('1970-01-01T00:00:00.000000Z'),
                new DateTimeImmutable('9999-12-31T23:59:59.999999Z'),
            );
        }
        if (! in_array($rule, ['calendar_day', 'calendar_month'], true)) {
            throw new ReadFailure('missing_meter_period');
        }
        $start = $at->setTime(0, 0);
        if ($rule === 'calendar_month') {
            $start = $start->modify('first day of this month');

            return new UsagePeriod($start, $start->modify('+1 month'));
        }

        return new UsagePeriod($start, $start->modify('+1 day'));
    }
}
