<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Impruthvi\CashierEntitlements\Diagnostics\Doctor;
use Impruthvi\CashierEntitlements\Reconciliation\ReadFailure;

final class DoctorCommand extends Command
{
    protected $signature = 'entitlements:doctor {--owner-type=} {--stale-after=} {--json}';

    protected $description = 'Report local configuration and convergence health without calling Stripe';

    public function handle(): int
    {
        try {
            $scope = $this->option('owner-type');
            if ($scope !== null && (! is_string($scope) || trim($scope) === '')) {
                throw new ReadFailure('invalid_owner_type');
            }
            $stale = $this->option('stale-after');
            if ($stale !== null) {
                $stale = filter_var($stale, FILTER_VALIDATE_INT);
                if ($stale === false || $stale < 1) {
                    throw new ReadFailure('invalid_stale_age');
                }
            }
            $report = $this->laravel->make(Doctor::class)->report(Date::now()->toDateTimeImmutable(), $scope, $stale);
        } catch (ReadFailure $exception) {
            $report = $this->errorReport($exception->getMessage());
        } catch (\Throwable) {
            $report = $this->errorReport('doctor_failed');
        }
        $this->line(json_encode($report, JSON_THROW_ON_ERROR | ($this->option('json') ? 0 : JSON_PRETTY_PRINT)));

        return $report['exit_code'];
    }

    /** @return array<string, mixed> */
    private function errorReport(string $error): array
    {
        return ['schema_version' => 1, 'checks' => [], 'state' => [], 'sweep' => null,
            'errors' => [$error], 'exit_code' => 2];
    }
}
