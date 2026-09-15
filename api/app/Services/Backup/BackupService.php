<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Creates and restores whole-application backups.
 *
 * Distinct from BackupTenantJob, which exports one tenant's rows as JSON for
 * portability and says so in its own header ("not a binary-restorable system
 * backup"). This is the disaster-recovery path: schema, every row, triggers,
 * and the employee documents under storage/app/private.
 *
 * Until this existed there was no way to back up or restore ETHR anywhere
 * except Docker — scripts/backup.sh is `docker compose exec mariadb mysqldump`
 * and `docker run --volumes-from ethr-minio`, neither of which means anything on
 * shared hosting. docs/audit/BASELINE.md ranked that the single largest risk in
 * the system, ahead of the cross-tenant defect, because an HR and payroll
 * product that cannot be restored has no recoverable failure mode at all.
 *
 * ## What "verified" means here
 *
 * Master plan §30: *a backup that has never been restored is not considered
 * verified*. That is why `restore()` exists in the same class rather than as a
 * runbook full of shell commands, and why the test suite performs an actual
 * destructive round trip — dump, drop everything, restore, assert the data and
 * the triggers came back.
 *
 * ## Off-host copies
 *
 * A backup on the same 5 GB account it is protecting is a convenience, not a
 * backup. `copyOffHost()` pushes an archive to the configured `s3` disk, which
 * is already wired through league/flysystem-aws-s3 and needs no new dependency.
 * It is optional because credentials may not exist yet; it is not optional
 * before go-live.
 */
class BackupService
{
    public function __construct(private readonly DatabaseDumper $dumper) {}

    /**
     * @return array{name: string, path: string, manifest: array<string, mixed>}
     */
    public function create(?string $label = null): array
    {
        // Every artefact below contains the entire dataset. Default 0644 would
        // leave salary history and scanned identity documents readable by any
        // account on a shared host, so constrain everything created from here.
        $previousUmask = umask(0077);

        try {
            $name = 'ethr-'.now()->format('Ymd-His').($label !== null ? '-'.preg_replace('/[^A-Za-z0-9_-]/', '', $label) : '');
            $root = $this->backupRoot();
            $dir = $root.DIRECTORY_SEPARATOR.$name;

            File::ensureDirectoryExists($dir, 0700);
            @chmod($root, 0700);

            $sqlPath = $dir.DIRECTORY_SEPARATOR.'database.sql';
            $stats = $this->dumper->dumpTo($sqlPath);

            $files = $this->copyPrivateFiles($dir);

            $manifest = [
                'name' => $name,
                'created_at' => now()->toIso8601String(),
                'app_version' => config('app.version', 'unknown'),
                'driver' => config('database.default'),
                'database' => $stats,
                'files' => $files,
                'sql_sha256' => hash_file('sha256', $sqlPath),
            ];

            File::put(
                $dir.DIRECTORY_SEPARATOR.'manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );

            return ['name' => $name, 'path' => $dir, 'manifest' => $manifest];
        } finally {
            umask($previousUmask);
        }
    }

    /**
     * Restore a backup directory in place.
     *
     * Destructive by definition: the dump begins with DROP TABLE for every
     * table it contains.
     *
     * @return array{statements: int, files: int}
     */
    public function restore(string $path): array
    {
        $manifestPath = $path.DIRECTORY_SEPARATOR.'manifest.json';
        $sqlPath = $path.DIRECTORY_SEPARATOR.'database.sql';

        if (! File::exists($sqlPath)) {
            throw new RuntimeException("No database.sql in {$path} — not a backup directory.");
        }

        if (File::exists($manifestPath)) {
            $manifest = json_decode(File::get($manifestPath), true);

            // A dump that does not match its manifest is worse than no dump: it
            // restores something, and nobody knows what. Refuse rather than
            // restore a truncated or edited file.
            if (isset($manifest['sql_sha256']) && hash_file('sha256', $sqlPath) !== $manifest['sql_sha256']) {
                throw new RuntimeException(
                    'database.sql does not match the sha256 in manifest.json — the backup is corrupt or was edited.'
                );
            }
        }

        $statements = $this->executeSqlFile($sqlPath);
        $files = $this->restorePrivateFiles($path);

        return ['statements' => $statements, 'files' => $files];
    }

    /** Push an archive of the backup to the configured off-host disk. */
    public function copyOffHost(string $path, string $disk = 's3'): string
    {
        $archive = $this->archive($path);
        $key = 'backups/'.basename($archive);

        Storage::disk($disk)->put($key, File::get($archive));

        return $key;
    }

    /** Keep the newest $keep backups; delete the rest. Returns what was removed. */
    public function prune(int $keep): array
    {
        $dirs = collect(File::directories($this->backupRoot()))
            ->filter(fn ($d) => File::exists($d.DIRECTORY_SEPARATOR.'manifest.json'))
            ->sortByDesc(fn ($d) => basename($d))
            ->values();

        $removed = [];

        foreach ($dirs->slice($keep) as $dir) {
            File::deleteDirectory($dir);
            $removed[] = basename($dir);
        }

        return $removed;
    }

    public function backupRoot(): string
    {
        // Outside the document root by default. On the target layout the app
        // lives at ~/ethr/api and httpdocs is a sibling, so storage/backups is
        // never web-reachable — but the path is configurable because the whole
        // point is to get copies somewhere else entirely.
        return rtrim((string) config('backup.path', storage_path('backups')), '/\\');
    }

    /** @return array{count: int, bytes: int} */
    private function copyPrivateFiles(string $dir): array
    {
        $source = storage_path('app/private');
        $target = $dir.DIRECTORY_SEPARATOR.'private';

        if (! File::isDirectory($source)) {
            return ['count' => 0, 'bytes' => 0];
        }

        File::ensureDirectoryExists($target, 0700);
        File::copyDirectory($source, $target);

        $count = 0;
        $bytes = 0;
        foreach (File::allFiles($target) as $file) {
            $count++;
            $bytes += $file->getSize();
        }

        return ['count' => $count, 'bytes' => $bytes];
    }

    private function restorePrivateFiles(string $path): int
    {
        $source = $path.DIRECTORY_SEPARATOR.'private';

        if (! File::isDirectory($source)) {
            return 0;
        }

        $target = storage_path('app/private');
        File::ensureDirectoryExists($target, 0700);
        File::copyDirectory($source, $target);

        return count(File::allFiles($target));
    }

    /**
     * Execute a dump file statement by statement.
     *
     * Split on ";\n" rather than ";" so a semicolon inside a trigger body or a
     * string literal does not tear a statement in half. The dumper writes one
     * statement per line precisely so this stays true.
     */
    private function executeSqlFile(string $sqlPath): int
    {
        $pdo = \DB::connection()->getPdo();
        $handle = fopen($sqlPath, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot read {$sqlPath}.");
        }

        $buffer = '';
        $count = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = rtrim($line, "\r\n");

                if ($trimmed === '' || str_starts_with(ltrim($trimmed), '--')) {
                    continue;
                }

                $buffer .= $trimmed."\n";

                if (str_ends_with($trimmed, ';')) {
                    $statement = trim($buffer);
                    $buffer = '';

                    try {
                        $pdo->exec($statement);
                        $count++;
                    } catch (Throwable $e) {
                        throw new RuntimeException(
                            'Restore failed on: '.mb_substr($statement, 0, 160).' — '.$e->getMessage(),
                            previous: $e
                        );
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $count;
    }

    /** Tar+gzip a backup directory. Falls back to zip where phar is unavailable. */
    private function archive(string $path): string
    {
        $target = $path.'.tar.gz';

        if (class_exists(\PharData::class)) {
            @unlink($target);
            @unlink($path.'.tar');

            $tar = new \PharData($path.'.tar');
            $tar->buildFromDirectory($path);
            $tar->compress(\Phar::GZ);
            @unlink($path.'.tar');

            return $target;
        }

        if (class_exists(\ZipArchive::class)) {
            $target = $path.'.zip';
            $zip = new \ZipArchive;
            $zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

            foreach (File::allFiles($path) as $file) {
                $zip->addFile($file->getPathname(), $file->getRelativePathname());
            }

            $zip->close();

            return $target;
        }

        throw new RuntimeException(
            'Neither phar nor zip is available, so the backup cannot be archived for off-host copy. '
            .'Copy the directory manually, or enable one of those extensions.'
        );
    }
}
