<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Impruthvi\CashierEntitlements\Tests\Support\ScheduledTestCase;

pest()->extend(ScheduledTestCase::class);

function scheduled(): array
{
    $events = [];
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains($event->command ?? '', 'entitlements:')) {
            $events[] = [preg_replace('/^.*(entitlements:[a-z]+).*$/s', '$1', $event->command), $event->expression];
        }
    }

    return $events;
}

it('registers sweep and recovery on the scheduler from a complete configuration', function () {
    ScheduledTestCase::$schedule = ['owner_type' => 'organization', 'sweep' => '0 * * * *',
        'recover' => '*/5 * * * *', 'sweep_limit' => 50, 'stale_after' => 900];
    $this->refreshApplication();

    expect(scheduled())->toBe([['entitlements:sweep', '0 * * * *'], ['entitlements:recover', '*/5 * * * *']]);
    $sweep = app(Schedule::class)->events()[0];
    expect($sweep->command)->toContain("--owner-type='organization'")
        ->and($sweep->command)->toContain('--limit=50')
        ->and($sweep->command)->toContain('--stale-after=900')
        // A value-less option would compile to --json='1', which the command refuses to parse.
        ->and($sweep->command)->not->toContain('--json')
        // Overlapping scans would fight over one cursor and duplicate provider reads.
        ->and($sweep->withoutOverlapping)->toBeTrue();
});

it('boots without registering anything when the schedule cannot be used', function (array $schedule) {
    ScheduledTestCase::$schedule = $schedule;
    $this->refreshApplication();

    expect(scheduled())->toBe([]);
})->with([
    'nothing scheduled' => [['owner_type' => 'organization']],
    'no owner type' => [['sweep' => '0 * * * *']],
    'bad expression' => [['owner_type' => 'organization', 'sweep' => 'hourly']],
    'bad limit' => [['owner_type' => 'organization', 'sweep' => '0 * * * *', 'sweep_limit' => 0]],
    'not an array' => [[]],
]);

afterEach(fn () => ScheduledTestCase::$schedule = []);
