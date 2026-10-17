<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Enums\EmployeeStatus;
use App\Exceptions\InvalidEmployeeTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\TransitionEmployeeRequest;
use App\Http\Resources\EmployeeTransitionResource;
use App\Models\Employee;
use App\Services\EmployeeTransitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EmployeeTransitionController extends Controller
{
    public function __construct(private readonly EmployeeTransitionService $transitions) {}

    public function store(TransitionEmployeeRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('employee.transition');

        $toStatus = EmployeeStatus::from($request->validated('to_status'));

        try {
            $transition = $this->transitions->transition(
                $employee,
                $toStatus,
                $request->validated('effective_date'),
                $request->validated('reason'),
                auth()->id(),
            );
        } catch (InvalidEmployeeTransition $e) {
            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-transition',
                'title' => 'Invalid Status Transition',
                'status' => 422,
                'detail' => "Cannot transition from {$e->from->value} to {$e->to->value}.",
            ], 422)->header('Content-Type', 'application/problem+json');
        }

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
