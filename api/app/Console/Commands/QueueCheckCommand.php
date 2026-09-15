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
 * up. This runs from cron, which is the thing being watched — and if cron is
 * dead, the absence of its output is itself the signal. Master plan §40 asks for
 * both; docs/operations/QUEUE-MONITORING.md explains the split.
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
        $this->line('If the scheduler has stopped, check Plesk -> Scheduled Tasks for:');
        $this->line('  * * * * *  cd ~/ethr/api && php artisan schedule:run');

        return self::FAILURE;
    }
}
