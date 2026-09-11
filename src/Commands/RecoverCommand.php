<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Commands;

use Illuminate\Console\Command;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;
use Impruthvi\CashierEntitlements\Reconciliation\RefreshManager;

final class RecoverCommand extends Command
{
    protected $signature = 'entitlements:recover {--limit=100} {--json}';

    protected $description = 'Re-enqueue pending entitlement refreshes, including abandoned claims';

    public function handle(): int
    {
        try {
            $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
            if ($limit === false) {
                throw new ReadFailure('invalid_pending_limit');
            }
            $count = $this->laravel->make(RefreshManager::class)->recover($limit);
            $report = ['schema_version' => 1, 'queued' => $count, 'errors' => [], 'exit_code' => 0];
        } catch (\Throwable $exception) {
            $report = ['schema_version' => 1, 'queued' => null,
                'errors' => [$exception instanceof ReadFailure ? $exception->getMessage() : 'recovery_failed'], 'exit_code' => 2];
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['exit_code'];
    }
}
