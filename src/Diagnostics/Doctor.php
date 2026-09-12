<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Diagnostics;

use DateTimeImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Drivers\EntitlementDriver;
use Impruthvi\CashierEntitlements\Persistence\AuditRunStore;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Reconciliation\SchedulePlan;
use Impruthvi\CashierEntitlements\Resolution\FreshnessPolicy;
use Impruthvi\CashierEntitlements\Usage\MeterPeriods;

/**
 * Local-only health report.
 *
 * Reads configuration and committed local state. It never calls Stripe, so it answers
 * "is this installation wired correctly and converging" rather than "does the provider
 * agree", which is `entitlements:reconcile`'s question. Every field is a count, a
 * timestamp or an existing sanitized reason code, so a report is safe to paste into an
 * issue.
 */
final readonly class Doctor
{
    public function __construct(private Container $container, private Connection $connection,
        private AuditRunStore $runs) {}

    /** @return array<string, mixed> */
    public function report(DateTimeImmutable $at, ?string $scope = null, ?int $staleAfter = null): array
    {
        $checks = [];
        $freshness = $this->check($checks, 'freshness_policy', fn () => $this->container->make(FreshnessPolicy::class));
        $this->check($checks, 'meter_rules', fn () => $this->container->make(MeterPeriods::class));
        $this->check($checks, 'entitlement_driver', fn () => $this->container->make(EntitlementDriver::class));
        $catalog = $this->check($checks, 'price_catalog', function (): PriceCatalog {
            if (! $this->container->bound(PriceCatalog::class)) {
                throw new ReadFailure('catalog_not_bound');
            }

            return $this->container->make(PriceCatalog::class);
        });
        $enabled = config('cashier-entitlements.enabled') === true;
        $checks[] = ['name' => 'application_enabled', 'status' => $enabled ? 'ok' : 'warn',
            'detail' => $enabled ? 'native application is on' : 'diagnostics only; no grant is ever applied'];

        $threshold = $staleAfter ?? ($freshness instanceof FreshnessPolicy ? $freshness->maxStaleAgeSeconds : null);
        $state = $this->state($at, $threshold, $catalog instanceof PriceCatalog ? $catalog->version : null);
        foreach ([['pending', 'owners with outstanding refresh work'], ['failing', 'owners whose last refresh failed'],
            ['stale', 'owners past the freshness threshold'], ['catalog_mismatch', 'owners applied under another catalog version']] as [$field, $detail]) {
            $checks[] = ['name' => $field, 'status' => $state[$field] === 0 ? 'ok' : 'warn', 'detail' => $detail];
        }

        $plan = SchedulePlan::fromConfig(config('cashier-entitlements.schedule'));
        $checks[] = ['name' => 'scheduled_convergence', 'status' => $plan->usable() ? 'ok' : 'warn',
            'detail' => $plan->reason ?? 'sweep and recovery are registered on the scheduler'];

        $sweep = $scope === null ? null : $this->sweep($checks, $scope, $at);
        $errors = $state['errors'];
        if ($sweep !== null && $sweep['failed'] > 0) {
            // A run retains only its last reason, not per-reason counts. Report the total
            // separately rather than attributing every failed owner to that last reason.
            $errors['sweep_owner_failed'] = ($errors['sweep_owner_failed'] ?? 0) + $sweep['failed'];
        }
        ksort($errors);
        $status = array_column($checks, 'status');

        return ['schema_version' => 1, 'checks' => $checks, 'state' => $state, 'sweep' => $sweep,
            'errors' => $errors,
            'exit_code' => in_array('fail', $status, true) ? 2 : (in_array('warn', $status, true) ? 1 : 0)];
    }

    /** @return array<string, mixed> */
    private function state(DateTimeImmutable $at, ?int $threshold, ?string $catalogVersion): array
    {
        $now = $at->getTimestamp();
        $counts = ['owners' => 0, 'pending' => 0, 'failing' => 0, 'stale' => 0, 'catalog_mismatch' => 0,
            'oldest_observation_age' => null, 'threshold' => $threshold, 'errors' => []];
        foreach ($this->connection->table('cashier_entitlement_states')->orderBy('id')->cursor() as $row) {
            $counts['owners']++;
            if ((int) $row->requested_sequence > (int) $row->completed_sequence) {
                $counts['pending']++;
            }
            if ($row->last_error !== null) {
                $counts['failing']++;
                $counts['errors'][$row->last_error] = ($counts['errors'][$row->last_error] ?? 0) + 1;
            }
            if ($row->observed_at === null) {
                continue;
            }
            $age = $now - (int) $row->observed_at;
            $counts['oldest_observation_age'] = max($age, $counts['oldest_observation_age'] ?? $age);
            if ($threshold !== null && $age >= $threshold) {
                $counts['stale']++;
            }
            if ($catalogVersion !== null && $row->catalog_version !== null && $row->catalog_version !== $catalogVersion) {
                $counts['catalog_mismatch']++;
            }
        }
        ksort($counts['errors']);

        return $counts;
    }

    /**
     * @param  list<array<string, string>>  $checks
     * @return array<string, mixed>|null
     */
    private function sweep(array &$checks, string $scope, DateTimeImmutable $at): ?array
    {
        $run = $this->runs->latest($scope);
        if ($run === null) {
            $checks[] = ['name' => 'account_sweep', 'status' => 'warn',
                'detail' => 'no sweep has run for this scope, so missed notifications are undetected'];

            return null;
        }
        $complete = $run['completed_at'] !== null;
        $checks[] = ['name' => 'account_sweep', 'status' => $complete && $run['failed'] === 0 ? 'ok' : 'warn',
            'detail' => ! $complete ? 'last scan is incomplete and proves nothing about owners it did not reach'
                : ($run['failed'] > 0 ? 'last scan exhausted its scope with failed owners' : 'last scan exhausted its scope')];

        return ['run' => $run['id'], 'complete' => $complete, 'examined' => $run['examined'],
            'requested' => $run['requested'], 'failed' => $run['failed'], 'last_error' => $run['last_error'],
            'age' => $at->getTimestamp() - ($run['completed_at'] ?? $run['heartbeat_at'])];
    }

    /**
     * @template T
     *
     * @param  list<array<string, string>>  $checks
     * @param  callable(): T  $resolve
     * @return T|null
     */
    private function check(array &$checks, string $name, callable $resolve): mixed
    {
        try {
            $resolved = $resolve();
            $checks[] = ['name' => $name, 'status' => 'ok', 'detail' => 'resolves'];

            return $resolved;
        } catch (ReadFailure $exception) {
            $checks[] = ['name' => $name, 'status' => 'fail', 'detail' => $exception->getMessage()];
        } catch (\Throwable) {
            // A container or driver exception body can carry credentials; only the check name escapes.
            $checks[] = ['name' => $name, 'status' => 'fail', 'detail' => 'unresolvable'];
        }

        return null;
    }
}
