<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * `schedule:run` for a caller that does not arrive every minute.
 *
 * Laravel's own `schedule:run` runs only what is due in the minute it is
 * called. That assumes a system cron firing every minute; production has none
 * (no SSH, no Scheduled Tasks — G0-D) and is driven over HTTP by GitHub Actions,
 * whose every-five-minutes schedule was measured on 2026-10-12 delivering 4–6 runs a day at
 * arbitrary minutes. Every task pinned to a minute — the 01:00 backup, the
 * 05:00 trial reminders, the monthly invoices, the hourly reports — therefore
 * never ran in production; the run logs show only the heartbeat (console QA F23).
 *
 * This command remembers when it last ran and runs every event that came due
 * since then, once, however late the call. An event that came due several times
 * in the gap (an hourly report after a six-hour gap) runs once: each run covers
 * "now", not each missed slot.
 *
 * The first call on a deployment covers only the current minute, so deploying
 * this does not fire a month of missed work at once. The window is capped for
 * the same reason: a caller that was dead for longer than the cap resumes with
 * what is due, not with everything that ever was.
 */
final class ScheduleCatchUpCommand extends Command
{
    /** Where the time of the last completed run is kept. */
    public const LAST_RUN_KEY = 'ethr:schedule:last_run_at';

    /** No catch-up reaches further back than this. */
    private const MAX_WINDOW_DAYS = 31;

    protected $signature = 'ethr:schedule-run';

    protected $description = 'Run every scheduled task that came due since the last run (schedule:run with catch-up)';

    public function handle(Schedule $schedule): int
    {
        $now = Carbon::now()->startOfMinute();
        $since = $this->windowStart($now);
        $failed = false;
        $ran = 0;

        foreach ($schedule->events() as $event) {
            if (! $event->runsInEnvironment($this->laravel->environment())) {
                continue;
            }

            if (! $this->cameDueSince($event->expression, $event->timezone, $since, $now)) {
                continue;
            }

            if (! $event->filtersPass($this->laravel)) {
                continue;
            }

            $this->line(sprintf('%s Running [%s]', $now->format('Y-m-d H:i'), $event->getSummaryForDisplay()));

            try {
                $event->run($this->laravel);
                $ran++;
            } catch (Throwable $e) {
                // One failing task must not stop the rest, or a broken report
                // definition would starve the backup behind it.
                $failed = true;
                report($e);
                $this->error(sprintf('  failed: %s', $e->getMessage()));
            }
        }

        Cache::forever(self::LAST_RUN_KEY, $now->getTimestamp());

        if ($ran === 0) {
            $this->line('No scheduled tasks came due since the last run.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Exclusive start of the window: the last run, bounded; the previous minute on a first run. */
    private function windowStart(Carbon $now): Carbon
    {
        $last = Cache::get(self::LAST_RUN_KEY);
        $floor = $now->copy()->subDays(self::MAX_WINDOW_DAYS);

        if (! is_numeric($last)) {
            return $now->copy()->subMinute();
        }

        $since = Carbon::createFromTimestamp((int) $last, $now->getTimezone());

        return $since->lessThan($floor) ? $floor : $since;
    }

    /** Whether the expression's latest due minute falls in (since, now]. */
    private function cameDueSince(string $expression, mixed $timezone, Carbon $since, Carbon $now): bool
    {
        $zone = $timezone instanceof DateTimeZone ? $timezone->getName() : ($timezone ?: $now->getTimezone()->getName());
        $previous = (new CronExpression($expression))->getPreviousRunDate($now->toDateTimeImmutable(), 0, true, $zone);

        return $previous->getTimestamp() > $since->getTimestamp()
            && $previous->getTimestamp() <= $now->getTimestamp();
    }
}
