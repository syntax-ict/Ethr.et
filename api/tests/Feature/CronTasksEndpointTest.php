<?php

declare(strict_types=1);

use App\Services\Observability\QueueHealth;
use App\Support\HealthSignals;
use Illuminate\Support\Carbon;

/**
 * GET /api/v1/cron/tasks: the check an uptime monitor calls so that stopped or
 * failing background work answers 503 (BASELINE section 25bl). Same token and
 * same read-only contract as /cron/backup.
 */
const CRON_TASKS_TOKEN = 'a-token-that-is-at-least-thirty-two-chars-long';

beforeEach(function () {
    config(['cron.token' => CRON_TASKS_TOKEN, 'cron.min_token_length' => 32]);

    foreach (['ethr:schedule:task_failures', 'ethr:scheduler:heartbeat', 'ethr:cron:last_external_tick_at'] as $key) {
        HealthSignals::store()->forget($key);
    }
});

afterEach(function () {
    Carbon::setTestNow();
});

it('answers 404 without the right token', function () {
    $this->getJson('/api/v1/cron/tasks')->assertNotFound();
    $this->withHeader('X-Cron-Token', 'wrong-token-wrong-token-wrong-token-1')->getJson('/api/v1/cron/tasks')->assertNotFound();
});

it('answers 200 when the scheduler is alive and nothing is failing', function () {
    app(QueueHealth::class)->beat();

    $this->withHeader('X-Cron-Token', CRON_TASKS_TOKEN)->getJson('/api/v1/cron/tasks')
        ->assertOk()
        ->assertJsonPath('task', 'tasks-check')
        ->assertJsonPath('status', 'healthy')
        ->assertJsonPath('failing_tasks', []);
});

it('answers 503 and names a task that failed', function () {
    Carbon::setTestNow(now()->startOfSecond());
    $health = app(QueueHealth::class);
    $health->beat();
    $health->recordTaskFailure('ethr-backup', 255);

    $this->withHeader('X-Cron-Token', CRON_TASKS_TOKEN)->getJson('/api/v1/cron/tasks')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy')
        ->assertJsonPath('failing_tasks.0.task', 'ethr-backup')
        ->assertJsonPath('failing_tasks.0.exit_code', 255)
        ->assertJsonFragment(['problems' => [
            sprintf('scheduled task "ethr-backup" is failing (exit 255), last failed at %s', now()->toIso8601String()),
        ]]);
});

it('stops reporting a failure older than 48 hours', function () {
    $health = app(QueueHealth::class);
    $health->recordTaskFailure('ethr-backup', 255);

    Carbon::setTestNow(now()->addHours(49));
    $health->beat();

    $this->withHeader('X-Cron-Token', CRON_TASKS_TOKEN)->getJson('/api/v1/cron/tasks')->assertOk();
});

it('clears when the same task succeeds, not when a different one does', function () {
    $health = app(QueueHealth::class);
    $health->beat();
    $health->recordTaskFailure('ethr-backup', 255);

    $health->recordTaskSuccess('another-task');
    $this->withHeader('X-Cron-Token', CRON_TASKS_TOKEN)->getJson('/api/v1/cron/tasks')->assertStatus(503);

    $health->recordTaskSuccess('ethr-backup');
    $this->withHeader('X-Cron-Token', CRON_TASKS_TOKEN)->getJson('/api/v1/cron/tasks')->assertOk();
});

it('answers 503 when the scheduler heartbeat is stale', function () {
    $health = app(QueueHealth::class);
    $health->beat();

    Carbon::setTestNow(now()->addMinutes(20));

    $this->withHeader('X-Cron-Token', CRON_TASKS_TOKEN)->getJson('/api/v1/cron/tasks')
        ->assertStatus(503)
        ->assertJsonPath('status', 'unhealthy');
});

it('records no external tick, so it cannot make a dead caller look alive', function () {
    app(QueueHealth::class)->beat();

    $this->withHeader('X-Cron-Token', CRON_TASKS_TOKEN)->getJson('/api/v1/cron/tasks')->assertOk();

    expect(app(QueueHealth::class)->lastExternalTick())->toBeNull();
});
