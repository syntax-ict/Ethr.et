<?php

declare(strict_types=1);

namespace App\Services\Observability;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Answers "is anything actually running?", which is different from "is the
 * queue reachable?".
 *
 * On shared hosting there is no Supervisor keeping a worker alive and no
 * Horizon dashboard showing its state. The whole asynchronous side of ETHR
 * arrives through an **external caller hitting `/api/v1/cron/{schedule,queue}`**
 * on a timer — GitHub Actions, every five minutes (Q6, 2026-09-27). It does
 * **not** arrive through a Plesk Scheduled Task: G0-D measured that section as
 * absent on this subscription (owner-read 2026-09-18), which is why the caller
 * is off-host at all.
 *
 * If that caller is disabled, unauthenticated, or silently rate-limited, **jobs
 * stop and nothing says so** — the application keeps serving pages normally.
 * And because the caller is off-host, its silence leaves no trace on this
 * account: there is no cron entry to inspect. The heartbeat below is the only
 * signal, which makes it load-bearing rather than a backstop.
 *
 * What stops: monthly invoicing (this is how the business bills), leave accrual
 * on the 1st, year-end carry-forward, payslip notifications, device sync,
 * anomaly scans — and, since payroll moved off the request thread, payroll runs
 * too. A payroll left at `processing` forever looks identical to one still
 * working.
 *
 * `HealthController` already proved the `jobs` table was readable. That is a
 * genuinely different question: a table nobody is draining reads perfectly.
 *
 * ## Precedent
 *
 * This has happened here once already, with a supervisor present:
 *
 *   "ScanAttendanceAnomaliesJob failed on every scheduled run and was found
 *    only by opening the health endpoint for an unrelated reason."
 *      — docs/CLAUDE.md, Queue Failure Recovery
 *
 * The `Queue::failing` hook exists because of that. It catches a job that runs
 * and fails. It cannot catch a job that never runs, which is the failure mode
 * cron introduces.
 *
 * See docs/operations/QUEUE-MONITORING.md.
 */
class QueueHealth
{
    /** Every queue the application dispatches onto. A bare `queue:work` drains only `default`. */
    public const QUEUES = ['default', 'attendance', 'notifications', 'exports'];

    private const HEARTBEAT_KEY = 'ethr:scheduler:heartbeat';

    /** Kept well beyond any plausible staleness threshold so absence means "never ran", not "expired". */
    private const HEARTBEAT_TTL_SECONDS = 86400;

    private const DRAIN_KEY = 'ethr:queue:last_drain_at';

    private const EXTERNAL_TICK_KEY = 'ethr:cron:last_external_tick_at';

    private const TRAFFIC_TICK_KEY = 'ethr:cron:last_traffic_tick_at';

    /**
     * Longer than the heartbeat's day: a caller dead for two days must read as
     * stale, not as "never ran", which the console shows as unknown.
     */
    private const TICK_TTL_SECONDS = 2592000;

    /**
     * Record that a queue drain ran to its end (CronRunner::queue). Without it
     * "is anything working the queue?" could only be inferred from how old the
     * oldest job is, which says nothing while the queue is empty.
     */
    public function recordDrain(): void
    {
        Cache::put(self::DRAIN_KEY, now()->toIso8601String(), self::HEARTBEAT_TTL_SECONDS);
    }

    public function lastDrain(): ?Carbon
    {
        return $this->readMoment(self::DRAIN_KEY);
    }

    /** Record that the scheduler ran. Called once a minute by routes/console.php. */
    public function beat(): void
    {
        Cache::put(self::HEARTBEAT_KEY, now()->toIso8601String(), self::HEARTBEAT_TTL_SECONDS);
    }

    public function lastBeat(): ?Carbon
    {
        return $this->readMoment(self::HEARTBEAT_KEY);
    }

    /**
     * Record that the external timer reached `/cron/*` (CronRunController).
     *
     * The heartbeat above cannot say WHO ran the scheduler. Since
     * RunBackgroundWorkAfterResponse lets page views run it too, a dead
     * external caller is invisible whenever people are using ETHR — and found
     * only after a quiet night, when nothing ran the 01:00 backup. Recorded on
     * arrival, before the lock: a tick refused with 409 still proves the
     * caller is alive.
     */
    public function recordExternalTick(): void
    {
        Cache::put(self::EXTERNAL_TICK_KEY, now()->toIso8601String(), self::TICK_TTL_SECONDS);
    }

    public function lastExternalTick(): ?Carbon
    {
        return $this->readMoment(self::EXTERNAL_TICK_KEY);
    }

    /** Record that ordinary traffic ran background work (RunBackgroundWorkAfterResponse). */
    public function recordTrafficTick(): void
    {
        Cache::put(self::TRAFFIC_TICK_KEY, now()->toIso8601String(), self::TICK_TTL_SECONDS);
    }

    public function lastTrafficTick(): ?Carbon
    {
        return $this->readMoment(self::TRAFFIC_TICK_KEY);
    }

    private function readMoment(string $key): ?Carbon
    {
        $value = Cache::get($key);

        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whole picture, shaped for both the health endpoint and the CLI check.
     *
     * @return array{
     *     status: string,
     *     problems: array<int, string>,
     *     scheduler: array<string, mixed>,
     *     queues: array<string, mixed>,
     *     failed_jobs: int|null
     * }
     */
    public function snapshot(int $schedulerStaleSeconds = 900, int $oldestJobSeconds = 1800): array
    {
        $problems = [];

        // ── Scheduler ────────────────────────────────────────────────────────
        $last = $this->lastBeat();
        $ageSeconds = $last?->diffInSeconds(now());

        if ($last === null) {
            // Distinguished from "stale" deliberately: never-beaten usually means
            // the caller was never wired up, which is a different fix from a
            // caller that has stopped.
            //
            // The remedy this names must be one that EXISTS on the target. It read
            // "is the Plesk Scheduled Task created?" until 2026-09-28, and G0-D
            // measured that section as absent on this subscription — so the one
            // operator following this message went looking for a panel section
            // that is not there, on the one code path where nothing else is
            // telling them anything. `docs/operations/QUEUE-MONITORING.md` had
            // recorded the defect and the correct reading; recording it is not
            // fixing it.
            $problems[] = 'scheduler has never run — is the external cron caller configured (CRON_TOKEN and base URL)?';
        } elseif ($ageSeconds > $schedulerStaleSeconds) {
            $problems[] = sprintf('scheduler last ran %ds ago (threshold %ds)', $ageSeconds, $schedulerStaleSeconds);
        }

        // ── Queues ───────────────────────────────────────────────────────────
        $queues = [];
        $oldestOverall = null;

        foreach (self::QUEUES as $queue) {
            try {
                $depth = (int) DB::table('jobs')->where('queue', $queue)->count();

                $oldest = DB::table('jobs')
                    ->where('queue', $queue)
                    ->whereNull('reserved_at')
                    ->min('available_at');

                $waited = $oldest === null ? null : max(0, time() - (int) $oldest);

                $queues[$queue] = ['depth' => $depth, 'oldest_waiting_seconds' => $waited];

                if ($waited !== null && ($oldestOverall === null || $waited > $oldestOverall)) {
                    $oldestOverall = $waited;
                }

                // Per-queue, not total. A misconfigured worker draining only
                // `default` leaves three queues starving while the total looks
                // healthy — which is exactly the mistake a bare `queue:work`
                // makes, since DB_QUEUE defaults to `default`.
                if ($waited !== null && $waited > $oldestJobSeconds) {
                    $problems[] = sprintf('queue "%s" has a job waiting %ds (threshold %ds)', $queue, $waited, $oldestJobSeconds);
                }
            } catch (Throwable $e) {
                $queues[$queue] = ['depth' => null, 'error' => $e->getMessage()];
                $problems[] = sprintf('queue "%s" is unreadable', $queue);
            }
        }

        // ── Failed jobs ──────────────────────────────────────────────────────
        // Reported, never a problem on its own. A failed job means something ran
        // and broke, which is a different alert from nothing running at all, and
        // conflating them trains people to ignore both.
        try {
            $failed = (int) DB::table('failed_jobs')->count();
        } catch (Throwable) {
            $failed = null;
        }

        return [
            'status' => $problems === [] ? 'healthy' : 'unhealthy',
            'problems' => $problems,
            'scheduler' => [
                'last_run_at' => $last?->toIso8601String(),
                'age_seconds' => $ageSeconds,
                'stale_after_seconds' => $schedulerStaleSeconds,
            ],
            'queues' => $queues,
            'last_drain_at' => $this->lastDrain()?->toIso8601String(),
            'oldest_waiting_seconds' => $oldestOverall,
            'failed_jobs' => $failed,
        ];
    }
}
