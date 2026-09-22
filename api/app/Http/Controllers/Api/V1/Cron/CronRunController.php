<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Cron;

use App\Services\Observability\QueueHealth;
use Dedoc\Scramble\Attributes\ExcludeRouteFromDocs;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Drives `schedule:run` and `queue:work` over HTTP.
 *
 * This exists because G0-D is FAIL: the subscription has no Scheduled Tasks
 * section, so nothing on the host can start either command. Eleven of the
 * fourteen entries in routes/console.php do nothing but enqueue, and all 16
 * classes in app/Jobs implement ShouldQueue — so without a runner the
 * asynchronous half of the product is inert, silently.
 *
 * It is NOT a replacement for real cron where real cron exists. The VPS keeps
 * infrastructure/supervisor.conf and the compose worker services; this is the
 * shared-hosting fallback the register has called "unbuilt" since 2026-09-18.
 */
class CronRunController
{
    /**
     * `php artisan schedule:run` — Laravel decides internally what is due.
     *
     * KEPT OUT OF THE PUBLISHED API CONTRACT, deliberately. CI caught this:
     * Scramble discovered both routes and `src/api/generated.ts` went stale.
     * Regenerating would have been the quick fix and the wrong one —
     *
     *   1. these routes 404 when CRON_TOKEN is unset precisely so an
     *      unconfigured deployment does not confirm they exist; publishing
     *      them in an OpenAPI document advertises them instead;
     *   2. `generated.ts` is the FRONTEND's typed client, and the frontend
     *      will never call these;
     *   3. the drift diff showed this method's own prose — "shared hosting
     *      runs no supervisor" — being lifted into a customer-facing
     *      contract. Internal infrastructure detail does not belong there.
     */
    #[ExcludeRouteFromDocs]
    public function schedule(): JsonResponse
    {
        return $this->runExclusively('schedule', function (): array {
            $exit = Artisan::call('schedule:run');

            return ['exit_code' => $exit, 'output' => $this->tail(Artisan::output())];
        });
    }

    /**
     * `php artisan queue:work --stop-when-empty`.
     *
     * --stop-when-empty, never a daemon: shared hosting runs no supervisor, so
     * the process must exit rather than linger. --max-time bounds it under the
     * HTTP timeout AND under the one-minute tick, so runs cannot overlap even
     * if the lock below were somehow lost.
     */
    #[ExcludeRouteFromDocs]
    public function queue(): JsonResponse
    {
        return $this->runExclusively('queue', function (): array {
            $exit = Artisan::call('queue:work', [
                // The authoritative list. Order is meaningful — Laravel drains
                // left to right — but membership is the correctness property:
                // a queue missing here is a queue nothing drains.
                '--queue' => implode(',', QueueHealth::QUEUES),
                '--stop-when-empty' => true,
                '--max-time' => (int) config('cron.queue_max_seconds', 50),
                '--tries' => 3,
                '--backoff' => 10,
            ]);

            return ['exit_code' => $exit, 'output' => $this->tail(Artisan::output())];
        });
    }

    /**
     * One run of a given task at a time.
     *
     * A fetch-a-URL task that fires while the previous run is still going must
     * not start a second worker — two concurrent `queue:work` processes would
     * both reserve jobs. 409 tells the caller plainly rather than looking like
     * a success that did nothing.
     */
    private function runExclusively(string $task, callable $work): JsonResponse
    {
        $lock = Cache::lock("cron:{$task}", (int) config('cron.lock_seconds', 110));

        if (! $lock->get()) {
            return response()->json([
                'task' => $task,
                'status' => 'already-running',
            ], 409);
        }

        $startedAt = microtime(true);

        try {
            $result = $work();
        } finally {
            $lock->release();
        }

        return response()->json([
            'task' => $task,
            'status' => ($result['exit_code'] ?? 1) === 0 ? 'ok' : 'failed',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ] + $result);
    }

    /** Artisan output is for a human reading a cron log, so cap it. */
    private function tail(string $output): string
    {
        $output = trim($output);

        return strlen($output) > 2000 ? '…'.substr($output, -2000) : $output;
    }
}
