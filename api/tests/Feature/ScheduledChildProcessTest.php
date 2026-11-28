<?php

declare(strict_types=1);

use App\Console\Commands\ScheduleCatchUpCommand;
use App\Services\Observability\QueueHealth;
use App\Support\HealthSignals;
use App\Support\PhpCli;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled `Schedule::command` children on a host where PHP-FPM cannot find
 * the CLI binary (BASELINE section 25bj): which binary they run, that a
 * failing child says why, and that the failure reaches /health.
 */
beforeEach(function () {
    HealthSignals::store()->forget(ScheduleCatchUpCommand::LAST_RUN_KEY);
    HealthSignals::store()->forget('ethr:schedule:task_failures');
    Cache::store()->forget(ScheduleCatchUpCommand::LAST_RUN_KEY);
    config(['cron.php_binary' => null]);
});

afterEach(function () {
    PhpCli::useSapi(null);
    Carbon::setTestNow();
});

it('uses PHP_BINARY under the CLI and phpdbg', function () {
    PhpCli::useSapi('cli');
    expect(PhpCli::binary())->toBe(PHP_BINARY);

    PhpCli::useSapi('phpdbg');
    expect(PhpCli::binary())->toBe(PHP_BINARY);
});

it('uses the directory PHP was installed in under PHP-FPM, without probing the file system', function () {
    PhpCli::useSapi('fpm-fcgi');

    expect(PhpCli::binary())->toBe(PHP_BINDIR.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php'));
});

it('prefers the configured binary over both', function () {
    config(['cron.php_binary' => '/opt/alt-php/bin/php']);

    PhpCli::useSapi('cli');
    expect(PhpCli::binary())->toBe('/opt/alt-php/bin/php');

    PhpCli::useSapi('fpm-fcgi');
    expect(PhpCli::binary())->toBe('/opt/alt-php/bin/php');

    config(['cron.php_binary' => '  ']);
    expect(PhpCli::binary())->not->toBe('  ');
});

/** @param  callable(Schedule): mixed  $define */
function scheduleOnly(callable $define): Schedule
{
    $schedule = new Schedule;
    $define($schedule);
    app()->instance(Schedule::class, $schedule);

    return $schedule;
}

it('rewrites the PHP prefix of a command event and leaves other events alone', function () {
    config(['cron.php_binary' => '/opt/alt-php/bin/php']);

    $events = [];
    scheduleOnly(function (Schedule $s) use (&$events) {
        $events['command'] = $s->command('ethr:no-such-task')->everyMinute();
        $events['exec'] = $s->exec('echo hello')->everyMinute();
        $events['callback'] = $s->call(fn () => null)->everyMinute();
    });
    $original = $events['exec']->command;

    $this->artisan('ethr:schedule-run');

    expect($events['command']->command)->toStartWith(escapeshellarg('/opt/alt-php/bin/php').' ')
        ->and($events['command']->command)->not->toStartWith(Application::phpBinary().' ')
        ->and($events['command']->command)->toContain('ethr:no-such-task')
        ->and($events['exec']->command)->toBe($original)
        ->and($events['callback'])->toBeInstanceOf(CallbackEvent::class);
});

it('puts the tail of a failing child output in the report and the console, and cleans up', function () {
    $reported = [];
    app()->instance(
        ExceptionHandler::class,
        new class($reported) extends Handler
        {
            public function __construct(public array &$seen)
            {
                parent::__construct(app());
            }

            public function report(Throwable $e): void
            {
                $this->seen[] = $e->getMessage();
            }
        },
    );

    $before = glob(storage_path('framework/schedule').DIRECTORY_SEPARATOR.'ethr-sched-*') ?: [];
    $event = null;
    scheduleOnly(function (Schedule $s) use (&$event) {
        $event = $s->command('ethr:no-such-task')->everyMinute()->name('failing-child');
    });

    $this->artisan('ethr:schedule-run')
        ->expectsOutputToContain('failed: exit code')
        ->expectsOutputToContain('ethr:no-such-task')
        ->assertFailed();

    expect($reported)->toHaveCount(1)
        ->and($reported[0])->toContain('Scheduled task [failing-child] exited')
        ->and($reported[0])->toContain('ethr:no-such-task')
        ->and($event->output)->toBe($event->getDefaultOutput())
        ->and(glob(storage_path('framework/schedule').DIRECTORY_SEPARATOR.'ethr-sched-*') ?: [])->toBe($before);

    $failing = app(QueueHealth::class)->failingTasks();
    expect($failing)->toHaveCount(1)
        ->and($failing[0]['task'])->toBe('failing-child')
        ->and($failing[0]['exit_code'])->toBeInt()->not->toBe(0);
});

it('keeps output an event already sends somewhere', function () {
    $own = tempnam(sys_get_temp_dir(), 'own-');
    $event = null;
    scheduleOnly(function (Schedule $s) use (&$event, $own) {
        $event = $s->command('ethr:no-such-task')->everyMinute()->sendOutputTo($own);
    });

    $this->artisan('ethr:schedule-run')->assertFailed();

    expect($event->output)->toBe($own)
        ->and(file_get_contents($own))->toContain('ethr:no-such-task');
    @unlink($own);
});

it('keeps a recorded task failure as a health problem until the task succeeds, for at most 48 hours', function () {
    $health = app(QueueHealth::class);
    $health->beat();

    expect(implode(' ', $health->snapshot()['problems']))->not->toContain('scheduled task');

    $health->recordTaskFailure('ethr-backup', 255);

    $snapshot = $health->snapshot();
    expect($snapshot['status'])->toBe('unhealthy')
        ->and(implode(' ', $snapshot['problems']))->toContain('scheduled task "ethr-backup" is failing (exit 255)')
        ->and($snapshot['failing_tasks'])->toHaveCount(1)
        ->and($snapshot['failing_tasks'][0]['task'])->toBe('ethr-backup');

    Carbon::setTestNow(now()->addHours(47));
    $health->beat();
    expect(implode(' ', $health->snapshot()['problems']))->toContain('ethr-backup');

    Carbon::setTestNow(now()->addHours(2));
    $health->beat();
    expect(implode(' ', $health->snapshot()['problems']))->not->toContain('scheduled task')
        ->and($health->snapshot()['failing_tasks'])->toBe([]);
});

it('clears a task failure when the same task succeeds, and only that one', function () {
    $health = app(QueueHealth::class);
    $health->recordTaskFailure('task-a', 1);
    $health->recordTaskFailure('task-b', null);

    $health->recordTaskSuccess('task-c');
    expect($health->failingTasks())->toHaveCount(2);

    $health->recordTaskSuccess('task-a');
    expect(array_column($health->failingTasks(), 'task'))->toBe(['task-b']);

    $health->recordTaskSuccess('task-b');
    expect($health->failingTasks())->toBe([]);
});

it('ignores the old single-value failure key', function () {
    HealthSignals::store()->put('ethr:schedule:last_task_failure', [
        'task' => 'legacy', 'exit_code' => 255, 'at' => now()->toIso8601String(),
    ], 600);

    expect(app(QueueHealth::class)->failingTasks())->toBe([]);

    HealthSignals::store()->forget('ethr:schedule:last_task_failure');
});

it('clears a failing child once the same task runs and exits zero', function () {
    scheduleOnly(function (Schedule $s) {
        $s->command('ethr:no-such-task')->everyMinute()->name('flaky-child');
    });

    $this->artisan('ethr:schedule-run')->assertFailed();
    expect(array_column(app(QueueHealth::class)->failingTasks(), 'task'))->toBe(['flaky-child']);

    Carbon::setTestNow(now()->addMinutes(2));
    scheduleOnly(function (Schedule $s) {
        $s->command('list')->everyMinute()->name('flaky-child');
    });

    $this->artisan('ethr:schedule-run')->assertSuccessful();
    expect(app(QueueHealth::class)->failingTasks())->toBe([]);
});

it('names every scheduled event, so a failure never publishes a command line', function () {
    $this->artisan('schedule:list');
    $events = app(Schedule::class)->events();

    $unnamed = collect($events)
        ->filter(fn ($event) => ! is_string($event->description) || trim($event->description) === '')
        ->map(fn ($event) => $event->command ?? $event::class)
        ->values()
        ->all();

    expect(count($events))->toBeGreaterThan(5)
        ->and($unnamed)->toBe([]);
});
