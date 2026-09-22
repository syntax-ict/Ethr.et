<?php

declare(strict_types=1);

use App\Services\Observability\QueueHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * The HTTP-driven scheduler and queue runner.
 *
 * These routes exist because G0-D is FAIL — no Scheduled Tasks section exists
 * on the shared-hosting subscription, so nothing can start `schedule:run` or
 * `queue:work`. They execute queued work on request, which makes their guard
 * the only thing between the internet and this application's job runner. The
 * tests below are weighted accordingly: most of them are about refusing.
 */
const CRON_TOKEN = 'a-token-that-is-at-least-thirty-two-chars-long';

beforeEach(function () {
    config()->set('cron.token', CRON_TOKEN);
    config()->set('cron.min_token_length', 32);
    Cache::flush();
});

it('404s when no token is configured, rather than advertising the route', function () {
    config()->set('cron.token', null);

    // 404, not 401/403: an unconfigured deployment must not confirm that these
    // routes exist. A 403 would tell a scanner exactly where to spend its time.
    $this->postJson('/api/v1/cron/schedule')->assertNotFound();
    $this->postJson('/api/v1/cron/queue')->assertNotFound();
});

it('404s when the configured token is too short to withstand guessing', function () {
    // A short token is a configuration ERROR, not a credential. Accepting it
    // would be worse than having none, because it looks configured.
    config()->set('cron.token', 'short');

    $this->withHeader('X-Cron-Token', 'short')
        ->postJson('/api/v1/cron/schedule')
        ->assertNotFound();
});

it('404s on a missing, wrong, or empty token', function () {
    $this->postJson('/api/v1/cron/schedule')->assertNotFound();

    $this->withHeader('X-Cron-Token', 'wrong-but-also-thirty-two-characters-long')
        ->postJson('/api/v1/cron/schedule')
        ->assertNotFound();

    $this->postJson('/api/v1/cron/schedule?token=')->assertNotFound();
});

it('accepts the token in the header', function () {
    Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('done');

    $this->withHeader('X-Cron-Token', CRON_TOKEN)
        ->postJson('/api/v1/cron/schedule')
        ->assertOk()
        ->assertJsonPath('task', 'schedule')
        ->assertJsonPath('status', 'ok');
});

it('accepts the token in the query string, because a URL-fetch task sets no headers', function () {
    // This is the whole reason the query string is supported: Plesk's
    // "Fetch a URL" task type cannot send a header. Dropping this would mean
    // dropping the one caller the feature exists for.
    Artisan::shouldReceive('call')->once()->with('schedule:run')->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('done');

    $this->postJson('/api/v1/cron/schedule?token='.CRON_TOKEN)
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});

it('drains exactly the queues the application dispatches onto', function () {
    // The list must equal QueueHealth::QUEUES. A queue missing here is a queue
    // nothing drains — it fills forever and only the health endpoint notices.
    Artisan::shouldReceive('call')
        ->once()
        ->withArgs(function (string $command, array $args): bool {
            return $command === 'queue:work'
                && $args['--queue'] === implode(',', QueueHealth::QUEUES)
                && $args['--stop-when-empty'] === true
                && $args['--max-time'] > 0;
        })
        ->andReturn(0);
    Artisan::shouldReceive('output')->andReturn('');

    $this->withHeader('X-Cron-Token', CRON_TOKEN)
        ->postJson('/api/v1/cron/queue')
        ->assertOk()
        ->assertJsonPath('task', 'queue');
});

it('never runs two of the same task at once', function () {
    // A fetch-a-URL task firing while the previous run is still going must not
    // start a second worker: two concurrent queue:work processes would both
    // reserve jobs. 409 says so plainly instead of looking like a success.
    Cache::lock('cron:queue', 110)->get();

    $this->withHeader('X-Cron-Token', CRON_TOKEN)
        ->postJson('/api/v1/cron/queue')
        ->assertStatus(409)
        ->assertJsonPath('status', 'already-running');
});

it('reports a non-zero artisan exit as failed rather than ok', function () {
    Artisan::shouldReceive('call')->once()->andReturn(1);
    Artisan::shouldReceive('output')->andReturn('boom');

    $this->withHeader('X-Cron-Token', CRON_TOKEN)
        ->postJson('/api/v1/cron/schedule')
        ->assertOk()
        ->assertJsonPath('status', 'failed');
});

it('releases the lock even when the command throws', function () {
    // Without the finally block a single exception would wedge the task for
    // the whole lock duration, and every subsequent tick would 409 — the
    // scheduler would go silently dead, which is this project's recurring
    // failure shape.
    Artisan::shouldReceive('call')->once()->andThrow(new RuntimeException('kaboom'));

    try {
        $this->withHeader('X-Cron-Token', CRON_TOKEN)->postJson('/api/v1/cron/schedule');
    } catch (Throwable) {
        // The exception surfacing is fine; the lock leaking is not.
    }

    expect(Cache::lock('cron:schedule', 1)->get())->toBeTrue();
});

it('rejects GET, so a crawler or link prefetcher cannot trigger a run', function () {
    $this->get('/api/v1/cron/schedule?token='.CRON_TOKEN)->assertMethodNotAllowed();
});

it('rate-limits rejected token attempts, not just accepted ones', function () {
    // The regression guard for a defect in this feature's own first draft:
    // the route originally listed VerifyCronToken BEFORE throttle:cron, so a
    // bad token abort(404)ed before the limiter ever ran. The brake on
    // guessing braked only legitimate callers. Order is load-bearing.
    for ($i = 0; $i < 10; $i++) {
        $this->withHeader('X-Cron-Token', 'wrong-but-also-thirty-two-characters-long')
            ->postJson('/api/v1/cron/schedule')
            ->assertNotFound();
    }

    $this->withHeader('X-Cron-Token', 'wrong-but-also-thirty-two-characters-long')
        ->postJson('/api/v1/cron/schedule')
        ->assertStatus(429);
});
