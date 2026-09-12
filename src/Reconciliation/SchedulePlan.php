<?php

declare(strict_types=1);

namespace Impruthvi\CashierEntitlements\Reconciliation;

use Cron\CronExpression;

/**
 * A validated reading of the convergence schedule.
 *
 * Boot registers work only from a usable plan, and the doctor reports the same reason an
 * unusable one was ignored. Both read this one interpretation, so a schedule can never be
 * silently skipped while diagnostics call it healthy.
 */
final readonly class SchedulePlan
{
    private function __construct(
        public ?string $alias = null,
        public ?string $sweep = null,
        public ?string $recover = null,
        public int $limit = 100,
        public ?int $staleAfter = null,
        public ?string $reason = null,
    ) {}

    public static function fromConfig(mixed $config): self
    {
        if (! is_array($config)) {
            return new self(reason: 'invalid_schedule');
        }
        $sweep = self::cron($config['sweep'] ?? null);
        $recover = self::cron($config['recover'] ?? null);
        $alias = $config['owner_type'] ?? null;
        if ((($config['sweep'] ?? null) !== null && $sweep === null)
            || (($config['recover'] ?? null) !== null && $recover === null)) {
            return new self(reason: 'invalid_cron_expression');
        }
        if ($sweep === null && $recover === null) {
            return new self(reason: 'no_scheduled_convergence');
        }
        if (! is_string($alias) || trim($alias) === '') {
            return new self(reason: 'schedule_requires_owner_type');
        }
        $limit = $config['sweep_limit'] ?? 100;
        $stale = $config['stale_after'] ?? null;
        if (! is_int($limit) || $limit < 1 || $limit > 1000) {
            return new self(reason: 'invalid_sweep_limit');
        }
        if ($stale !== null && (! is_int($stale) || $stale < 1)) {
            return new self(reason: 'invalid_stale_age');
        }

        return new self(trim($alias), $sweep, $recover, $limit, $stale);
    }

    public function usable(): bool
    {
        return $this->reason === null;
    }

    private static function cron(mixed $expression): ?string
    {
        return is_string($expression) && strlen($expression) <= 100 && class_exists(CronExpression::class)
            && CronExpression::isValidExpression(trim($expression))
            ? trim($expression) : null;
    }
}
