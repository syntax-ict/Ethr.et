<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shift;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shift\AssignShiftRotationRequest;
use App\Http\Requests\Shift\PreviewShiftRotationRequest;
use App\Http\Requests\Shift\StoreShiftRotationRequest;
use App\Http\Resources\ShiftAssignmentResource;
use App\Http\Resources\ShiftRotationResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationStep;
use App\Services\Shift\ShiftRotationResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ShiftRotationController extends Controller
{
    public function __construct(private readonly ShiftRotationResolver $resolver) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('shift.viewAny');

        $rotations = ShiftRotation::query()
            ->with('steps.shift')
            ->withCount('assignments')
            ->orderBy('name')
            ->paginate(request()->integer('per_page', 25));

        return ShiftRotationResource::collection($rotations);
    }

    public function show(ShiftRotation $rotation): ShiftRotationResource
    {
        Gate::authorize('view', $rotation);

        return new ShiftRotationResource($rotation->load('steps.shift'));
    }

    public function store(StoreShiftRotationRequest $request): JsonResponse
    {
        Gate::authorize('create', ShiftRotation::class);

        $rotation = DB::transaction(function () use ($request) {
            $rotation = ShiftRotation::create([
                'name' => $request->validated('name'),
                'name_am' => $request->validated('name_am'),
                'description' => $request->validated('description'),
                'cycle_days' => $request->validated('cycle_days'),
                'is_active' => $request->validated('is_active') ?? true,
            ]);

            $this->replaceSteps($rotation, $request->validated('steps'));

            return $rotation;
        });

        AuditLog::record('shift_rotation.created', $rotation, [
            'cycle_days' => $rotation->cycle_days,
        ]);

        return (new ShiftRotationResource($rotation->load('steps.shift')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreShiftRotationRequest $request, ShiftRotation $rotation): ShiftRotationResource
    {
        Gate::authorize('update', $rotation);

        DB::transaction(function () use ($request, $rotation) {
            $rotation->update([
                'name' => $request->validated('name'),
                'name_am' => $request->validated('name_am'),
                'description' => $request->validated('description'),
                'cycle_days' => $request->validated('cycle_days'),
                'is_active' => $request->validated('is_active') ?? $rotation->is_active,
            ]);

            // Steps are replaced wholesale rather than diffed: the pattern is
            // meaningful only as a complete set, and a partial update that left
            // a stale offset behind would silently change what people work.
            $this->replaceSteps($rotation, $request->validated('steps'));
        });

        AuditLog::record('shift_rotation.updated', $rotation);

        return new ShiftRotationResource($rotation->fresh()->load('steps.shift'));
    }

    public function destroy(ShiftRotation $rotation): JsonResponse
    {
        Gate::authorize('delete', $rotation);

        $rotation->delete();

        AuditLog::record('shift_rotation.deleted', $rotation);

        return response()->json(null, 204);
    }

    /**
     * Assign a rotation to an employee, department or branch. Written to the
     * same `shift_assignments` table as a plain shift so that resolving "what
     * is this person working" has a single source.
     */
    public function assign(AssignShiftRotationRequest $request): JsonResponse
    {
        Gate::authorize('shift.create');

        $rotation = ShiftRotation::where('public_id', $request->validated('rotation_id'))->firstOrFail();

        // The default arm is unreachable while the FormRequest's `in:` rule
        // holds, which is the point: if that rule is ever widened without this
        // match being updated, an UnhandledMatchError here is a loud failure
        // rather than a silently unassigned rotation.
        $assignableType = match ($request->validated('assignable_type')) {
            'employee' => Employee::class,
            'department' => Department::class,
            'branch' => Branch::class,
            default => throw new \InvalidArgumentException('Unsupported assignable type.'),
        };

        $assignable = $assignableType::where('public_id', $request->validated('assignable_id'))->firstOrFail();

        $assignment = ShiftAssignment::create([
            'tenant_id' => $rotation->tenant_id,
            'shift_id' => null,
            'shift_rotation_id' => $rotation->id,
            'assignable_type' => $assignableType,
            'assignable_id' => $assignable->id,
            'effective_from' => $request->validated('effective_from'),
            'effective_to' => $request->validated('effective_to'),
            'anchor_date' => $request->validated('anchor_date') ?? $request->validated('effective_from'),
        ]);

        AuditLog::record('shift_rotation.assigned', $rotation, [
            'assignable_type' => $request->validated('assignable_type'),
            'assignable_public_id' => $request->validated('assignable_id'),
        ]);

        return (new ShiftAssignmentResource($assignment->load('rotation.steps.shift')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The resolved day-by-day pattern for a rotation over a date range — what a
     * roster view needs, and the only way to see what a cycle actually produces
     * without recomputing the modulo arithmetic client-side.
     */
    public function preview(PreviewShiftRotationRequest $request, ShiftRotation $rotation): JsonResponse
    {
        Gate::authorize('view', $rotation);

        $from = Carbon::parse((string) $request->validated('from'));
        $to = Carbon::parse((string) $request->validated('to'));

        // Bounded so a caller cannot ask for a decade and time the request out.
        if ($from->diffInDays($to) > 366) {
            return response()->json([
                'type' => 'https://ethr.et/errors/range-too-large',
                'title' => 'Range Too Large',
                'status' => 422,
                'detail' => 'Preview range may not exceed 366 days.',
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $anchorDate = $request->validated('anchor_date');
        $anchor = $anchorDate !== null ? Carbon::parse((string) $anchorDate) : $from;

        $rotation->load('steps.shift');

        $days = [];
        foreach ($this->resolver->scheduleFor($rotation, $anchor, $from, $to) as $date => $shift) {
            $days[] = [
                'date' => $date,
                'shift' => $shift ? [
                    'public_id' => $shift->public_id,
                    'name' => $shift->name,
                    'start_time' => $shift->start_time,
                    'end_time' => $shift->end_time,
                ] : null,
                'is_rest_day' => $shift === null,
            ];
        }

        return response()->json([
            'rotation' => new ShiftRotationResource($rotation),
            'anchor_date' => $anchor->format('Y-m-d'),
            'days' => $days,
        ]);
    }

    /**
     * @param  array<int, array{day_offset: int|string, shift_id?: string|null}>  $steps
     */
    private function replaceSteps(ShiftRotation $rotation, array $steps): void
    {
        $rotation->steps()->delete();

        // Resolved in one query rather than per step: a 21-day rotation would
        // otherwise issue 21 lookups for what is usually three distinct shifts.
        $publicIds = array_values(array_filter(array_map(
            static fn (array $step): ?string => $step['shift_id'] ?? null,
            $steps,
        )));

        $shiftIds = $publicIds === []
            ? collect()
            : Shift::whereIn('public_id', $publicIds)->pluck('id', 'public_id');

        foreach ($steps as $step) {
            ShiftRotationStep::create([
                'tenant_id' => $rotation->tenant_id,
                'shift_rotation_id' => $rotation->id,
                'day_offset' => (int) $step['day_offset'],
                'shift_id' => isset($step['shift_id']) ? $shiftIds[$step['shift_id']] ?? null : null,
            ]);
        }
    }
}
