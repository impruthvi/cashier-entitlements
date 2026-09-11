<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Commands;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Impruthvi\CashierEntitlements\Billing\PriceCatalog;
use Impruthvi\CashierEntitlements\Reconciliation\DryRunReconciler;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Stripe\StripeSubscriptionSource;
use Laravel\Cashier\Cashier;

final class ReconcileCommand extends Command
{
    protected $signature = 'entitlements:reconcile {--owner-type=} {--owner=} {--all} {--test-clock=} {--dry-run} {--apply} {--json}';

    protected $description = 'Compare current Stripe subscriptions with Cashier without changing access';

    public function handle(): int
    {
        try {
            if ($this->option('apply')) {
                throw new ReadFailure('apply_not_supported');
            }
            if (! class_exists(Cashier::class)) {
                throw new ReadFailure('cashier_not_installed');
            }
            $alias = $this->option('owner-type');
            $key = $this->option('owner');
            if (! is_string($alias) || (! $this->option('all') && (! is_string($key) || trim($key) === ''))) {
                throw new ReadFailure('explicit_owner_required');
            }
            $class = Relation::getMorphedModel($alias);
            if ($class === null || $class !== Cashier::$customerModel || ! is_subclass_of($class, Model::class)) {
                throw new ReadFailure('unregistered_owner_type');
            }
            $owner = null;
            if (! $this->option('all')) {
                $owner = (new $class)->newQuery()->find($key);
                if (! $owner instanceof Model || (string) $owner->getKey() !== $key) {
                    throw new ReadFailure('unknown_owner');
                }
            }
            if (! $this->laravel->bound(PriceCatalog::class)) {
                throw new ReadFailure('catalog_not_bound');
            }
            $type = config('cashier-entitlements.subscription_type', 'default');
            if (! is_string($type) || trim($type) === '') {
                throw new ReadFailure('invalid_subscription_type');
            }
            if ($this->option('all')) {
                if ($key !== null) {
                    throw new ReadFailure('conflicting_scope');
                }
                $report = $this->audit(new $class, $type);
            } else {
                if ($this->option('test-clock') !== null) {
                    throw new ReadFailure('conflicting_scope');
                }
                if (! $owner instanceof Model) {
                    throw new ReadFailure('unknown_owner');
                }
                $report = $this->laravel->make(DryRunReconciler::class)->run(
                    $owner, $this->laravel->make(PriceCatalog::class), new DateTimeImmutable, $type,
                );
            }
        } catch (ReadFailure $exception) {
            $report = $this->errorReport($exception->getMessage());
        } catch (\Throwable) {
            // Neither SQL bindings nor provider exception bodies belong in operator JSON.
            $report = $this->errorReport('diagnostic_failed');
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return $report['exit_code'];
    }

    /** @return array<string, mixed> No partial results escape on a failed account scan. */
    private function audit(Model $model, string $type): array
    {
        $clock = $this->option('test-clock');
        if ($clock !== null && (! is_string($clock) || trim($clock) === '')) {
            throw new ReadFailure('invalid_test_clock_scope');
        }
        $source = $this->laravel->make(StripeSubscriptionSource::class);
        $customers = $source->discoverCustomers($clock);
        $owners = $model->newQuery()->limit(10001)->get();
        if ($owners->count() > 10000) {
            throw new ReadFailure('audit_owner_limit');
        }
        $reports = [];
        $seen = [];
        $exit = 0;
        foreach ($owners as $owner) {
            $customer = $owner->getAttribute('stripe_id');
            if ($customer !== null && (! is_string($customer) || trim($customer) === '')) {
                throw new ReadFailure('malformed_local_data');
            }
            if ($customer !== null && isset($seen[$customer])) {
                throw new ReadFailure('ambiguous_customer');
            }
            if ($customer === null && $clock !== null) {
                continue;
            }
            if ($customer !== null && ! $source->customerMatchesScope($customer, $clock)) {
                continue;
            }
            if ($customer !== null) {
                $seen[$customer] = true;
            }
            $report = $this->laravel->make(DryRunReconciler::class)->run(
                $owner, $this->laravel->make(PriceCatalog::class), new DateTimeImmutable, $type,
            );
            $reports[] = $report;
            $exit = max($exit, $report['exit_code']);
        }
        $unknown = array_values(array_diff($customers, array_keys($seen)));
        sort($unknown);

        return ['schema_version' => 1, 'mode' => 'dry-run', 'complete' => true,
            'scope' => ['owner_type' => $model->getMorphClass(), 'test_clock' => $clock],
            'owners' => $reports, 'unknown_customers' => $unknown, 'errors' => [],
            'exit_code' => max($exit, $unknown !== [] ? 1 : 0)];
    }

    /** @return array{schema_version: int, mode: string, complete: bool, proposed_decision: null, differences: array{}, errors: list<string>, exit_code: int} */
    private function errorReport(string $error): array
    {
        return ['schema_version' => 1, 'mode' => 'dry-run', 'complete' => false,
            'proposed_decision' => null, 'differences' => [], 'errors' => [$error], 'exit_code' => 2];
    }
}
