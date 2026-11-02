<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Backup\BackupService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Answers "is ETHR being backed up?", which nothing answered before
 * 2026-11-01: a nightly backup that stopped, or failed every night, was found
 * only by someone listing the backup folder.
 *
 * When the last backup was taken and whether it reached the off-host disk are
 * read from the backups themselves (BackupService::latest()), so a cache that
 * was cleared cannot make them look older or newer than they are. Only a
 * failure is remembered separately, in the signals store, because a failed
 * run leaves no backup to read it from.
 */
final class BackupHealth
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const UNKNOWN = 'unknown';

    /**
     * The backup runs nightly, so a day and two hours of slack, then red:
     * OPERATIONS-CENTRE-SPEC.md, Panel 5. No amber step, because a night
     * without a backup is already the failure, not an early sign of one.
     */
    public const STALE_AFTER_SECONDS = 26 * 3600;

    private const FAILED_KEY = 'ethr:backup:last_failed_at';

    private const FAILED_STAGE_KEY = 'ethr:backup:last_failed_stage';

    /**
     * Kept beside the manifests because two manual backups in one day would
     * prune (keep 2) the nightly one whose manifest records the copy.
     */
    private const COPIED_KEY = 'ethr:backup:last_off_host_at';

    private const TTL_SECONDS = 2592000;

    public function __construct(private readonly BackupService $backups) {}

    public static function recordOffHostCopy(string $copiedAt): void
    {
        try {
            HealthSignals::store()->put(self::COPIED_KEY, $copiedAt, self::TTL_SECONDS);
        } catch (Throwable) {
            // As below: never the reason a run fails.
        }
    }

    /** @param  'backup'|'off_host'  $stage */
    public static function recordFailure(string $stage): void
    {
        try {
            HealthSignals::store()->put(self::FAILED_KEY, now()->toIso8601String(), self::TTL_SECONDS);
            HealthSignals::store()->put(self::FAILED_STAGE_KEY, $stage, self::TTL_SECONDS);
        } catch (Throwable) {
            // A health record must never be the reason a backup run fails.
        }
    }

    /**
     * @return array{
     *     state: 'ok'|'warning'|'critical'|'unknown',
     *     reasons: list<string>,
     *     last_backup_at: string|null,
     *     age_seconds: int|null,
     *     last_failed_at: string|null,
     *     last_failed_stage: string|null,
     *     off_host: array{configured: bool, disk: string, last_copied_at: string|null},
     *     disk: array{measured_at: string, quota_bytes: int, total_bytes: int, database_bytes: int|null, documents_bytes: int, backups_bytes: int, logs_bytes: int, other_bytes: int}|null
     * }
     */
    public function snapshot(): array
    {
        $critical = [];
        $warning = [];
        $unknown = [];

        // Off production, no backups and no off-host disk are the normal
        // state of a development machine, and `unknown` is never red
        // (OperationsStatusService): a panel that is always red is ignored.
        $production = app()->environment('production');

        try {
            $latest = $this->backups->latest();
        } catch (Throwable) {
            $latest = null;
        }

        $lastAt = $this->parse($latest['created_at'] ?? null);
        $age = $lastAt === null ? null : (int) $lastAt->diffInSeconds(now(), absolute: true);

        if ($lastAt === null && $production) {
            $critical[] = 'backup_never_run';
        } elseif ($lastAt === null) {
            $unknown[] = 'backup_never_run';
        } elseif ($age > self::STALE_AFTER_SECONDS) {
            $critical[] = 'backup_stale';
        }

        $failedAt = $this->parse(HealthSignals::store()->get(self::FAILED_KEY));
        $failedStage = HealthSignals::store()->get(self::FAILED_STAGE_KEY);

        // A failure is news only while nothing has succeeded since.
        if ($failedAt !== null && $failedStage === 'backup' && ($lastAt === null || $failedAt->greaterThan($lastAt))) {
            $critical[] = 'backup_failing';
        }

        $target = $this->backups->offHostTarget();
        $disk = $target['name'];
        $blocker = $target['blocker'];
        $copiedAt = $this->latestOf(
            $this->parse($latest['off_host']['copied_at'] ?? null),
            $this->parse(HealthSignals::store()->get(self::COPIED_KEY)),
        );
        $copyAge = $copiedAt === null ? null : (int) $copiedAt->diffInSeconds(now(), absolute: true);

        if ($blocker !== null) {
            // A warning, not an alarm: the backups themselves are fine, and
            // configuring a disk is an owner decision rather than a fault.
            if ($production) {
                $warning[] = $blocker;
            } else {
                $unknown[] = $blocker;
            }
        } elseif ($copiedAt === null) {
            // Configured, and the next nightly run has not copied yet.
            $warning[] = 'off_host_copy_missing';
        } elseif ($copyAge > self::STALE_AFTER_SECONDS) {
            $critical[] = 'off_host_stale';
        }

        if ($failedAt !== null && $failedStage === 'off_host' && $blocker === null
            && ($copiedAt === null || $failedAt->greaterThan($copiedAt))) {
            $critical[] = 'off_host_failing';
        }

        // Measured by the nightly backup (DiskUsage). Disk is here because
        // backups are most of it, and because this area already reaches
        // the outside monitor through /cron/backup.
        $usage = DiskUsage::last();
        $diskReason = $usage === null ? null : DiskUsage::reason($usage);
        if ($diskReason === 'disk_critical') {
            $critical[] = $diskReason;
        } elseif ($diskReason === 'disk_high') {
            $warning[] = $diskReason;
        }

        return [
            'state' => match (true) {
                $critical !== [] => self::CRITICAL,
                $warning !== [] => self::WARNING,
                $unknown !== [] => self::UNKNOWN,
                default => self::OK,
            },
            'reasons' => array_values(array_unique([...$critical, ...$warning, ...$unknown])),
            'last_backup_at' => $lastAt?->toIso8601String(),
            'age_seconds' => $age,
            'last_failed_at' => $failedAt?->toIso8601String(),
            'last_failed_stage' => is_string($failedStage) ? $failedStage : null,
            'off_host' => [
                'configured' => $blocker === null,
                'disk' => $disk,
                'last_copied_at' => $copiedAt?->toIso8601String(),
            ],
            'disk' => $usage === null ? null : [
                'measured_at' => (string) $usage['measured_at'],
                'quota_bytes' => (int) $usage['quota_bytes'],
                'total_bytes' => (int) $usage['total_bytes'],
                'database_bytes' => isset($usage['database_bytes']) ? (int) $usage['database_bytes'] : null,
                'documents_bytes' => (int) $usage['documents_bytes'],
                'backups_bytes' => (int) $usage['backups_bytes'],
                'logs_bytes' => (int) $usage['logs_bytes'],
                'other_bytes' => (int) $usage['other_bytes'],
            ],
        ];
    }

    private function latestOf(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->greaterThan($b) ? $a : $b;
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
