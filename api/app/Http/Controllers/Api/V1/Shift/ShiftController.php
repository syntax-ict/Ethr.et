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
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
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

        $shift->delete();

        AuditLog::record('shift.deleted', $shift);

        return response()->json(null, 204);
    }

    public function assign(AssignShiftRequest $request): JsonResponse
    {
        Gate::authorize('shift.create');

        $shift = Shift::where('public_id', $request->validated('shift_public_id'))->firstOrFail();

        $assignableType = match ($request->validated('assignable_type')) {
            'employee' => Employee::class,
            'department' => Department::class,
            'branch' => Branch::class,
        };

        $assignable = $assignableType::where('public_id', $request->validated('assignable_public_id'))->firstOrFail();

        $assignment = ShiftAssignment::create([
            'tenant_id' => $shift->tenant_id,
            'shift_id' => $shift->id,
            'assignable_type' => $assignableType,
            'assignable_id' => $assignable->id,
            'effective_from' => $request->validated('effective_from'),
            'effective_to' => $request->validated('effective_to'),
        ]);

        $assignment->load('shift');

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
            ->with('shift');

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
            $type = match ($request->input('filter.assignable_type')) {
                'employee' => Employee::class,
                'department' => Department::class,
                'branch' => Branch::class,
                default => null,
            };
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
