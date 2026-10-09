<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shift;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shift\AssignShiftRequest;
use App\Http\Requests\Shift\StoreShiftRequest;
use App\Http\Requests\Shift\UpdateShiftRequest;
use App\Http\Resources\ShiftAssignmentResource;
use App\Http\Resources\ShiftResource;
use App\Models\AuditLog;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRotation;
use App\Support\ShiftAssignables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ShiftController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('shift.viewAny');

        $query = Shift::query()->withCount('assignments');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        $query->orderBy('name');

        return ShiftResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreShiftRequest $request): JsonResponse
    {
        Gate::authorize('shift.create');

        $shift = Shift::create($request->validated());

        if ($shift->is_default) {
            Shift::where('id', '!=', $shift->id)
                ->where('tenant_id', $shift->tenant_id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        AuditLog::record('shift.created', $shift);

        return (new ShiftResource($shift))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Shift $shift): ShiftResource
    {
        Gate::authorize('shift.view');

        $shift->loadCount('assignments');

        return new ShiftResource($shift);
    }

    public function update(UpdateShiftRequest $request, Shift $shift): ShiftResource
    {
        Gate::authorize('shift.update');

        $shift->update($request->validated());

        if ($shift->is_default) {
            Shift::where('id', '!=', $shift->id)
                ->where('tenant_id', $shift->tenant_id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        AuditLog::record('shift.updated', $shift);

        return new ShiftResource($shift);
    }

    public function destroy(Shift $shift): JsonResponse
    {
        Gate::authorize('shift.delete');

        // A soft-deleted shift drops out of attendance matching (ShiftMatcher
        // only matches a shift it can load), so deleting one that is still
        // assigned silently moved its people onto their department's, branch's
        // or the default shift — and a rotation step naming it became a rest
        // day. Refused while anything current or future depends on it; an
        // assignment that ended before today is history and does not block.
        $inForce = $shift->assignments()
            ->inForceOnOrAfter(ShiftAssignment::tenantToday())
            ->count();
        $rotations = ShiftRotation::query()
            ->whereHas('steps', fn ($q) => $q->where('shift_id', $shift->id))
            ->orderBy('name')
            ->pluck('name');

        if ($inForce > 0 || $rotations->isNotEmpty()) {
            $reasons = [];
            if ($inForce > 0) {
                $reasons[] = $inForce === 1
                    ? '1 assignment is current or upcoming'
                    : "{$inForce} assignments are current or upcoming";
            }
            if ($rotations->isNotEmpty()) {
                $reasons[] = 'it is a step in the rotation(s) '.$rotations->implode(', ');
            }

            return response()->json([
                'type' => 'https://ethr.et/errors/shift-in-use',
                'title' => 'Shift In Use',
                'status' => 409,
                'detail' => 'This shift cannot be deleted: '.implode(', and ', $reasons)
                    .'. End or reassign those first, or mark the shift inactive.',
            ], 409)->header('Content-Type', 'application/problem+json');
        }

        $shift->delete();

        AuditLog::record('shift.deleted', $shift);

        return response()->json(null, 204);
    }

    public function assign(AssignShiftRequest $request): JsonResponse
    {
        Gate::authorize('shift.create');

        $shift = Shift::where('public_id', $request->validated('shift_public_id'))->firstOrFail();

        $assignableType = ShiftAssignables::modelFor($request->validated('assignable_type'))
            ?? throw new \InvalidArgumentException('Unsupported assignable type.');

        $assignable = $assignableType::where('public_id', $request->validated('assignable_public_id'))->firstOrFail();

        $assignment = ShiftAssignment::create([
            'tenant_id' => $shift->tenant_id,
            'shift_id' => $shift->id,
            'assignable_type' => $assignableType,
            'assignable_id' => $assignable->id,
            'effective_from' => $request->validated('effective_from'),
            'effective_to' => $request->validated('effective_to'),
        ]);

        $assignment->load(ShiftAssignment::resourceRelations());

        AuditLog::record('shift.assigned', $shift, [
            'assignable_type' => $request->validated('assignable_type'),
            'assignable_public_id' => $request->validated('assignable_public_id'),
        ]);

        return (new ShiftAssignmentResource($assignment))
            ->response()
            ->setStatusCode(201);
    }

    public function schedule(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('shift.viewAny');

        $query = ShiftAssignment::query()
            ->with(ShiftAssignment::resourceRelations());

        if ($request->filled('filter.date_from')) {
            $query->where(function ($q) use ($request) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $request->input('filter.date_from'));
            });
        }

        if ($request->filled('filter.date_to')) {
            $query->where('effective_from', '<=', $request->input('filter.date_to'));
        }

        if ($request->filled('filter.assignable_type')) {
            $type = ShiftAssignables::modelFor($request->input('filter.assignable_type'));
            if ($type) {
                $query->where('assignable_type', $type);
            }
        }

        $query->orderBy('effective_from');

        return ShiftAssignmentResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }
}
