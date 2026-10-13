<?php

declare(strict_types=1);

use App\Console\Commands\ScheduleCatchUpCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * `ethr:schedule-run` — schedule:run for a caller that does not arrive every minute.
 *
 * Production's scheduler is called over HTTP a handful of times a day at
 * arbitrary minutes (console QA F23, measured 2026-10-12). Stock schedule:run
 * runs only what is due in that minute, so every task pinned to a time never
 * ran. These tests drive the command against a schedule of their own, so no
 * real task fires.
 */

/**
 * A fresh schedule holding only the given test events, bound for the command.
 *
 * Define events with `function () use (&$counter)`, never an arrow function: an
 * arrow function captures by value, so the counter the event increments would
 * be a copy and every "did not run" assertion would pass vacuously.
 */
function catchUpSchedule(callable $define): Schedule
{
    $schedule = new Schedule;
    $define($schedule);
    app()->instance(Schedule::class, $schedule);

    return $schedule;
}

afterEach(function () {
    Carbon::setTestNow();
});

it('runs a daily task whose minute was missed, once, on the next late call', function () {
    $runs = 0;
    catchUpSchedule(function (Schedule $s) use (&$runs) {
        $s->call(function () use (&$runs) {
            $runs++;
        })->dailyAt('05:00');
    });

    // The previous call came before 05:00; the next one arrives at 07:29.
    Cache::forever(ScheduleCatchUpCommand::LAST_RUN_KEY, Carbon::parse('2026-10-12 04:50:00')->getTimestamp());
    Carbon::setTestNow('2026-10-12 07:29:18');

    $this->artisan('ethr:schedule-run')->assertSuccessful();
    expect($runs)->toBe(1);

    // A later call the same morning does not run it again.
    Carbon::setTestNow('2026-10-12 09:41:02');
    $this->artisan('ethr:schedule-run')->assertSuccessful();
    expect($runs)->toBe(1);
});

it('is exactly what stock schedule:run misses', function () {
    $runs = 0;
    catchUpSchedule(function (Schedule $s) use (&$runs) {
        $s->call(function () use (&$runs) {
            $runs++;
        })->dailyAt('05:00');
    });

    // The proof of the defect: at 07:29 the 05:00 task is not "due".
    Carbon::setTestNow('2026-10-12 07:29:18');
    $this->artisan('schedule:run');
    expect($runs)->toBe(0);

    // Control: called in the very minute, stock schedule:run does run it — so
    // the zero above is the defect, not a test that cannot count.
    Carbon::setTestNow('2026-10-12 05:00:30');
    $this->artisan('schedule:run');
    expect($runs)->toBe(1);
});

it('runs an hourly task once after a gap of several hours', function () {
    $runs = 0;
    catchUpSchedule(function (Schedule $s) use (&$runs) {
        $s->call(function () use (&$runs) {
            $runs++;
        })->hourly();
    });

    Cache::forever(ScheduleCatchUpCommand::LAST_RUN_KEY, Carbon::parse('2026-10-12 01:21:00')->getTimestamp());
    Carbon::setTestNow('2026-10-12 07:29:00');

    $this->artisan('ethr:schedule-run')->assertSuccessful();
    expect($runs)->toBe(1);
});

it('does not fire past work on its first run, only what is due now', function () {
    $daily = 0;
    $minutely = 0;
    catchUpSchedule(function (Schedule $s) use (&$daily, &$minutely) {
        $s->call(function () use (&$daily) {
            $daily++;
        })->dailyAt('05:00');
        $s->call(function () use (&$minutely) {
            $minutely++;
        })->everyMinute();
    });

    Cache::forget(ScheduleCatchUpCommand::LAST_RUN_KEY);
    Carbon::setTestNow('2026-10-12 07:29:18');

    $this->artisan('ethr:schedule-run')->assertSuccessful();

    expect($daily)->toBe(0)
        ->and($minutely)->toBe(1)
        ->and(Cache::get(ScheduleCatchUpCommand::LAST_RUN_KEY))
        ->toBe(Carbon::parse('2026-10-12 07:29:00')->getTimestamp());
});

it('runs a monthly task missed by days', function () {
    $runs = 0;
    catchUpSchedule(function (Schedule $s) use (&$runs) {
        $s->call(function () use (&$runs) {
            $runs++;
        })->monthlyOn(1, '03:00');
    });

    // Dead since 30 September; the call on 3 October still runs the 1 October task.
    Cache::forever(ScheduleCatchUpCommand::LAST_RUN_KEY, Carbon::parse('2026-09-30 12:00:00')->getTimestamp());
    Carbon::setTestNow('2026-10-03 10:00:00');

    $this->artisan('ethr:schedule-run')->assertSuccessful();
    expect($runs)->toBe(1);
});

it('does not reach back further than the window', function () {
    $runs = 0;
    catchUpSchedule(function (Schedule $s) use (&$runs) {
        $s->call(function () use (&$runs) {
            $runs++;
        })->yearlyOn(1, 1, '00:00');
    });

    // Dead since June; on 1 March the 1 January run is 59 days old, past the
    // 31-day window, so it is not resurrected.
    Cache::forever(ScheduleCatchUpCommand::LAST_RUN_KEY, Carbon::parse('2025-06-01 00:00:00')->getTimestamp());
    Carbon::setTestNow('2026-03-01 10:00:00');

    $this->artisan('ethr:schedule-run')->assertSuccessful();
    expect($runs)->toBe(0);

    // Control: inside the window the same task does run.
    Cache::forever(ScheduleCatchUpCommand::LAST_RUN_KEY, Carbon::parse('2025-12-31 00:00:00')->getTimestamp());
    Carbon::setTestNow('2026-01-20 10:00:00');
    $this->artisan('ethr:schedule-run')->assertSuccessful();
    expect($runs)->toBe(1);
});

it('keeps going when one task throws, and reports failure', function () {
    $after = 0;
    catchUpSchedule(function (Schedule $s) use (&$after) {
        $s->call(fn () => throw new RuntimeException('broken report definition'))->everyMinute();
        $s->call(function () use (&$after) {
            $after++;
        })->everyMinute();
    });

    Carbon::setTestNow('2026-10-12 07:29:00');

    $this->artisan('ethr:schedule-run')->assertFailed();
    expect($after)->toBe(1);
});
