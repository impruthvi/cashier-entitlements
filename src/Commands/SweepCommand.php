<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Commands;

use Illuminate\Console\Command;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Reconciliation\SweepManager;

final class SweepCommand extends Command
{
    protected $signature = 'entitlements:sweep {--owner-type=} {--limit=100} {--stale-after=} {--resume=} {--json}';

    protected $description = 'Scan an owner scope and request refreshes for owners whose observation is stale';

    public function handle(): int
    {
        try {
            $alias = $this->option('owner-type');
            if (! is_string($alias) || trim($alias) === '') {
                throw new ReadFailure('explicit_owner_type_required');
            }
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false) {
                throw new ReadFailure('invalid_sweep_limit');
            }
            $stale = $this->option('stale-after');
            if ($stale !== null) {
                $stale = filter_var($stale, FILTER_VALIDATE_INT);
                if ($stale === false) {
                    throw new ReadFailure('invalid_stale_age');
                }
            }
            $resume = $this->option('resume');
            if ($resume !== null && (! is_string($resume) || trim($resume) === '')) {
                throw new ReadFailure('invalid_audit_run');
            }
            $result = $this->laravel->make(SweepManager::class)->run($alias, $limit, $stale, $resume);
            // An incomplete scan exits non-zero so a scheduler notices it must resume.
            $report = ['schema_version' => 1, ...$result, 'errors' => [],
                'exit_code' => $result['complete'] && $result['failed'] === 0 ? 0 : 1];
        } catch (ReadFailure $exception) {
            $report = $this->errorReport($exception->getMessage());
        } catch (\Throwable) {
            $report = $this->errorReport('sweep_failed');
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return $report['exit_code'];
    }

    /** @return array<string, mixed> */
    private function errorReport(string $error): array
    {
        return ['schema_version' => 1, 'run' => null, 'complete' => false, 'cursor' => null,
            'examined' => 0, 'requested' => 0, 'failed' => 0, 'last_error' => null,
            'errors' => [$error], 'exit_code' => 2];
    }
}
