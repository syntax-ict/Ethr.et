<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\AttendanceVisit;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\FileStorageService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How long attendance selfies are kept, and how they are deleted
 * (docs/operations/ATTENDANCE-PHOTO-RETENTION.md).
 *
 * A selfie is kept for the organisation's retention period after the record's
 * last punch (7 days unless changed). Then a 48-hour review opens: HR and the
 * employee's department manager are told, and either can hold a photo for an
 * unresolved complaint. When the review ends, unheld photo files are deleted.
 * The attendance record, its times, its status and everything payroll reads
 * stay, and each deletion is audited.
 *
 * Every query here runs under the current tenant's scope; the command sets it
 * for each organisation in turn.
 */
class PhotoRetention
{
    public const REVIEW_HOURS = 48;

    public const DEFAULT_RETENTION_DAYS = 7;

    public function __construct(private readonly FileStorageService $storage) {}

    /** The current organisation's retention period, in days. */
    public function retentionDays(): int
    {
        $days = AttendanceSetting::query()->value('photo_retention_days');

        return is_numeric($days) && (int) $days > 0 ? (int) $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Records still holding a selfie file.
     *
     * @return Builder<AttendanceRecord>
     */
    public static function withPhotos(): Builder
    {
        return AttendanceRecord::query()->where(function (Builder $q): void {
            $q->whereNotNull('photo_path')->orWhereNotNull('metadata->checkout_photo_path');
        });
    }

    /** When a record's selfies stop being kept: its last punch plus the retention period. */
    public static function keepUntil(AttendanceRecord $record, int $retentionDays): ?Carbon
    {
        $last = $record->check_out ?? $record->check_in;

        return $last?->copy()->addDays($retentionDays);
    }

    /** When a record under review loses its selfies, unless one is held first. */
    public static function deleteAfter(AttendanceRecord $record): ?Carbon
    {
        return $record->photo_review_started_at?->copy()->addHours(self::REVIEW_HOURS);
    }

    /**
     * Opens the review for every unheld record whose retention period has
     * passed, and returns those records. Nothing is deleted here.
     *
     * @return Collection<int, AttendanceRecord>
     */
    public function startReviews(CarbonInterface $now): Collection
    {
        $cutoff = Carbon::instance($now)->subDays($this->retentionDays());

        $due = self::withPhotos()
            ->whereNull('photo_review_started_at')
            ->whereNull('photo_held_at')
            ->where(function (Builder $q) use ($cutoff): void {
                $q->where('check_out', '<', $cutoff)
                    ->orWhere(fn (Builder $open) => $open->whereNull('check_out')->where('check_in', '<', $cutoff));
            })
            ->with('employee')
            ->get();

        if ($due->isNotEmpty()) {
            AttendanceRecord::query()
                ->whereIn('id', $due->pluck('id'))
                ->update(['photo_review_started_at' => $now]);
            $due->each(fn (AttendanceRecord $r) => $r->photo_review_started_at = Carbon::instance($now));
        }

        return $due;
    }

    /** Deletes the selfies of every unheld record whose review has ended. */
    public function deleteDue(CarbonInterface $now): int
    {
        $deleted = 0;

        self::withPhotos()
            ->whereNull('photo_held_at')
            ->whereNotNull('photo_review_started_at')
            ->where('photo_review_started_at', '<=', Carbon::instance($now)->subHours(self::REVIEW_HOURS))
            ->chunkById(200, function (Collection $records) use (&$deleted): void {
                foreach ($records as $record) {
                    /** @var AttendanceRecord $record */
                    $this->deletePhotos($record);
                    $deleted++;
                }
            });

        return $deleted;
    }

    /**
     * Deletes field-visit photos older than the retention period plus the
     * review window, keeping the visits. A visit photo shows a place or a
     * job more often than a face, so it has no review queue of its own; it
     * is held no longer than a selfie would be.
     */
    public function deleteVisitPhotos(CarbonInterface $now): int
    {
        $cutoff = Carbon::instance($now)->subDays($this->retentionDays())->subHours(self::REVIEW_HOURS);
        $deleted = 0;

        AttendanceVisit::query()
            ->whereNotNull('photo_path')
            ->where('occurred_at', '<', $cutoff)
            ->chunkById(200, function (Collection $visits) use (&$deleted): void {
                foreach ($visits as $visit) {
                    /** @var AttendanceVisit $visit */
                    $this->storage->delete((string) $visit->photo_path);
                    $visit->forceFill(['photo_path' => null])->save();
                    $deleted++;
                }
            });

        return $deleted;
    }

    /**
     * Deletes a record's selfie files and keeps the record. The review marker
     * is cleared, so a photo that arrives later (a late offline check-out)
     * goes through the whole cycle again.
     */
    public function deletePhotos(AttendanceRecord $record): void
    {
        $paths = $record->photoPaths();

        foreach ($paths as $path) {
            $this->storage->delete($path);
        }

        $metadata = $record->metadata ?? [];
        unset($metadata['checkout_photo_path']);
        // The photos' fingerprints go with them (AttendanceRiskScorer).
        unset($metadata['photo_hash'], $metadata['checkout_photo_hash']);

        $record->update([
            'photo_path' => null,
            'metadata' => $metadata === [] ? null : $metadata,
            'photos_deleted_at' => now(),
            'photo_review_started_at' => null,
        ]);

        AuditLog::record('attendance.photos_deleted', $record, [
            'photos' => array_keys($paths),
            'retention_days' => $this->retentionDays(),
        ]);
    }

    /**
     * Who reviews these records, and how many each may see: everyone holding
     * `attendance.viewPhotos` (HR across the organisation, a department
     * manager for their own department), counted through the same scope that
     * decides what they can open.
     *
     * @param  Collection<int, AttendanceRecord>  $records
     * @return list<array{user: User, count: int}>
     */
    public function reviewers(Collection $records): array
    {
        $candidates = User::query()
            ->where('status', 'active')
            ->where(function (Builder $q): void {
                $q->whereIn('role', [UserRole::TENANT_ADMIN->value, UserRole::HR_ADMIN->value, UserRole::DEPT_ADMIN->value])
                    ->orWhereNotNull('custom_role_id');
            })
            ->with('employee')
            ->get();

        $reviewers = [];
        foreach ($candidates as $user) {
            if (! $user->hasPermission('attendance.viewPhotos')) {
                continue;
            }
            $count = $records->filter(
                fn (AttendanceRecord $r) => $r->employee !== null && $user->canAccessEmployee($r->employee)
            )->count();
            if ($count > 0) {
                $reviewers[] = ['user' => $user, 'count' => $count];
            }
        }

        return $reviewers;
    }
}
