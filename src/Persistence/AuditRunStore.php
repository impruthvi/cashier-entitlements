<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Persistence;

use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;

/**
 * Durable progress for a bounded account scan.
 *
 * A run is complete only when its scope was exhausted. An interrupted run keeps its cursor
 * so the next pass resumes, and it never claims to have proved anything about the owners it
 * did not reach.
 */
final readonly class AuditRunStore
{
    public function __construct(private Connection $connection) {}

    public function open(string $scope, DateTimeImmutable $at): string
    {
        if (trim($scope) === '' || strlen($scope) > 255) {
            throw new ReadFailure('invalid_audit_scope');
        }
        $id = hash('sha256', json_encode([$scope, $at->format('U.u'), bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR));
        $this->runs()->insert(['id' => $id, 'scope' => $scope, 'cursor' => null,
            'started_at' => $at->getTimestamp(), 'heartbeat_at' => $at->getTimestamp()]);

        return $id;
    }

    /** @return array<string, mixed> */
    public function claim(string $id, string $scope, DateTimeImmutable $at): array
    {
        $run = $this->runs()->where('id', $id)->first();
        if ($run === null || $run->scope !== $scope) {
            throw new ReadFailure('unknown_audit_run');
        }
        $this->runs()->where('id', $id)->update(['heartbeat_at' => $at->getTimestamp()]);

        return $this->normalize((array) $run);
    }

    /** A heartbeat proves the scan is alive; a stalled run is visible instead of silently lost. */
    public function progress(string $id, ?string $cursor, int $examined, int $requested, int $failed,
        ?string $lastError, DateTimeImmutable $at, bool $complete): void
    {
        $this->runs()->where('id', $id)->update(['cursor' => $cursor, 'examined' => $examined,
            'requested' => $requested, 'failed' => $failed, 'last_error' => $lastError,
            'heartbeat_at' => $at->getTimestamp(), 'completed_at' => $complete ? $at->getTimestamp() : null]);
    }

    /** @return array<string, mixed>|null The newest run for a scope, complete or not. */
    public function latest(string $scope): ?array
    {
        $run = $this->runs()->where('scope', $scope)->orderByDesc('started_at')->orderByDesc('id')->first();

        return $run === null ? null : $this->normalize((array) $run);
    }

    /** Continue the oldest unfinished scan in this scope before starting another one. */
    public function unfinished(string $scope): ?string
    {
        return $this->runs()->where('scope', $scope)->whereNull('completed_at')
            ->orderBy('started_at')->orderBy('id')->value('id');
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function normalize(array $run): array
    {
        foreach (['started_at', 'heartbeat_at', 'examined', 'requested', 'failed'] as $field) {
            $run[$field] = (int) $run[$field];
        }
        $run['completed_at'] = $run['completed_at'] === null ? null : (int) $run['completed_at'];

        return $run;
    }

    private function runs(): Builder
    {
        return $this->connection->table('cashier_entitlement_audit_runs');
    }
}
