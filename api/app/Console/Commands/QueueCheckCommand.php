<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Observability\QueueHealth;
use Illuminate\Console\Command;

/**
 * The dead-man's-switch.
 *
 *   php artisan ethr:queue:check                 # exit 1 if anything is wrong
 *   php artisan ethr:queue:check --json          # for an uptime monitor
 *   php artisan ethr:queue:check --scheduler-stale=600 --oldest-job=900
 *
 * Exits non-zero when the scheduler has stopped or a queue is starving, so a
 * cron entry, an uptime check or a CI job can act on it without parsing output.
 *
 * Why a separate command rather than a row in the health endpoint: the endpoint
 * is served by the application, so it can only answer while the application is
 * up, whereas a runner outside the application can report its silence.
 *
 * NOTHING IN THIS REPOSITORY RUNS THIS COMMAND, and that is deliberate rather
 * than an oversight — stated here because the docblock used to say "this runs
 * from cron, which is the thing being watched" (corrected 2026-11-13). The
 * production host has no cron entry and no shell (G0-D), so there is nothing to
 * run it from; and scheduling it would be circular, because a watchdog the
 * scheduler starts cannot report that the scheduler stopped. What actually
 * watches production is an off-host monitor polling GET /api/v1/health for
 * `queue_detail`, and GET /api/v1/cron/backup for a 503.
 *
 * So this is a command for a human at a terminal, and for any deployment that
 * DOES have a runner — a VPS, a developer machine, a CI job. The exit code is
 * its contract: 0 healthy, 1 a problem, no output parsing required.
 * docs/operations/QUEUE-MONITORING.md explains the split.
 */
class QueueCheckCommand extends Command
{
    protected $signature = 'ethr:queue:check
        {--json : Emit the raw snapshot}
        {--scheduler-stale=900 : Seconds before a silent scheduler is a problem}
        {--oldest-job=1800 : Seconds a job may wait before its queue is starving}';

    protected $description = 'Check that the scheduler is running and no queue is starving';

    public function handle(QueueHealth $health): int
    {
        $snapshot = $health->snapshot(
            (int) $this->option('scheduler-stale'),
            (int) $this->option('oldest-job'),
        );

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $snapshot['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
        }

        $scheduler = $snapshot['scheduler'];
        $this->line('scheduler   '.($scheduler['last_run_at'] ?? 'NEVER RUN')
            .($scheduler['age_seconds'] !== null ? "  ({$scheduler['age_seconds']}s ago)" : ''));

        foreach ($snapshot['queues'] as $queue => $state) {
            $this->line(sprintf(
                '  %-14s depth %-6s oldest %s',
                $queue,
                $state['depth'] ?? '?',
                // isset() already excludes null, so no second check is needed.
                isset($state['oldest_waiting_seconds']) ? $state['oldest_waiting_seconds'].'s' : '—',
            ));
        }

        // Reported, never fatal. A failed job means something ran and broke,
        // which needs a different response from nothing running at all.
        $this->line('failed jobs '.($snapshot['failed_jobs'] ?? '?'));

        if ($snapshot['status'] === 'healthy') {
            $this->info('OK');

            return self::SUCCESS;
        }

        $this->newLine();
        foreach ($snapshot['problems'] as $problem) {
            $this->error('  '.$problem);
        }

        $this->newLine();
        // The host has no Scheduled Tasks section and no shell (G0-D), so
        // nothing on it runs schedule:run. The GitHub Actions workflow is the
        // caller; this used to send operators to a panel that does not exist.
        $this->line('If the scheduler has stopped, check the external cron caller:');
        $this->line('  GitHub Actions -> "ETHR scheduler (external cron caller)" (.github/workflows/cron.yml)');
        $this->line('  It POSTs /api/v1/cron/schedule and /api/v1/cron/queue every 5 minutes;');
        $this->line('  a DORMANT run means ETHR_CRON_TOKEN or ETHR_CRON_BASE_URL is not set.');

        return self::FAILURE;
    }
}
