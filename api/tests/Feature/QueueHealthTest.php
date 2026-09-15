<?php

declare(strict_types=1);

use App\Services\Observability\QueueHealth;
use Illuminate\Support\Facades\DB;

/**
 * The dead-man's-switch has to be seen failing.
 *
 * A monitor nobody has watched fail is an assumption with a dashboard. These
 * tests drive it into each state it exists to detect: never run, gone quiet, a
 * starving queue, and healthy — plus the one that matters most in practice,
 * that a *quiet* queue is not mistaken for a *dead* one.
 *
 * Why it exists at all: on shared hosting there is no Supervisor and no
 * Horizon. Everything asynchronous arrives through one Plesk Scheduled Task
 * running `schedule:run`. If it stops, invoicing, leave accrual, payslip
 * notifications, device sync — and now payroll, since it moved off the request
 * thread — stop silently, while the application keeps serving pages normally.
 *
 * See docs/operations/QUEUE-MONITORING.md.
 */
it('reports unhealthy before the scheduler has ever run', function () {
    $snapshot = app(QueueHealth::class)->snapshot();

    expect($snapshot['status'])->toBe('unhealthy')
        ->and($snapshot['scheduler']['last_run_at'])->toBeNull();

    // "Never ran" is distinguished from "stopped" on purpose: the first usually
    // means the Plesk task was never created, which is a different fix.
    expect(implode(' ', $snapshot['problems']))->toContain('never run');
});

it('reports healthy once the scheduler beats', function () {
    $health = app(QueueHealth::class);
    $health->beat();

    $snapshot = $health->snapshot();

    expect($snapshot['status'])->toBe('healthy')
        ->and($snapshot['problems'])->toBe([])
        ->and($snapshot['scheduler']['last_run_at'])->not->toBeNull()
        ->and($snapshot['scheduler']['age_seconds'])->toBeLessThan(5);
});

it('goes unhealthy when the scheduler stops beating', function () {
    $health = app(QueueHealth::class);
    $health->beat();

    // Same as the cron entry being disabled an hour ago.
    $this->travel(2)->hours();

    $snapshot = $health->snapshot(schedulerStaleSeconds: 900);

    expect($snapshot['status'])->toBe('unhealthy')
        ->and(implode(' ', $snapshot['problems']))->toContain('scheduler last ran');
});

it('does not mistake an empty queue for a dead one', function () {
    $health = app(QueueHealth::class);
    $health->beat();

    $snapshot = $health->snapshot();

    // The common case, and the one that would ruin the alert's credibility if
    // it were wrong: nothing to do is healthy, not suspicious.
    expect($snapshot['status'])->toBe('healthy')
        ->and($snapshot['queues']['default']['depth'])->toBe(0)
        ->and($snapshot['queues']['default']['oldest_waiting_seconds'])->toBeNull();
});

it('detects a starving queue even while the scheduler is alive', function () {
    $health = app(QueueHealth::class);
    $health->beat();

    // A job that has been available for an hour and nobody has reserved. This
    // is what a worker draining only `default` looks like from the outside —
    // and a bare `queue:work` does exactly that, because DB_QUEUE is `default`.
    DB::table('jobs')->insert([
        'queue' => 'attendance',
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time() - 3600,
        'created_at' => time() - 3600,
    ]);

    $snapshot = $health->snapshot(oldestJobSeconds: 1800);

    expect($snapshot['status'])->toBe('unhealthy')
        ->and(implode(' ', $snapshot['problems']))->toContain('attendance')
        ->and($snapshot['queues']['attendance']['depth'])->toBe(1);

    // The scheduler is fine. Reporting it as the problem would send someone to
    // check the wrong thing.
    expect(implode(' ', $snapshot['problems']))->not->toContain('scheduler');
});

it('ignores a reserved job, because a worker is already on it', function () {
    $health = app(QueueHealth::class);
    $health->beat();

    DB::table('jobs')->insert([
        'queue' => 'exports',
        'payload' => '{}',
        'attempts' => 1,
        'reserved_at' => time() - 3600,
        'available_at' => time() - 3600,
        'created_at' => time() - 3600,
    ]);

    // A long-running job is not a starving queue. BackupTenantJob may legitimately
    // hold one for 900s, and alerting on that would teach people to mute this.
    expect(app(QueueHealth::class)->snapshot(oldestJobSeconds: 1800)['status'])->toBe('healthy');
});

it('counts failed jobs without treating them as a queue failure', function () {
    $health = app(QueueHealth::class);
    $health->beat();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    $snapshot = $health->snapshot();

    // Something ran and broke — a different alert from nothing running at all.
    // Conflating the two trains people to ignore both.
    expect($snapshot['failed_jobs'])->toBe(1)
        ->and($snapshot['status'])->toBe('healthy');
});

it('exits non-zero from the CLI when the scheduler is dead', function () {
    $this->artisan('ethr:queue:check')->assertExitCode(1);

    app(QueueHealth::class)->beat();

    $this->artisan('ethr:queue:check')->assertExitCode(0);
});
