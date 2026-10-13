<?php

declare(strict_types=1);

use App\Services\Observability\QueueHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Background work after ordinary requests (console QA F23).
 *
 * The external timer fires a few times a day on this host, so the queue —
 * password-reset mail, payroll runs, notifications — waited hours. Any
 * request that reaches Laravel may now, at most once a minute and after its
 * response is sent, drain the queue for a few seconds and run the scheduler
 * catch-up when the heartbeat is stale.
 */
beforeEach(function () {
    config()->set('cron.opportunistic', true);
    // CLI has no fastcgi_finish_request; the guard is tested on its own below.
    config()->set('cron.opportunistic_requires_early_flush', false);
    config()->set('cron.opportunistic_seconds', 5);
    Cache::flush();
});

function expectQueueDrain(int $times = 1): void
{
    Artisan::shouldReceive('call')
        ->times($times)
        ->withArgs(fn (string $command, array $args = []): bool => $command === 'queue:work'
            && $args['--queue'] === implode(',', QueueHealth::QUEUES)
            && $args['--stop-when-empty'] === true
            && $args['--max-time'] === 5)
        ->andReturn(0);
}

it('drains the queue and catches the scheduler up after an ordinary request', function () {
    Artisan::shouldReceive('call')->once()->with('ethr:schedule-run')->andReturn(0);
    expectQueueDrain();
    Artisan::shouldReceive('output')->andReturn('');

    $this->getJson('/api/v1/health')->assertSuccessful();

    expect(app(QueueHealth::class)->lastDrain())->not->toBeNull();
});

it('does it at most once a minute, however many requests arrive', function () {
    Artisan::shouldReceive('call')->once()->with('ethr:schedule-run')->andReturn(0);
    expectQueueDrain(1);
    Artisan::shouldReceive('output')->andReturn('');

    $this->getJson('/api/v1/health');
    $this->getJson('/api/v1/health');
    $this->getJson('/api/v1/health');
});

it('leaves the scheduler alone while its heartbeat is fresh', function () {
    app(QueueHealth::class)->beat();

    Artisan::shouldReceive('call')->never()->with('ethr:schedule-run');
    expectQueueDrain();
    Artisan::shouldReceive('output')->andReturn('');

    $this->getJson('/api/v1/health');
});

it('does not start a second worker while a cron drain holds the lock', function () {
    app(QueueHealth::class)->beat();
    Cache::lock('cron:queue', 110)->get();

    Artisan::shouldReceive('call')->never();

    $this->getJson('/api/v1/health')->assertSuccessful();
});

it('never makes a visitor wait: off where the response cannot be sent first', function () {
    config()->set('cron.opportunistic_requires_early_flush', true);

    if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
        $this->markTestSkipped('This PHP can flush early; the guard only matters where it cannot.');
    }

    Artisan::shouldReceive('call')->never();

    $this->getJson('/api/v1/health');
});

it('never breaks the request it rides on', function () {
    Artisan::shouldReceive('call')->andThrow(new RuntimeException('database went away'));

    $this->getJson('/api/v1/health')->assertSuccessful();
});

it('can be switched off', function () {
    config()->set('cron.opportunistic', false);

    Artisan::shouldReceive('call')->never();

    $this->getJson('/api/v1/health');
});

it('reports when the queue was last drained', function () {
    app(QueueHealth::class)->recordDrain();

    expect(app(QueueHealth::class)->snapshot()['last_drain_at'])->not->toBeNull();
});
