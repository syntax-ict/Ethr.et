<?php

declare(strict_types=1);

namespace App\Support;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use UnexpectedValueException;

/**
 * How much of the hosting plan's disk ETHR uses, which nothing measured until
 * 2026-11-02. The plan is one quota (5 GB on Bronze) shared by the database,
 * every employee document and the local backups, each of which holds another
 * full copy of the documents. When it fills, uploads fail first and then the
 * database refuses writes.
 *
 * Measured by the nightly backup, before it copies anything and again after it
 * prunes, because walking every file is too slow for a page load. Mail and
 * anything outside the application are on the same quota and are not seen
 * here, so the thresholds are set low.
 */
class DiskUsage
{
    /** Amber: time to clear space or raise the plan. */
    public const WARNING_RATIO = 0.70;

    /** Red: the next backup or a batch of uploads may not fit. */
    public const CRITICAL_RATIO = 0.85;

    /** A backup must leave this much of the quota free once it is written. */
    public const BACKUP_CEILING_RATIO = 0.95;

    private const KEY = 'ethr:disk:usage';

    private const TTL_SECONDS = 2592000;

    /**
     * @return array{
     *     measured_at: string,
     *     quota_bytes: int,
     *     total_bytes: int,
     *     database_bytes: int|null,
     *     documents_bytes: int,
     *     backups_bytes: int,
     *     logs_bytes: int,
     *     other_bytes: int
     * }
     */
    public function measure(): array
    {
        $root = $this->root();
        $parts = [
            'documents' => storage_path('app/private'),
            'backups' => rtrim((string) config('backup.path', storage_path('backups')), '/\\'),
            'logs' => storage_path('logs'),
        ];
        $bytes = ['documents' => 0, 'backups' => 0, 'logs' => 0, 'other' => 0];

        // One walk over the application, each file counted under the part it
        // sits in; a part kept outside the application is walked on its own.
        foreach ($this->files($root) as $path => $size) {
            $bytes[$this->partOf($path, $parts)] += $size;
        }
        foreach ($parts as $part => $dir) {
            if (! $this->inside($dir, $root)) {
                foreach ($this->files($dir) as $size) {
                    $bytes[$part] += $size;
                }
            }
        }

        $database = $this->databaseBytes();

        return [
            'measured_at' => now()->toIso8601String(),
            'quota_bytes' => $this->quotaBytes(),
            'total_bytes' => array_sum($bytes) + ($database ?? 0),
            'database_bytes' => $database,
            'documents_bytes' => $bytes['documents'],
            'backups_bytes' => $bytes['backups'],
            'logs_bytes' => $bytes['logs'],
            'other_bytes' => $bytes['other'],
        ];
    }

    /**
     * Whether one more backup (a dump the size of the database and a copy of
     * every document) fits under the ceiling.
     *
     * @param  array{quota_bytes: int, total_bytes: int, database_bytes: int|null, documents_bytes: int}  $usage
     */
    public static function roomForBackup(array $usage): bool
    {
        $needed = ($usage['database_bytes'] ?? 0) + $usage['documents_bytes'];

        return $usage['total_bytes'] + $needed <= $usage['quota_bytes'] * self::BACKUP_CEILING_RATIO;
    }

    /** @param  array<string, mixed>  $usage */
    public static function record(array $usage): void
    {
        try {
            HealthSignals::store()->put(self::KEY, $usage, self::TTL_SECONDS);
        } catch (Throwable) {
            // A health record must never be the reason a backup run fails.
        }
    }

    /** @return array<string, mixed>|null */
    public static function last(): ?array
    {
        try {
            $usage = HealthSignals::store()->get(self::KEY);
        } catch (Throwable) {
            return null;
        }

        return is_array($usage) ? $usage : null;
    }

    /** @return 'disk_critical'|'disk_high'|null */
    public static function reason(array $usage): ?string
    {
        $quota = (int) ($usage['quota_bytes'] ?? 0);
        if ($quota <= 0) {
            return null;
        }

        $ratio = (int) ($usage['total_bytes'] ?? 0) / $quota;

        return match (true) {
            $ratio >= self::CRITICAL_RATIO => 'disk_critical',
            $ratio >= self::WARNING_RATIO => 'disk_high',
            default => null,
        };
    }

    private function quotaBytes(): int
    {
        return max(1, (int) config('backup.disk_quota_mb', 5120)) * 1024 * 1024;
    }

    private function root(): string
    {
        $root = (string) config('backup.disk_root', '');

        if ($root === '') {
            $root = base_path();
        } elseif (preg_match('#^([A-Za-z]:)?[/\\\\]#', $root) !== 1) {
            $root = base_path($root);
        }

        return rtrim($root, '/\\');
    }

    /**
     * MariaDB reports its tables' size; SQLite is one file. Null when neither
     * answers. Protected so a test can hold it still across database engines.
     */
    protected function databaseBytes(): ?int
    {
        try {
            $driver = DB::connection()->getDriverName();

            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne('SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.tables WHERE table_schema = DATABASE()');

                return (int) ($row->bytes ?? 0);
            }

            if ($driver === 'sqlite') {
                $file = (string) DB::connection()->getConfig('database');

                return is_file($file) ? (int) filesize($file) : null;
            }
        } catch (Throwable) {
            // Unmeasured is reported as unmeasured, not as zero.
        }

        return null;
    }

    /** @return iterable<string, int> path => bytes, links not followed */
    private function files(string $dir): iterable
    {
        if (! is_dir($dir)) {
            return;
        }

        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->isLink() || ! $file->isFile()) {
                    continue;
                }

                try {
                    yield $file->getPathname() => (int) $file->getSize();
                } catch (Throwable) {
                    // A file removed during the walk.
                }
            }
        } catch (UnexpectedValueException) {
            // The directory itself could not be opened.
        }
    }

    /** @param  array<string, string>  $parts */
    private function partOf(string $path, array $parts): string
    {
        foreach ($parts as $part => $dir) {
            if ($this->inside($path, $dir)) {
                return $part;
            }
        }

        return 'other';
    }

    private function inside(string $path, string $dir): bool
    {
        $path = str_replace('\\', '/', $path);
        $dir = rtrim(str_replace('\\', '/', $dir), '/');

        return $path === $dir || str_starts_with($path, $dir.'/');
    }
}
