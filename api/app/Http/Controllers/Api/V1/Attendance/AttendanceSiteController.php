<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\SaveAttendanceSiteRequest;
use App\Http\Resources\AttendanceSiteResource;
use App\Models\AttendanceSite;
use App\Models\AttendanceSiteAssignment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Work sites: places besides the branch where people may punch
 * (AttendancePlaces).
 */
class AttendanceSiteController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('attendance.manage');

        return AttendanceSiteResource::collection(
            AttendanceSite::query()->with(['branch', 'assignments'])->orderBy('name')->get()
        );
    }

    public function store(SaveAttendanceSiteRequest $request): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $site = DB::transaction(function () use ($request): AttendanceSite {
            $site = AttendanceSite::create($this->attributes($request));
            $this->syncAssignments($site, $request);

            return $site;
        });

        AuditLog::record('attendance.site_created', $site);

        return (new AttendanceSiteResource($site->load(['branch', 'assignments'])))->response()->setStatusCode(201);
    }

    public function update(SaveAttendanceSiteRequest $request, AttendanceSite $site): AttendanceSiteResource
    {
        Gate::authorize('attendance.manage');

        DB::transaction(function () use ($request, $site): void {
            $site->update($this->attributes($request));
            $this->syncAssignments($site, $request);
        });

        AuditLog::record('attendance.site_updated', $site);

        return new AttendanceSiteResource($site->fresh(['branch', 'assignments']));
    }

    public function destroy(AttendanceSite $site): JsonResponse
    {
        Gate::authorize('attendance.manage');

        // Soft-deleted: punches recorded there keep a place to point at.
        $site->delete();

        AuditLog::record('attendance.site_deleted', $site);

        return response()->json(null, 204);
    }

    /**
     * The map settings a picker needs: the platform's Google Maps key, if the
     * platform has one. It is a browser key by Google's design.
     */
    public function mapConfig(): JsonResponse
    {
        Gate::authorize('attendance.manage');

        $key = (string) PlatformSetting::query()->value('google_maps_key');

        return response()->json([
            'google_maps_key' => $key !== '' ? $key : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function attributes(SaveAttendanceSiteRequest $request): array
    {
        $data = $request->safe()->only(['name', 'name_am', 'latitude', 'longitude', 'radius_meters', 'is_active']);

        if ($request->has('branch_public_id')) {
            $public = $request->validated('branch_public_id');
            $data['branch_id'] = is_string($public) && $public !== ''
                ? Branch::query()->where('public_id', $public)->value('id')
                : null;
        }

        return $data;
    }

    private function syncAssignments(AttendanceSite $site, SaveAttendanceSiteRequest $request): void
    {
        foreach (['employee_public_ids' => Employee::class, 'department_public_ids' => Department::class] as $field => $model) {
            if (! $request->has($field)) {
                continue;
            }

            $ids = $model::query()->whereIn('public_id', (array) $request->validated($field, []))->pluck('id');

            AttendanceSiteAssignment::query()
                ->where('attendance_site_id', $site->id)
                ->where('assignable_type', $model)
                ->whereNotIn('assignable_id', $ids)
                ->delete();

            foreach ($ids as $id) {
                AttendanceSiteAssignment::query()->firstOrCreate([
                    'attendance_site_id' => $site->id,
                    'assignable_type' => $model,
                    'assignable_id' => $id,
                ]);
            }
        }
    }
}
