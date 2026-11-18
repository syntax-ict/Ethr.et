<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\ConflictResolutionStatus;
use App\Enums\OrgScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\JoinConflictsRequest;
use App\Http\Requests\Attendance\ResolveConflictRequest;
use App\Http\Resources\AttendanceConflictResource;
use App\Models\AttendanceConflict;
use App\Models\AuditLog;
use App\Services\Attendance\SplitDayJoiner;
use App\Services\Payroll\PayrollPeriodLock;
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

        // Limited to the employees the caller may see, as every sibling
        // attendance list is (AttendanceController::index). The stock roles
        // holding attendance.viewConflicts see everyone anyway; a custom role
        // granted it with a narrower org scope used to see the whole tenant.
        $user = $request->user();
        if ($user->orgScope() !== OrgScope::ALL) {
            $query->whereHas('employee', fn ($q) => $user->scopeAccessibleEmployees($q));
        }

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
        // The permission and the employee inside the caller's org scope — the
        // same reach index() lists — and never the caller's own conflict.
        Gate::authorize('resolve', $conflict);

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

        // A pair that is one working day split by a break is joined into one
        // record; any other merged pair changes no record, as it always did.
        $joiner = app(SplitDayJoiner::class);
        $pair = $resolution === ConflictResolutionStatus::MERGED ? $joiner->pair($conflict) : null;
        if ($pair !== null) {
            app(PayrollPeriodLock::class)->assertOpen($pair[0]->employee_id, $pair[0]->date);

            if ($joiner->join($conflict, $user, $request->validated('resolution_notes')) !== null) {
                abort(409, __('attendance.conflict_already_resolved'));
            }

            return $this->resolved($conflict->fresh() ?? $conflict);
        }

        // Voiding either record of a paid day would change what a reprocess pays.
        $subject = $conflict->recordA ?? $conflict->recordB;
        if ($subject !== null && in_array($resolution, [ConflictResolutionStatus::KEEP_A, ConflictResolutionStatus::KEEP_B], true)) {
            app(PayrollPeriodLock::class)->assertOpen($subject->employee_id, $subject->date);
        }

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

        return $this->resolved($conflict);
    }

    public function join(JoinConflictsRequest $request, SplitDayJoiner $joiner): JsonResponse
    {
        Gate::authorize('attendance.resolveConflicts');

        $user = $request->user();
        $ids = $request->input('conflict_ids');

        $query = AttendanceConflict::query()->with(['employee', 'recordA', 'recordB']);

        // The reach index() lists. resolve() is asked again for every
        // conflict, which also refuses the caller's own.
        if ($user->orgScope() !== OrgScope::ALL) {
            $query->whereHas('employee', fn ($q) => $user->scopeAccessibleEmployees($q));
        }

        if (is_array($ids)) {
            $query->whereIn('public_id', $ids);
        } else {
            $query->where('resolution', ConflictResolutionStatus::PENDING)
                ->whereHas('employee', fn ($q) => $q->where('public_id', $request->input('employee_public_id')));
        }

        $after = $request->input('after');
        $afterId = is_string($after) ? (int) AttendanceConflict::query()->where('public_id', $after)->value('id') : 0;

        $result = $joiner->joinMany($query, $user, $afterId);

        $left = $result['left'];
        if (is_array($ids) && $afterId === 0) {
            $found = (clone $query)->whereIn('public_id', $ids)->pluck('public_id')->all();
            foreach (array_diff($ids, $found) as $missing) {
                $left[] = ['conflict_id' => $missing, 'reason' => SplitDayJoiner::NOT_FOUND];
            }
        }

        return response()->json([
            'joined' => $result['joined'],
            'left' => $left,
            'remaining' => $result['remaining'],
            'next_after' => $result['remaining'] > 0 ? $result['last']?->public_id : null,
        ]);
    }

    private function resolved(AttendanceConflict $conflict): JsonResponse
    {
        $conflict->load([
            'employee',
            'recordA.employee', 'recordA.shift',
            'recordB.employee', 'recordB.shift',
            'resolvedByUser',
        ]);

        return response()->json(new AttendanceConflictResource($conflict));
    }
}
