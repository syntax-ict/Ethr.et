<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Observability\CronRunner;
use App\Services\Observability\QueueHealth;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Lets ordinary traffic move the queue (console QA F23).
 *
 * On this host nothing runs the queue but an external timer fetching
 * `/cron/queue`, and GitHub's timer fired four to six times a day — so a
 * password-reset email, a payroll run or a leave notification waited hours.
 * After a response has been sent, at most once a minute across all requests,
 * this drains the queue for a few seconds, and runs the scheduler catch-up
 * first when its heartbeat is older than five minutes. Any signed-in person
 * using ETHR now keeps the background moving; the timer remains for quiet
 * hours.
 *
 * It never delays a visitor: the work starts in terminate(), and only where
 * PHP has already handed the response back (PHP-FPM's fastcgi_finish_request,
 * which Symfony calls when sending; LiteSpeed's equivalent). It never breaks
 * a request either: any failure is logged and swallowed. CronRunner's lock is
 * the one `/cron/*` takes, so a cron tick and a page view cannot both start a
 * worker.
 */
final class RunBackgroundWorkAfterResponse
{
    private const TICK_KEY = 'cron:opportunistic:tick';

    public function __construct(
        private readonly CronRunner $runner,
        private readonly QueueHealth $health,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->shouldRun($request)) {
            return;
        }

        // Cache::add is atomic: of every request in this minute, one wins.
        if (! Cache::add(self::TICK_KEY, true, max(1, (int) config('cron.opportunistic_every_seconds', 60)))) {
            return;
        }

        ignore_user_abort(true);

        try {
            $this->health->recordTrafficTick();

            $last = $this->health->lastBeat();
            if ($last === null || $last->diffInSeconds(now(), absolute: true) > (int) config('cron.opportunistic_schedule_after_seconds', 300)) {
                $this->runner->schedule();
            }

            $this->runner->queue((int) config('cron.opportunistic_seconds', 5));
        } catch (Throwable $e) {
            Log::warning('Background work after response failed', ['error' => $e->getMessage()]);
        }
    }

    private function shouldRun(Request $request): bool
    {
        if (! config('cron.opportunistic', true)) {
            return false;
        }

        // The timer's own requests already do this work.
        if ($request->is('api/v1/cron/*')) {
            return false;
        }

        if (config('cron.opportunistic_requires_early_flush', true)
            && ! function_exists('fastcgi_finish_request')
            && ! function_exists('litespeed_finish_request')) {
            return false;
        }

        return true;
    }
}
