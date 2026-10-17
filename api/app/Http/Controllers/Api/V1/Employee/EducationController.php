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

class EducationController extends Controller
{
    public function index(Employee $employee): AnonymousResourceCollection
    {
        $this->authorize('view', $employee);

        return EducationResource::collection(
            $employee->education()->orderByDesc('end_date')->get()
        );
    }

    public function store(StoreEducationRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        $education = $employee->education()->create($request->validated());

        return (new EducationResource($education))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreEducationRequest $request, Employee $employee, EmployeeEducation $education): EducationResource
    {
        $this->authorize('update', $employee);

        $education->update($request->validated());

        return new EducationResource($education);
    }

    public function destroy(Employee $employee, EmployeeEducation $education): JsonResponse
    {
        $this->authorize('update', $employee);

        $education->delete();

        return response()->json(null, 204);
    }
}
