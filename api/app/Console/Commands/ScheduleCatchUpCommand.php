<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Observability\QueueHealth;
use App\Support\HealthSignals;
use App\Support\PhpCli;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Application;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
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
 * The marker lives in the `signals` store (HealthSignals), not the default one:
 * every release runs `optimize:clear`, which empties the default store, and a
 * run that found no marker would cover only its own minute and skip whatever
 * came due between the last tick and the deploy.
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

            $captured = null;

            try {
                $this->useConfiguredPhpBinary($event);
                $captured = $this->captureDefaultOutput($event);
                $event->run($this->laravel);
                $ran++;

                // A `Schedule::command` runs as a child process, so its failure
                // is an exit code, not an exception. Only exceptions counted,
                // and a backup that never ran (a PHP binary the host cannot
                // find, an off-host copy refused) answered `/cron/schedule`
                // with "ok" while its output went to /dev/null. Stock
                // schedule:run reads the exit code; this replacement did not.
                if (is_int($event->exitCode) && $event->exitCode !== 0) {
                    $failed = true;
                    $tail = $this->outputTail($captured);
                    $this->error(sprintf('  failed: exit code %d', $event->exitCode));
                    if ($tail !== '') {
                        $this->error('  output: '.$tail);
                    }
                    app(QueueHealth::class)->recordTaskFailure($event->getSummaryForDisplay(), $event->exitCode);
                    report(new RuntimeException(sprintf(
                        'Scheduled task [%s] exited %d.%s',
                        $event->getSummaryForDisplay(),
                        $event->exitCode,
                        $tail === '' ? '' : ' Output: '.$tail,
                    )));
                }
            } catch (Throwable $e) {
                // One failing task must not stop the rest, or a broken report
                // definition would starve the backup behind it.
                $failed = true;
                report($e);
                $this->error(sprintf('  failed: %s', $e->getMessage()));
                app(QueueHealth::class)->recordTaskFailure($event->getSummaryForDisplay(), null);
            } finally {
                if ($captured !== null) {
                    $event->output = $captured['original'];
                    @unlink($captured['path']);
                }
            }
        }

        HealthSignals::store()->forever(self::LAST_RUN_KEY, $now->getTimestamp());

        if ($ran === 0) {
            $this->line('No scheduled tasks came due since the last run.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Run a `Schedule::command` child with the PHP binary PhpCli resolves.
     *
     * Laravel builds `'php' 'artisan' ...` with the bare word under PHP-FPM,
     * and the shell then finds a different PHP. Only the prefix Laravel itself
     * put there is replaced; any other command is left as written.
     */
    private function useConfiguredPhpBinary(Event $event): void
    {
        if ($event instanceof CallbackEvent || ! is_string($event->command)) {
            return;
        }

        $prefix = Application::phpBinary().' ';

        if (str_starts_with($event->command, $prefix)) {
            $event->command = escapeshellarg(PhpCli::binary()).' '.substr($event->command, strlen($prefix));
        }
    }

    /**
     * Point an event whose output goes to the null device at a file of this run.
     *
     * @return array{path: string, original: string|null}|null
     */
    private function captureDefaultOutput(Event $event): ?array
    {
        if ($event instanceof CallbackEvent || $event->output !== $event->getDefaultOutput()) {
            return null;
        }

        try {
            $directory = storage_path('framework/schedule');
            File::ensureDirectoryExists($directory);
            $path = $directory.DIRECTORY_SEPARATOR.'ethr-sched-'.Str::random(16).'.log';

            if (file_put_contents($path, '') === false) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $original = $event->output;
        $event->output = $path;
        $event->shouldAppendOutput = false;

        return ['path' => $path, 'original' => $original];
    }

    /**
     * The last 1,000 bytes of a captured child's output, trimmed.
     *
     * @param  array{path: string, original: string|null}|null  $captured
     */
    private function outputTail(?array $captured): string
    {
        if ($captured === null || ! is_file($captured['path'])) {
            return '';
        }

        $size = (int) filesize($captured['path']);
        $handle = @fopen($captured['path'], 'rb');

        if ($handle === false) {
            return '';
        }

        if ($size > 1000) {
            fseek($handle, -1000, SEEK_END);
        }

        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return trim(mb_scrub($text));
    }

    /** Exclusive start of the window: the last run, bounded; the previous minute on a first run. */
    private function windowStart(Carbon $now): Carbon
    {
        // Upgrade path: the release that moved the marker finds it in the old place, once.
        $last = HealthSignals::store()->get(self::LAST_RUN_KEY) ?? Cache::store()->get(self::LAST_RUN_KEY);
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
