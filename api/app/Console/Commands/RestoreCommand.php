<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\AuditLogIntegrity;
use App\Services\Backup\BackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * The half that makes the other half a backup.
 *
 * Master plan §30: a backup that has never been restored is not considered
 * verified. Shipping a dump command without a restore command leaves the
 * recovery procedure as prose, to be improvised during the one event it exists
 * for.
 */
class RestoreCommand extends Command
{
    protected $signature = 'ethr:restore
        {name : Backup directory name, or an absolute path}
        {--force : Skip the confirmation prompt (required for non-interactive cron)}';

    protected $description = 'Restore the database and employee documents from a backup';

    public function handle(BackupService $backups): int
    {
        $name = (string) $this->argument('name');
        $path = File::isDirectory($name) ? $name : $backups->backupRoot().DIRECTORY_SEPARATOR.$name;

        if (! File::isDirectory($path)) {
            $this->error("No backup at {$path}");

            return self::FAILURE;
        }

        $manifestFile = $path.DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = File::exists($manifestFile) ? json_decode(File::get($manifestFile), true) : null;

        if ($manifest !== null) {
            $this->line('  created   '.($manifest['created_at'] ?? 'unknown'));
            $this->line('  database  '.($manifest['database']['tables'] ?? '?').' tables, '
                .($manifest['database']['rows'] ?? '?').' rows');
            $this->line('  documents '.($manifest['files']['count'] ?? '?').' files');
        } else {
            $this->warn('  No manifest.json — the dump cannot be checksum-verified.');
        }

        if (! $this->option('force') && ! $this->confirm(
            'This DROPS every table in the current database and overwrites storage/app/private. Continue?',
            false
        )) {
            $this->line('Aborted.');

            // Nothing was restored, so this is not success: a script or cron
            // line that forgot --force must not read exit 0 as "restored".
            // migrate:fresh and db:wipe return 1 on a declined confirmation too.
            return self::FAILURE;
        }

        try {
            $result = $backups->restore($path);

            $this->info(sprintf('Restored %d statements, %d files.', $result['statements'], $result['files']));

            // The DEFINER hazard is solved at dump time rather than here: the
            // dumper emits CREATE TRIGGER with no DEFINER clause, so the
            // triggers now belong to whichever user just ran this. Checked
            // rather than assumed — a silently missing trigger means audit_log
            // has quietly stopped being append-only, and nothing else in the
            // application would notice. (This printed "run ethr:backup:verify",
            // a command that never existed, until 2026-10-14.)
            $failures = app(AuditLogIntegrity::class)->verifyAppendOnly('backup.restored', ['backup' => basename($path)]);
            foreach ($failures as $failure) {
                $this->error($failure);
            }

            $this->newLine();
            $this->line('Then by hand: sign in, read a payroll run, edit an employee, download a document.');

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Restore FAILED: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
