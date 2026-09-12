<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Usage;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Resolution\FeatureTypeMismatch;
use Impruthvi\CashierEntitlements\Resolution\LocalResolver;

final readonly class NativeUsage
{
    public function __construct(private NativeStateStore $store, private PriceCatalog $catalog, private MeterPeriods $periods) {}

    public function record(OwnerReference $owner, string $feature, int $quantity, string $idempotencyKey, ?DateTimeImmutable $at = null, ?DateTimeImmutable $occurredAt = null): UsageReceipt
    {
        return $this->increment($owner, $feature, $quantity, $idempotencyKey, $at, $occurredAt);
    }

    /** @param callable(Connection, UsageReceipt): void $create Domain writes must use this connection; no external side effects. */
    public function admit(OwnerReference $owner, string $feature, int $quantity, string $idempotencyKey, LocalResolver $resolver, callable $create, ?DateTimeImmutable $at = null): UsageReceipt
    {
        $resolver->assertConnection($owner, $this->store->database($owner));

        return $this->increment($owner, $feature, $quantity, $idempotencyKey, $at, null, $resolver, $create);
    }

    /** @param (callable(Connection, UsageReceipt): void)|null $create */
    private function increment(OwnerReference $owner, string $feature, int $quantity, string $idempotencyKey, ?DateTimeImmutable $at, ?DateTimeImmutable $occurredAt, ?LocalResolver $resolver = null, ?callable $create = null): UsageReceipt
    {
        $this->numeric($feature);
        if ($quantity < 1 || trim($idempotencyKey) === '' || strlen($idempotencyKey) > 255) {
            throw new \InvalidArgumentException('invalid_increment');
        }
        $payload = hash('sha256', json_encode([$quantity, $occurredAt?->format('U.u'), $resolver === null ? 'record' : 'admit'], JSON_THROW_ON_ERROR));

        return $this->store->synchronized($owner, function (Connection $db, string $ownerId) use ($owner, $feature, $quantity, $idempotencyKey, $at, $occurredAt, $payload, $resolver, $create): UsageReceipt {
            $at ??= Date::now()->toDateTimeImmutable();
            if ($occurredAt !== null && $occurredAt > $at) {
                throw new \InvalidArgumentException('future_occurrence');
            }
            $id = hash('sha256', json_encode([$ownerId, $feature, $idempotencyKey], JSON_THROW_ON_ERROR));
            $events = $db->table('cashier_entitlement_usage_events');
            $existing = (clone $events)->where('id', $id)->first();
            if ($existing !== null) {
                if ($existing->payload_hash !== $payload) {
                    throw new IdempotencyConflict('usage_payload_conflict');
                }

                return $this->receipt($existing);
            }
            $period = $this->periods->period($feature, $occurredAt ?? $at, $db, $ownerId);
            $counter = $db->table('cashier_entitlement_usage_counters')->where('id', $this->counterId($ownerId, $feature, $period));
            $current = (int) ($counter->value('total') ?? 0);
            if ($resolver !== null) {
                $limit = $resolver->for($owner, $at)->limit($feature);
                if ($limit !== null && ($current > $limit || $quantity > $limit - $current)) {
                    throw new LimitExceeded($feature);
                }
            }
            if ($quantity > PHP_INT_MAX - $current) {
                throw new \OverflowException('usage_overflow');
            }
            $total = $current + $quantity;
            $db->table('cashier_entitlement_usage_counters')->upsert([
                ['id' => $this->counterId($ownerId, $feature, $period), 'total' => $total],
            ], ['id'], ['total']);
            $events->insert(['id' => $id, 'owner_id' => $ownerId, 'feature' => $feature, 'operation_key' => $idempotencyKey,
                'payload_hash' => $payload, 'quantity' => $quantity, 'total' => $total,
                'period_start' => $period->start->format('Y-m-d\TH:i:s.uP'), 'period_end' => $period->end->format('Y-m-d\TH:i:s.uP'),
                'occurred_at' => ($occurredAt ?? $at)->format('Y-m-d\TH:i:s.uP')]);

            $receipt = new UsageReceipt($id, $period, $quantity, $total);
            if ($create !== null) {
                $create($db, $receipt);
            }

            return $receipt;
        });
    }

    public function usage(OwnerReference $owner, string $feature, DateTimeImmutable $at): int
    {
        $this->numeric($feature);
        $period = $this->periods->period($feature, $at, $this->store->database($owner), $this->store->ownerId($owner));

        return (int) ($this->store->database($owner)->table('cashier_entitlement_usage_counters')
            ->where('id', $this->counterId($this->store->ownerId($owner), $feature, $period))->value('total') ?? 0);
    }

    private function numeric(string $feature): void
    {
        if ($this->catalog->booleanFeature($feature)) {
            throw new FeatureTypeMismatch('usage_requires_numeric');
        }
    }

    private function counterId(string $ownerId, string $feature, UsagePeriod $period): string
    {
        return hash('sha256', json_encode([$ownerId, $feature, $period->start->format('U.u'), $period->end->format('U.u')], JSON_THROW_ON_ERROR));
    }

    private function receipt(\stdClass $event): UsageReceipt
    {
        return new UsageReceipt((string) $event->id, new UsagePeriod(new DateTimeImmutable((string) $event->period_start), new DateTimeImmutable((string) $event->period_end)), (int) $event->quantity, (int) $event->total);
    }
}
