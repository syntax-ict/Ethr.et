<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Shift;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shift\EndShiftAssignmentRequest;
use App\Http\Resources\ShiftAssignmentResource;
use App\Models\AuditLog;
use App\Models\ShiftAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Ending or removing one shift or rotation assignment. Until these existed an
 * assignment, once made, could not be changed by anyone: the resource had no
 * `public_id` to address it by.
 *
 * The two are split on purpose. An assignment that has taken effect is
 * history — attendance on those days was matched against it — so it is ended
 * (its last day set), never deleted. Only one that has not started yet can be
 * removed outright, which is how a mistake made today for next month is undone.
 *
 * Route-model binding resolves `{assignment}` through the model's tenant
 * scope, so another tenant's `public_id` is a 404 like any unknown one.
 */
class ShiftAssignmentController extends Controller
{
    public function update(EndShiftAssignmentRequest $request, ShiftAssignment $assignment): ShiftAssignmentResource
    {
        Gate::authorize('shift.update');

        $previous = $assignment->effective_to?->format('Y-m-d');

        $assignment->update(['effective_to' => $request->validated('effective_to')]);

        AuditLog::record('shift.assignment_ended', $assignment, [
            'effective_to' => $request->validated('effective_to'),
            'previous_effective_to' => $previous,
        ]);

        return new ShiftAssignmentResource($assignment->load(ShiftAssignment::resourceRelations()));
    }

    public function destroy(ShiftAssignment $assignment): JsonResponse
    {
        Gate::authorize('shift.delete');

        $today = ShiftAssignment::tenantToday();

        if ($assignment->effective_from !== null && $assignment->effective_from->format('Y-m-d') <= $today) {
            return response()->json([
                'type' => 'https://ethr.et/errors/assignment-in-effect',
                'title' => 'Assignment Already In Effect',
                'status' => 409,
                'detail' => 'This assignment has already taken effect, so it is part of the attendance history. End it instead: set its last day.',
            ], 409)->header('Content-Type', 'application/problem+json');
        }

        $assignment->delete();

        AuditLog::record('shift.assignment_deleted', $assignment);

        return response()->json(null, 204);
    }
}
