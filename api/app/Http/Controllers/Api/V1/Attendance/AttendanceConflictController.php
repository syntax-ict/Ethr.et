<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\ConflictResolutionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ResolveConflictRequest;
use App\Http\Resources\AttendanceConflictResource;
use App\Models\AttendanceConflict;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AttendanceConflictController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('attendance.viewConflicts');

        $query = AttendanceConflict::query()
            // recordA/recordB render through AttendanceRecordResource, which
            // needs ('employee', 'shift') loaded the same way every other
            // attendance controller loads it — it reads ->employee unguarded
            // and workedMinutes() reaches through ->shift.
            ->with([
                'employee',
                'recordA.employee', 'recordA.shift',
                'recordB.employee', 'recordB.shift',
                'resolvedByUser',
            ]);

        if ($request->has('filter.resolution')) {
            $query->where('resolution', $request->input('filter.resolution'));
        }

        if ($request->has('filter.conflict_type')) {
            $query->where('conflict_type', $request->input('filter.conflict_type'));
        }

        if ($request->has('filter.employee_public_id')) {
            $query->whereHas('employee', fn ($q) => $q->where('public_id', $request->input('filter.employee_public_id')));
        }

        $query->orderByDesc('created_at');

        return AttendanceConflictResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function resolve(ResolveConflictRequest $request, AttendanceConflict $conflict): JsonResponse
    {
        Gate::authorize('attendance.resolveConflicts');

        if ($conflict->resolution !== ConflictResolutionStatus::PENDING) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-state',
                'title' => 'Invalid State',
                'status' => 422,
                'detail' => __('attendance.conflict_already_resolved'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $resolution = ConflictResolutionStatus::from($request->validated('resolution'));
        $user = $request->user();

        $conflict->update([
            'resolution' => $resolution,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
            'resolution_notes' => $request->validated('resolution_notes'),
        ]);

        if ($resolution === ConflictResolutionStatus::KEEP_A) {
            $conflict->recordB?->update(['status' => AttendanceStatus::VOIDED, 'metadata' => array_merge(
                $conflict->recordB->metadata ?? [],
                ['voided_reason' => 'conflict_resolved', 'conflict_id' => $conflict->public_id],
            )]);
        } elseif ($resolution === ConflictResolutionStatus::KEEP_B) {
            $conflict->recordA?->update(['status' => AttendanceStatus::VOIDED, 'metadata' => array_merge(
                $conflict->recordA->metadata ?? [],
                ['voided_reason' => 'conflict_resolved', 'conflict_id' => $conflict->public_id],
            )]);
        }

        AuditLog::record('attendance.conflict_resolved', $conflict, [
            'resolution' => $resolution->value,
            'resolved_by' => $user->id,
        ]);

        $conflict->load([
            'employee',
            'recordA.employee', 'recordA.shift',
            'recordB.employee', 'recordB.shift',
            'resolvedByUser',
        ]);

        return response()->json(new AttendanceConflictResource($conflict));
    }
}
