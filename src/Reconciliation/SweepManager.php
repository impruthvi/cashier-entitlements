<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Reconciliation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Persistence\AuditRunStore;
use Impruthvi\CashierEntitlements\Persistence\NativeStateStore;
use Impruthvi\CashierEntitlements\Resolution\FreshnessPolicy;

/**
 * Bounded, resumable account scan that requests a refresh for stale owners.
 *
 * Webhooks repair an owner the provider told us about. This repairs the owners it did not:
 * Stripe documents unordered delivery, so only re-reading current resources can discover a
 * notification that never arrived. The scan requests work; the existing queue, fencing and
 * apply path do the rest, so a sweep never writes grants itself.
 *
 * Owners with outstanding work are skipped, because `RefreshManager::recover()` already owns
 * re-delivery for them. A scan that stops early keeps its cursor and stays incomplete; an
 * incomplete scan proves nothing about the owners it did not reach.
 */
final readonly class SweepManager
{
    public function __construct(private NativeStateStore $store, private AuditRunStore $runs,
        private OwnerLocator $owners, private RefreshManager $refresh, private FreshnessPolicy $freshness) {}

    /**
     * @param  string|null  $resume  An explicit run id, or null to continue this scope's unfinished scan.
     * @return array<string, mixed>
     */
    public function run(string $alias, int $limit = 100, ?int $staleAfter = null, ?string $resume = null): array
    {
        if (config('cashier-entitlements.enabled') !== true) {
            throw new ReadFailure('application_disabled');
        }
        if ($limit < 1 || $limit > 1000) {
            throw new ReadFailure('invalid_sweep_limit');
        }
        $threshold = $staleAfter ?? $this->freshness->maxStaleAgeSeconds;
        if ($threshold === null) {
            // Under retain_last_known there is no age at which state expires by itself, so
            // an operator must say what "stale" means rather than have the sweep guess.
            throw new ReadFailure('sweep_requires_stale_age');
        }
        if ($threshold < 1) {
            throw new ReadFailure('invalid_stale_age');
        }
        $class = $this->owners->modelFor($alias);
        $at = Date::now()->toDateTimeImmutable();
        $id = $resume ?? $this->runs->unfinished($alias) ?? $this->runs->open($alias, $at);
        $run = $this->runs->claim($id, $alias, $at);
        if ($run['completed_at'] !== null) {
            return $this->report($id, $alias, $run, true);
        }

        $model = new $class;
        $key = $model->getKeyName();
        $query = $model->newQuery()->orderBy($key)->limit($limit);
        if ($run['cursor'] !== null) {
            $query->where($key, '>', $run['cursor']);
        }
        $batch = $query->get();
        $cursor = $run['cursor'];
        $examined = $run['examined'];
        $requested = $run['requested'];
        $failed = $run['failed'];
        $lastError = $run['last_error'];
        foreach ($batch as $owner) {
            $examined++;
            $cursor = (string) $owner->getKey();
            try {
                if ($this->due($owner, $threshold, $at->getTimestamp())) {
                    $this->refresh->request($owner);
                    $requested++;
                }
            } catch (\Throwable $exception) {
                // One unmappable owner must not end the scan; it is counted and reported.
                $failed++;
                $lastError = $exception instanceof ReadFailure ? $exception->getMessage() : 'sweep_owner_failed';
            }
        }
        $complete = $batch->count() < $limit;
        $done = Date::now()->toDateTimeImmutable();
        $this->runs->progress($id, $cursor, $examined, $requested, $failed, $lastError, $done, $complete);

        return $this->report($id, $alias, ['cursor' => $cursor, 'examined' => $examined, 'requested' => $requested,
            'failed' => $failed, 'last_error' => $lastError], $complete);
    }

    private function due(Model $owner, int $threshold, int $now): bool
    {
        $state = $this->store->state($this->owners->reference($owner));
        if ($state === null) {
            return true;
        }
        if ($state['requested_sequence'] > $state['completed_sequence']) {
            return false;
        }

        return $state['observed_at'] === null || $now - (int) $state['observed_at'] >= $threshold;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function report(string $id, string $alias, array $run, bool $complete): array
    {
        return ['run' => $id, 'scope' => $alias, 'complete' => $complete, 'cursor' => $run['cursor'],
            'examined' => (int) $run['examined'], 'requested' => (int) $run['requested'],
            'failed' => (int) $run['failed'], 'last_error' => $run['last_error']];
    }
}
