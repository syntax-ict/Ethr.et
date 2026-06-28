<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Enums\EmployeeStatus;
use App\Events\EmployeeTransitioned;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\TransitionEmployeeRequest;
use App\Http\Resources\EmployeeTransitionResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeTransition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeTransitionController extends Controller
{
    public function store(TransitionEmployeeRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('employee.transition');

        $toStatus = EmployeeStatus::from($request->validated('to_status'));

        if (! $employee->status->canTransitionTo($toStatus)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-transition',
                'title' => 'Invalid Status Transition',
                'status' => 422,
                'detail' => "Cannot transition from {$employee->status->value} to {$toStatus->value}.",
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $transition = EmployeeTransition::create([
            'employee_id' => $employee->id,
            'from_status' => $employee->status->value,
            'to_status' => $toStatus->value,
            'reason' => $request->validated('reason'),
            'effective_date' => $request->validated('effective_date'),
            'approved_by' => auth()->id(),
        ]);

        $employee->status = $toStatus;

        if ($toStatus === EmployeeStatus::CONFIRMED) {
            $employee->confirmation_date = $request->validated('effective_date');
        }

        if (in_array($toStatus, [EmployeeStatus::RESIGNED, EmployeeStatus::TERMINATED, EmployeeStatus::RETIRED], true)) {
            $employee->termination_date = $request->validated('effective_date');
        }

        $employee->save();

        AuditLog::record('employee.transitioned', $employee, [
            'from' => $transition->from_status->value,
            'to' => $transition->to_status->value,
            'reason' => $transition->reason,
        ]);

        EmployeeTransitioned::dispatch($employee, $transition);

        return (new EmployeeTransitionResource($transition))
            ->response()
            ->setStatusCode(201);
    }

    public function index(Employee $employee): AnonymousResourceCollection
    {
        Gate::authorize('employee.view');

        return EmployeeTransitionResource::collection(
            $employee->transitions()->with('approvedBy')->get()
        );
    }
}
