<?php

declare(strict_types=1);

namespace App\Services\Observability;

use App\Support\BackgroundWork;
use App\Support\StaleReleaseCaches;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs the scheduler catch-up and a bounded queue drain, one at a time.
 *
 * Two callers share it: the `/cron/{schedule,queue}` endpoints an external
 * timer fetches (CronRunController), and the after-response hook that lets
 * ordinary traffic move the queue (RunBackgroundWorkAfterResponse). They take
 * the same lock, so a cron tick and a page view can never start two workers
 * that reserve the same jobs.
 */
final class CronRunner
{
    public function __construct(private readonly QueueHealth $health) {}

    /**
     * `ethr:schedule-run`, the catch-up scheduler: every event due since the
     * last run, once each.
     *
     * When the sweep dropped a stale config or route cache (StaleReleaseCaches),
     * this tick rebuilds them first. The rebuild never decides the tick's
     * result: a failure is reported under `recache` and retried next tick.
     *
     * @return array{exit_code: int, output: string, recache?: array{status: string, commands: list<array{command: string, exit_code: int}>}}|null null when another run holds the lock
     */
    public function schedule(): ?array
    {
        return $this->exclusively('schedule', function (): array {
            $recache = $this->recache();

            $exit = Artisan::call('ethr:schedule-run');

            $result = ['exit_code' => $exit, 'output' => $this->tail(Artisan::output())];

            return $recache === null ? $result : $result + ['recache' => $recache];
        });
    }

    /**
     * @return array{status: string, commands: list<array{command: string, exit_code: int}>}|null null when no sweep is waiting on a rebuild
     */
    private function recache(): ?array
    {
        try {
            $app = app();
            $marker = StaleReleaseCaches::markerPath($app);

            if (! is_file($marker)) {
                return null;
            }

            if (StaleReleaseCaches::cachesAreFresh($app)) {
                @unlink($marker);

                return ['status' => 'already-fresh', 'commands' => []];
            }

            $commands = [];
            $ok = true;

            foreach (StaleReleaseCaches::RECACHE as $command) {
                $exit = Artisan::call($command);
                $commands[] = ['command' => $command, 'exit_code' => $exit];

                if ($exit !== 0) {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                @unlink($marker);
            }

            return ['status' => $ok ? 'rebuilt' : 'failed', 'commands' => $commands];
        } catch (Throwable $e) {
            report($e);

            return ['status' => 'failed', 'commands' => []];
        }
    }

    /**
     * `queue:work --stop-when-empty`, never a daemon: shared hosting runs no
     * supervisor, so the process must exit. `$maxSeconds` bounds it under the
     * caller's own time limit.
     *
     * @return array{exit_code: int, output: string}|null null when another run holds the lock
     */
    public function queue(int $maxSeconds): ?array
    {
        return $this->exclusively('queue', function () use ($maxSeconds): array {
            $exit = Artisan::call('queue:work', [
                // The authoritative list. Order is meaningful — Laravel drains
                // left to right — but membership is the correctness property:
                // a queue missing here is a queue nothing drains.
                '--queue' => implode(',', QueueHealth::QUEUES),
                '--stop-when-empty' => true,
                '--max-time' => max(1, $maxSeconds),
                '--tries' => 3,
                '--backoff' => 10,
            ]);

            $this->health->recordDrain();

            return ['exit_code' => $exit, 'output' => $this->tail(Artisan::output())];
        });
    }

    /**
     * @param  callable(): array{exit_code: int, output: string}  $work
     * @return array{exit_code: int, output: string}|null
     */
    private function exclusively(string $task, callable $work): ?array
    {
        $lock = Cache::lock("cron:{$task}", (int) config('cron.lock_seconds', 110));

        if (! $lock->get()) {
            return null;
        }

        try {
            return BackgroundWork::run($work);
        } finally {
            $lock->release();
        }
    }

    /** Artisan output is for a human reading a cron log, so cap it. */
    private function tail(string $output): string
    {
        $output = trim($output);

        return strlen($output) > 2000 ? '…'.substr($output, -2000) : $output;
    }
}
