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
 * before go-live. The archive is encrypted first (BackupCipher), and the
 * nightly run copies whenever a disk and a key are configured; `unpack()`
 * turns a copy back into a directory `restore()` can read.
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

            $this->refuseUnreplayableDump($sqlPath, $dir);

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
     * Refuse to finish a backup whose INSERTs name a generated column.
     *
     * The manifest's sha256 proves the dump is the file that was written. It
     * cannot prove the file can be put back, and §15f is what the difference
     * looks like: `writeRows()` used `SELECT *`, which returns generated
     * columns, and the resulting INSERT is rejected on replay — on SQLite
     * outright, and on MariaDB depending on `sql_mode`, which is a property of
     * whoever runs the restore rather than of the backup. A dump that is
     * replayable on the machine that wrote it and not on the machine that needs
     * it is the same defect wearing a hat.
     *
     * So the check runs at **create** time, where it fails while there is still
     * something to fall back on, and deliberately **not** at restore time: an
     * older backup taken before the dumper was fixed may well still restore on
     * a permissive engine, and refusing to try would turn a recoverable
     * situation into an unrecoverable one.
     *
     * It is schema-driven — `generatedColumns()` reads the live schema — so a
     * generated column added later is covered without this method changing.
     *
     * The half-written directory is removed first. It carries no manifest, so
     * `prune()` would never list it and it would sit there looking like a
     * backup.
     */
    private function refuseUnreplayableDump(string $sqlPath, string $dir): void
    {
        $offences = $this->dumper->findGeneratedColumnInserts($sqlPath);

        if ($offences === []) {
            return;
        }

        File::deleteDirectory($dir);

        $lines = [];

        foreach ($offences as $table => $columns) {
            $lines[] = '  '.$table.': '.implode(', ', $columns);
        }

        throw new RuntimeException(
            'Refusing to write a backup that cannot be restored: its INSERT statements name generated '
            ."columns, which an engine may reject on replay.\n\n".implode("\n", $lines)."\n\n"
            .'This is a defect in DatabaseDumper, not in your data — `writeRows()` must select an explicit '
            .'column list that excludes generated columns. See docs/audit/BASELINE.md §15f and §15g.'
        );
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

    /**
     * Why an off-host copy cannot be made right now, or null when it can.
     *
     * Codes, not sentences: the operations centre translates them.
     */
    public function offHostBlocker(?string $disk = null): ?string
    {
        $disk ??= (string) config('backup.off_host_disk');
        $settings = config("filesystems.disks.{$disk}");

        if (! is_array($settings)) {
            return 'off_host_disk_missing';
        }

        // The s3 disk is always defined; it is configured only once it has a
        // bucket and credentials. Any other driver is taken as configured.
        if (($settings['driver'] ?? null) === 's3'
            && (empty($settings['bucket']) || empty($settings['key']) || empty($settings['secret']))) {
            return 'off_host_not_configured';
        }

        if (BackupCipher::fromConfig() === null) {
            return 'off_host_key_missing';
        }

        return null;
    }

    /**
     * Push an encrypted archive of the backup to the off-host disk, and record
     * the copy in the backup's manifest.
     *
     * Streamed, so a large document store never has to fit in memory, and
     * checked by size afterwards: the s3 disk is `throw => false`, so a failed
     * put() returns false rather than throwing, and was ignored here until
     * 2026-11-01. The local archive is removed either way; prune() only
     * removes backup directories, so an archive left beside them would sit on
     * the 5 GB account forever.
     *
     * @return array{disk: string, key: string, bytes: int, copied_at: string}
     */
    public function copyOffHost(string $path, ?string $disk = null): array
    {
        $disk ??= (string) config('backup.off_host_disk');
        $blocker = $this->offHostBlocker($disk);

        if ($blocker !== null) {
            throw new RuntimeException("No off-host copy was made ({$blocker}). See docs/deployment/BACKUP-RESTORE.md.");
        }

        /** @var BackupCipher $cipher */
        $cipher = BackupCipher::fromConfig();
        $archive = $this->archive($path);
        $encrypted = $archive.BackupCipher::EXTENSION;

        try {
            $cipher->encryptFile($archive, $encrypted);
            // At once, not in `finally`: on a 5 GB account the plain archive
            // and the encrypted one should not both sit there for the upload.
            @unlink($archive);
            $bytes = (int) filesize($encrypted);
            $key = 'backups/'.basename($encrypted);
            $storage = Storage::disk($disk);

            $stream = fopen($encrypted, 'rb');
            try {
                $written = $storage->writeStream($key, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($written === false || ! $storage->exists($key) || $storage->size($key) !== $bytes) {
                throw new RuntimeException("The off-host copy to {$disk}:{$key} did not arrive whole.");
            }
        } finally {
            @unlink($archive);
            @unlink($encrypted);
        }

        $copy = ['disk' => $disk, 'key' => $key, 'bytes' => $bytes, 'copied_at' => now()->toIso8601String()];
        $this->recordInManifest($path, ['off_host' => $copy]);

        return $copy;
    }

    /**
     * Keep the newest $keep archives on the off-host disk; delete the rest.
     * Only names this class writes are touched, so a bucket shared with
     * anything else keeps its other files.
     *
     * @return list<string>
     */
    public function pruneOffHost(int $keep, ?string $disk = null): array
    {
        $storage = Storage::disk($disk ?? (string) config('backup.off_host_disk'));

        $archives = collect($storage->files('backups'))
            ->filter(fn (string $key): bool => (bool) preg_match('/^backups\/ethr-\d{8}-\d{6}[A-Za-z0-9_-]*\.(tar\.gz|zip)'.preg_quote(BackupCipher::EXTENSION, '/').'$/', $key))
            ->sortDesc()
            ->values();

        $removed = [];

        foreach ($archives->slice(max(1, $keep)) as $key) {
            $storage->delete($key);
            $removed[] = $key;
        }

        return $removed;
    }

    /**
     * Turn an off-host archive back into a backup directory that `ethr:restore`
     * can read. `$source` is a local file, or a key on `$disk` when one is given.
     *
     * @return string the backup's name
     */
    public function unpack(string $source, ?string $disk = null, ?BackupCipher $cipher = null): string
    {
        $cipher ??= BackupCipher::fromConfig();

        if ($cipher === null) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY is not set, so the archive cannot be decrypted.');
        }

        $root = $this->backupRoot();
        File::ensureDirectoryExists($root, 0700);

        $previousUmask = umask(0077);
        $base = basename($source, BackupCipher::EXTENSION);
        $encrypted = $root.DIRECTORY_SEPARATOR.basename($source);
        // A name of its own: phar caches an archive's index by path for the
        // life of the process, so reusing the name archive() just wrote read
        // the new bytes through the old index and extracted garbage.
        $archive = $root.DIRECTORY_SEPARATOR.'unpack-'.bin2hex(random_bytes(6)).(str_ends_with($base, '.zip') ? '.zip' : '.tar.gz');
        $fetched = false;

        try {
            if ($disk !== null) {
                $stream = Storage::disk($disk)->readStream($source);

                if (! is_resource($stream)) {
                    throw new RuntimeException("{$disk}:{$source} does not exist.");
                }

                $out = fopen($encrypted, 'wb');
                stream_copy_to_stream($stream, $out);
                fclose($out);
                fclose($stream);
                $fetched = true;
            } else {
                $encrypted = $source;
            }

            $cipher->decryptFile($encrypted, $archive);

            $name = (string) preg_replace('/\.(tar\.gz|zip)$/', '', $base);
            $dir = $root.DIRECTORY_SEPARATOR.$name;

            if (File::isDirectory($dir)) {
                throw new RuntimeException("A backup named {$name} is already on this host. Restore that one, or remove it first.");
            }

            $this->extract($archive, $dir);

            if (! File::exists($dir.DIRECTORY_SEPARATOR.'manifest.json')) {
                File::deleteDirectory($dir);

                throw new RuntimeException('The archive holds no manifest.json, so it is not an ETHR backup.');
            }

            return $name;
        } finally {
            umask($previousUmask);
            @unlink($archive);
            if ($fetched) {
                @unlink($encrypted);
            }
        }
    }

    /**
     * The newest backup on this host, and the newest off-host copy any of
     * them records, read from their manifests: what the operations centre and
     * the backup check report. The two can differ: a manual backup taken
     * before a release is local only, and is the newest until the next night.
     *
     * @return array{name: string, created_at: string|null, off_host: array<string, mixed>|null}|null
     */
    public function latest(): ?array
    {
        $root = $this->backupRoot();

        if (! File::isDirectory($root)) {
            return null;
        }

        $manifests = collect(File::directories($root))
            ->filter(fn ($d) => File::exists($d.DIRECTORY_SEPARATOR.'manifest.json'))
            ->sortByDesc(fn ($d) => basename($d))
            ->map(function (string $dir): array {
                $manifest = json_decode((string) File::get($dir.DIRECTORY_SEPARATOR.'manifest.json'), true);

                return ['name' => basename($dir)] + (is_array($manifest) ? $manifest : []);
            })
            ->values();

        if ($manifests->isEmpty()) {
            return null;
        }

        $newest = $manifests->first();
        $copied = $manifests->first(fn (array $m): bool => is_array($m['off_host'] ?? null));

        return [
            'name' => $newest['name'],
            'created_at' => is_string($newest['created_at'] ?? null) ? $newest['created_at'] : null,
            'off_host' => $copied === null ? null : $copied['off_host'],
        ];
    }

    /** @param  array<string, mixed>  $fields */
    private function recordInManifest(string $path, array $fields): void
    {
        $manifestPath = $path.DIRECTORY_SEPARATOR.'manifest.json';
        $manifest = json_decode((string) File::get($manifestPath), true);

        File::put(
            $manifestPath,
            json_encode(array_merge(is_array($manifest) ? $manifest : [], $fields), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
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

    /** The reverse of archive(). */
    private function extract(string $archive, string $dir): void
    {
        File::ensureDirectoryExists($dir, 0700);

        if (str_ends_with($archive, '.zip')) {
            $zip = new \ZipArchive;

            if ($zip->open($archive) !== true || ! $zip->extractTo($dir)) {
                throw new RuntimeException('The decrypted archive is not a readable zip.');
            }

            $zip->close();

            return;
        }

        (new \PharData($archive))->extractTo($dir, null, true);
    }
}
