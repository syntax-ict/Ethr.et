<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\StoreEducationRequest;
use App\Http\Resources\EducationResource;
use App\Models\Employee;
use App\Models\EmployeeEducation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class EducationController extends Controller
{
    public function index(Employee $employee): AnonymousResourceCollection
    {
        Gate::authorize('employee.view');

        return EducationResource::collection(
            $employee->education()->orderByDesc('end_date')->get()
        );
    }

    public function store(StoreEducationRequest $request, Employee $employee): JsonResponse
    {
        Gate::authorize('employee.update');

        $education = $employee->education()->create($request->validated());

        return (new EducationResource($education))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreEducationRequest $request, Employee $employee, EmployeeEducation $education): EducationResource
    {
        Gate::authorize('employee.update');

        $education->update($request->validated());

        return new EducationResource($education);
    }

    public function destroy(Employee $employee, EmployeeEducation $education): JsonResponse
    {
        Gate::authorize('employee.update');

        $education->delete();

        return response()->json(null, 204);
    }
}
