<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Support\BackupHealth;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs as a PHP job with no shell and no mysqldump. That constraint is the whole
 * design: scripts/backup.sh, the only backup this project had, is
 * `docker compose exec mariadb mysqldump` — meaningless on the target host.
 *
 * It reaches the host as a Laravel scheduled entry (`dailyAt('01:00')`, see
 * routes/console.php) driven by whatever invokes `schedule:run`. On this account
 * that is an external caller POSTing to /api/v1/cron/schedule, **not** a Plesk
 * Scheduled Task — G0-D measured that section as absent. The equivalent CLI
 * invocation, for a host that does offer one:
 *
 *   php /home/<user>/ethr/api/artisan ethr:backup --off-host --keep=2
 */
class BackupCommand extends Command
{
    protected $signature = 'ethr:backup
        {--label= : Suffix for the backup directory name}
        {--keep= : How many backups to retain locally; defaults to config backup.keep}
        {--off-host : Fail unless an encrypted archive reaches the off-host disk (copied anyway whenever one is configured)}
        {--disk= : Which disk to copy to; defaults to config backup.off_host_disk}';

    protected $description = 'Back up the database and employee documents';

    public function handle(BackupService $backups): int
    {
        // The nightly run arrives through the web SAPI (/cron/schedule), whose
        // 30-second limit a backup plus an upload can outlast. A run cut off
        // half-way leaves a directory with no manifest and no copy.
        @set_time_limit(0);

        try {
            $this->info('Backing up...');
            $result = $backups->create($this->option('label'));
            $m = $result['manifest'];

            $this->line(sprintf(
                '  database  %d tables, %d rows, %d triggers, %s',
                $m['database']['tables'],
                $m['database']['rows'],
                $m['database']['triggers'],
                $this->humanBytes($m['database']['bytes']),
            ));
            $this->line(sprintf(
                '  documents %d files, %s',
                $m['files']['count'],
                $this->humanBytes($m['files']['bytes'])
            ));
            $this->line('  path      '.$result['path']);

            $offHostFailed = ! $this->copyOffHost($backups, $result['path']);

            // Falls back to config rather than to a signature default, so
            // BACKUP_KEEP reaches this. It did not until 2026-09-23: the
            // signature said `--keep=7`, which is never null, so config
            // backup.keep and the BACKUP_KEEP env var were dead. An operator
            // lowering retention on a 5 GB plan got 7 anyway, silently.
            $keep = $this->option('keep') ?? config('backup.keep', 2);

            $removed = $backups->prune((int) $keep);
            if ($removed !== []) {
                $this->line('  pruned    '.implode(', ', $removed));
            }

            $this->info('Done. Restore with: php artisan ethr:restore '.$result['name']);

            return $offHostFailed ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            // Exit non-zero so cron notices, and record it so the operations
            // centre does. A backup that fails silently is indistinguishable
            // from one that never ran, which is precisely the failure this path
            // exists to prevent.
            BackupHealth::recordFailure('backup');
            $this->error('Backup FAILED: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Copy whenever a disk and a key are configured, and with `--off-host`
     * insist on it. Returns false when a copy was due and did not arrive.
     *
     * Its own failure does not stop the local prune that follows: a copy
     * failing night after night must not also fill the 5 GB account with
     * local backups.
     */
    private function copyOffHost(BackupService $backups, string $path): bool
    {
        $disk = (string) ($this->option('disk') ?: config('backup.off_host_disk'));
        $blocker = $backups->offHostBlocker($disk);

        if ($blocker !== null && ! $this->option('off-host')) {
            // Warned on every run, not once in a README. A backup that only
            // ever exists on the machine it protects does not survive the
            // failure it exists for, and on a 5 GB plan it competes with the
            // data for space.
            $this->warn("  off-host  NOT COPIED ({$blocker}): this backup only exists on the host it protects.");

            return true;
        }

        try {
            $copy = $backups->copyOffHost($path, $disk);
            BackupHealth::recordOffHostCopy($copy['copied_at']);
            $this->line('  off-host  '.$disk.':'.$copy['key'].' ('.$this->humanBytes($copy['bytes']).', encrypted)');

            $removed = $backups->pruneOffHost((int) config('backup.off_host_keep', 14), $disk);
            if ($removed !== []) {
                $this->line('  pruned    '.implode(', ', $removed));
            }

            return true;
        } catch (Throwable $e) {
            BackupHealth::recordFailure('off_host');
            report($e);
            $this->error('  off-host  FAILED: '.$e->getMessage());

            return false;
        }
    }

    private function humanBytes(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }
            $bytes = (int) ($bytes / 1024);
        }

        return $bytes.' B';
    }
}
