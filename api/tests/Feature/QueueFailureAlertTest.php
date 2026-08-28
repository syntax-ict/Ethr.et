<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * A failed queued job has to say so somewhere a human will look.
 *
 * Before this listener existed a failure left nothing but a row in
 * `failed_jobs` and an entry on an admin screen nobody opens on a schedule.
 * ScanAttendanceAnomaliesJob failed on every single run for an unknown period
 * and was found only because an unrelated health check happened to report a
 * non-zero failed-job count.
 *
 * Queue failures are the class of error most likely to pass unnoticed: no user
 * is waiting on a response, and a job exhausting its retries is indistinguishable
 * from a job that was never dispatched.
 */
it('logs an error when a queued job fails', function () {
    Log::spy();

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\Jobs\ScanAttendanceAnomaliesJob');
    $job->shouldReceive('getQueue')->andReturn('attendance');
    $job->shouldReceive('attempts')->andReturn(3);
    // Horizon registers its own JobFailed listener and reaches for the job id.
    // Dispatching the real event means every registered listener runs, which is
    // the point — but it also means the mock has to satisfy all of them.
    $job->shouldReceive('getJobId')->andReturn('job-uuid-1');
    $job->shouldReceive('getRawBody')->andReturn('{}');
    $job->shouldReceive('getConnectionName')->andReturn('redis');
    $job->shouldReceive('uuid')->andReturn('job-uuid-1');

    // Dispatch the framework's own event rather than calling the closure, so the
    // test fails if the listener is ever unregistered — the registration is the
    // part that silently disappears.
    Event::dispatch(new JobFailed(
        'redis',
        $job,
        new RuntimeException('Attempted to lazy load [shift]'),
    ));

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(function (string $message, array $context) {
            return $message === 'Queued job failed'
                && $context['job'] === 'App\Jobs\ScanAttendanceAnomaliesJob'
                && $context['queue'] === 'attendance'
                && $context['connection'] === 'redis'
                && $context['attempts'] === 3
                && str_contains($context['exception'], 'lazy load');
        });
});
