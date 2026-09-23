<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runs from Plesk Scheduled Tasks as a PHP CLI job — no shell, no mysqldump.
 *
 *   php /home/<user>/ethr/api/artisan ethr:backup --off-host --keep=2
 *
 * That constraint is the whole design. scripts/backup.sh, the only backup this
 * project had, is `docker compose exec mariadb mysqldump` — meaningless on the
 * target host.
 */
class BackupCommand extends Command
{
    protected $signature = 'ethr:backup
        {--label= : Suffix for the backup directory name}
        {--keep= : How many backups to retain locally; defaults to config backup.keep}
        {--off-host : Also copy an archive to the configured off-host disk}
        {--disk=s3 : Which disk to copy to}';

    protected $description = 'Back up the database and employee documents';

    public function handle(BackupService $backups): int
    {
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

            if ($this->option('off-host')) {
                $key = $backups->copyOffHost($result['path'], (string) $this->option('disk'));
                $this->line('  off-host  '.$this->option('disk').':'.$key);
            } else {
                // Warned on every run, not once in a README. A backup that only
                // ever exists on the machine it protects does not survive the
                // failure it exists for, and on a 5 GB plan it competes with the
                // data for space.
                $this->warn('  off-host  NOT COPIED — this backup only exists on the host it protects.');
            }

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

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Exit non-zero so cron notices. A backup that fails silently is
            // indistinguishable from one that never ran, which is precisely the
            // failure this path exists to prevent.
            $this->error('Backup FAILED: '.$e->getMessage());

            return self::FAILURE;
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
