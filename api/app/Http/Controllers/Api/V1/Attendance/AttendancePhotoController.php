<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\HoldAttendancePhotoRequest;
use App\Http\Resources\AttendancePhotoResource;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Attendance\PhotoRetention;
use App\Services\FileStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Attendance selfies for review (docs/operations/ATTENDANCE-PHOTO-RETENTION.md).
 *
 * HR and department managers (`attendance.viewPhotos`) see the selfies of the
 * people their scope reaches, every view audited, and can hold one for an
 * unresolved complaint so it is not deleted. Only HR (`attendance.manage`)
 * releases a hold.
 */
class AttendancePhotoController extends Controller
{
    public function __construct(
        private readonly PhotoRetention $retention,
        private readonly FileStorageService $storage,
    ) {}

    /**
     * Records that still hold a selfie, newest first. `status=review` narrows
     * to those whose review is open, `status=held` to those on hold.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.viewPhotos');

        /** @var User $user */
        $user = $request->user();
        $status = (string) $request->query('status', 'all');

        $query = PhotoRetention::withPhotos()
            ->with('employee', 'photoHolder.employee')
            ->whereHas('employee', fn ($q) => $user->scopeAccessibleEmployees($q));

        if ($status === 'review') {
            $query->whereNotNull('photo_review_started_at')->whereNull('photo_held_at');
        } elseif ($status === 'held') {
            $query->whereNotNull('photo_held_at');
        }

        $request->attributes->set('photo_retention_days', $this->retention->retentionDays());

        return AttendancePhotoResource::collection(
            $query->orderByDesc('check_in')->paginate(min(100, max(1, (int) $request->query('per_page', 25))))
        );
    }

    /** One selfie, as the image itself. `which` is `check-in` or `check-out`. */
    public function show(Request $request, AttendanceRecord $attendanceRecord, string $which): Response
    {
        $this->authorizeRecord($request, $attendanceRecord);

        $key = $which === 'check-out' ? 'check_out' : 'check_in';
        $path = $attendanceRecord->photoPaths()[$key] ?? null;
        $bytes = $path !== null && in_array($which, ['check-in', 'check-out'], true) ? $this->storage->read($path) : null;

        abort_if($bytes === null, 404);

        AuditLog::record('attendance.photo_viewed', $attendanceRecord, ['photo' => $key]);

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Keeps a record's selfies past the retention period, for an unresolved complaint. */
    public function hold(HoldAttendancePhotoRequest $request, AttendanceRecord $attendanceRecord): AttendancePhotoResource
    {
        $this->authorizeRecord($request, $attendanceRecord);
        abort_if($attendanceRecord->photoPaths() === [], 404);

        $attendanceRecord->update([
            'photo_held_at' => now(),
            'photo_held_by' => $request->user()?->id,
            'photo_hold_reason' => $request->validated('reason'),
        ]);

        AuditLog::record('attendance.photo_held', $attendanceRecord, ['reason' => $request->validated('reason')]);

        $request->attributes->set('photo_retention_days', $this->retention->retentionDays());

        return new AttendancePhotoResource($attendanceRecord->fresh(['employee', 'photoHolder.employee']));
    }

    /**
     * Releases a hold. The review starts again on the next run, so the photos
     * are announced once more before any deletion.
     */
    public function release(Request $request, AttendanceRecord $attendanceRecord): AttendancePhotoResource
    {
        Gate::authorize('attendance.manage');
        $this->authorizeRecord($request, $attendanceRecord);
        abort_if($attendanceRecord->photo_held_at === null, 404);

        $attendanceRecord->update([
            'photo_held_at' => null,
            'photo_held_by' => null,
            'photo_hold_reason' => null,
            'photo_review_started_at' => null,
        ]);

        AuditLog::record('attendance.photo_hold_released', $attendanceRecord);

        $request->attributes->set('photo_retention_days', $this->retention->retentionDays());

        return new AttendancePhotoResource($attendanceRecord->fresh(['employee', 'photoHolder.employee']));
    }

    /** The photo ability, and the record inside the caller's scope. */
    private function authorizeRecord(Request $request, AttendanceRecord $record): void
    {
        Gate::authorize('attendance.viewPhotos');

        /** @var User $user */
        $user = $request->user();
        $record->loadMissing('employee');

        abort_unless($record->employee !== null && $user->canAccessEmployee($record->employee), 404);
    }
}
