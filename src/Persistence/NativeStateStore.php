<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Persistence;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Impruthvi\CashierEntitlements\Billing\BillingDecision;
use Impruthvi\CashierEntitlements\Billing\DecisionStatus;
use Impruthvi\CashierEntitlements\Billing\OwnerReference;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;

/** Native driver and synchronization metadata share one row/connection/transaction. */
final readonly class NativeStateStore
{
    public function __construct(private Connection $connection) {}

    public function request(OwnerReference $owner, DateTimeImmutable $at, ?string $eventId = null): bool
    {
        $identity = $this->identity($owner);
        if ($eventId !== null && (trim($eventId) === '' || strlen($eventId) > 255)) {
            throw new ReadFailure('invalid_event_id');
        }

        return $this->connection->transaction(function () use ($owner, $identity, $at, $eventId): bool {
            $id = hash('sha256', $identity);
            $this->connection->table('cashier_entitlement_states')->upsert(
                [['id' => $id, 'owner_identity' => $identity]], ['id'], ['id']);
            $row = $this->row($owner)->lockForUpdate()->first();
            if ($row === null || $row->owner_identity !== $identity) {
                throw new ReadFailure('owner_identity_conflict');
            }
            if ($eventId !== null) {
                $receiptId = hash('sha256', json_encode([$owner->providerContext, $owner->liveMode, $eventId], JSON_THROW_ON_ERROR));
                $receipts = $this->connection->table('cashier_entitlement_receipts');
                $inserted = $receipts->insertOrIgnore(['id' => $receiptId, 'owner_id' => $id,
                    'event_id' => $eventId, 'received_at' => $at->getTimestamp()]);
                if (! $inserted) {
                    $receipt = $receipts->where('id', $receiptId)->first();
                    if ($receipt === null || $receipt->owner_id !== $id || $receipt->event_id !== $eventId) {
                        throw new ReadFailure('receipt_owner_conflict');
                    }

                    return false;
                }
            }
            $this->row($owner)->update(['requested_sequence' => $this->next((int) $row->requested_sequence),
                'requested_at' => $at->getTimestamp(), 'retry_at' => 0]);

            return true;
        }, 3);
    }

    public function claim(OwnerReference $owner, DateTimeImmutable $at, int $leaseSeconds = 300): ?RefreshClaim
    {
        if ($leaseSeconds < 1 || $leaseSeconds > 86400) {
            throw new ReadFailure('invalid_lease');
        }

        return $this->connection->transaction(function () use ($owner, $at, $leaseSeconds): ?RefreshClaim {
            $row = $this->row($owner)->lockForUpdate()->first();
            if ($row === null || $row->requested_sequence <= $row->completed_sequence
                || $row->lease_until > $at->getTimestamp() || $row->retry_at > $at->getTimestamp()) {
                return null;
            }
            $generation = $this->next((int) $row->generation);
            $this->row($owner)->update(['generation' => $generation, 'lease_until' => $at->getTimestamp() + $leaseSeconds]);

            return new RefreshClaim($owner, $generation, (int) $row->requested_sequence);
        }, 3);
    }

    /** @param list<array<string, mixed>> $observations Normalized facts only, never provider payloads. */
    public function complete(RefreshClaim $claim, BillingDecision $decision, string $catalogVersion,
        DateTimeImmutable $observedAt, DateTimeImmutable $at, array $observations = []): bool
    {
        if ($decision->status === DecisionStatus::Invalid || trim($catalogVersion) === '' || strlen($catalogVersion) > 255 || $observedAt > $at) {
            throw new ReadFailure('invalid_application');
        }
        $allowances = $decision->allowances;
        ksort($allowances);
        $projection = json_encode(['status' => $decision->status->value, 'allowances' => $allowances,
            'paid' => $decision->status === DecisionStatus::Allowed && $decision->reason !== 'free_plan',
            'plan_key' => $decision->planKey, 'valid_until' => $decision->validUntil?->format('U.u')], JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $catalogVersion.'\0'.$projection);

        return $this->connection->transaction(function () use ($claim, $catalogVersion, $projection, $hash, $observedAt, $at, $observations): bool {
            $row = $this->row($claim->owner)->lockForUpdate()->first();
            if ($row === null || (int) $row->generation !== $claim->generation || $row->lease_until <= $at->getTimestamp()) {
                return false;
            }
            if ((int) $row->requested_sequence !== $claim->sequence) {
                $this->row($claim->owner)->update(['lease_until' => 0]);

                return false;
            }
            $this->row($claim->owner)->update(['projection' => $projection, 'applied_hash' => $hash,
                'catalog_version' => $catalogVersion,
                'applied_version' => $row->applied_hash === $hash ? (int) $row->applied_version : $this->next((int) $row->applied_version),
                'completed_sequence' => $claim->sequence, 'lease_until' => 0, 'retry_at' => 0,
                'observed_at' => $observedAt->getTimestamp(), 'last_success_at' => $at->getTimestamp(), 'last_error' => null,
                'observations' => json_encode($observations, JSON_THROW_ON_ERROR)]);

            return true;
        }, 3);
    }

    public function fail(RefreshClaim $claim, string $reason, DateTimeImmutable $at): void
    {
        if (! preg_match('/^[a-z_]{1,100}$/D', $reason)) {
            $reason = 'refresh_failed';
        }
        $this->row($claim->owner)->where('generation', $claim->generation)->where('completed_sequence', '<', $claim->sequence)->update([
            'lease_until' => 0, 'retry_at' => $at->getTimestamp() + 60, 'last_error' => $reason]);
    }

    /** @return array<string, mixed>|null */
    public function state(OwnerReference $owner): ?array
    {
        $row = $this->row($owner)->first();
        if ($row === null) {
            return null;
        }
        if ($row->owner_identity !== $this->identity($owner)) {
            throw new ReadFailure('owner_identity_conflict');
        }
        $projection = $row->projection === null ? [] : json_decode($row->projection, true, flags: JSON_THROW_ON_ERROR);

        return [...(array) $row, 'requested_sequence' => (int) $row->requested_sequence,
            'completed_sequence' => (int) $row->completed_sequence, 'applied_version' => (int) $row->applied_version,
            'allowances' => $projection['allowances'] ?? [], 'projection' => $projection];
    }

    /** @return list<OwnerReference> */
    public function pending(DateTimeImmutable $at, int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new ReadFailure('invalid_pending_limit');
        }

        return array_values($this->connection->table('cashier_entitlement_states')
            ->whereColumn('requested_sequence', '>', 'completed_sequence')->where('lease_until', '<=', $at->getTimestamp())
            ->where('retry_at', '<=', $at->getTimestamp())->orderBy('requested_at')->orderBy('id')->limit($limit)
            ->get()->map(function (object $row): OwnerReference {
                $identity = json_decode($row->owner_identity, true, flags: JSON_THROW_ON_ERROR);

                return new OwnerReference(...$identity);
            })->all());
    }

    private function row(OwnerReference $owner): Builder
    {
        return $this->connection->table('cashier_entitlement_states')->where('id', hash('sha256', $this->identity($owner)));
    }

    private function identity(OwnerReference $owner): string
    {
        if (! $owner->isValid() || $owner->connection !== $this->connection->getName()) {
            throw new ReadFailure('state_connection_mismatch');
        }

        return json_encode(get_object_vars($owner), JSON_THROW_ON_ERROR);
    }

    private function next(int $value): int
    {
        if ($value === PHP_INT_MAX) {
            throw new ReadFailure('sequence_overflow');
        }

        return $value + 1;
    }
}
