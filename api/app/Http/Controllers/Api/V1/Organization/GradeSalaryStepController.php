<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreGradeSalaryStepRequest;
use App\Http\Requests\Organization\UpdateGradeSalaryStepRequest;
use App\Http\Resources\GradeSalaryStepResource;
use App\Models\AuditLog;
use App\Models\Grade;
use App\Models\GradeSalaryStep;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class GradeSalaryStepController extends Controller
{
    public function index(Grade $grade): AnonymousResourceCollection
    {
        Gate::authorize('org.viewAny');

        return GradeSalaryStepResource::collection($grade->salarySteps);
    }

    public function store(StoreGradeSalaryStepRequest $request, Grade $grade): JsonResponse
    {
        Gate::authorize('org.create');

        $step = $grade->salarySteps()->create($request->validated());

        AuditLog::record('grade_salary_step.created', $step, [
            'grade_id' => $grade->public_id,
            'step' => $step->step,
        ]);

        return (new GradeSalaryStepResource($step))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateGradeSalaryStepRequest $request, Grade $grade, GradeSalaryStep $salaryStep): GradeSalaryStepResource
    {
        Gate::authorize('org.update');

        $salaryStep->update($request->validated());

        AuditLog::record('grade_salary_step.updated', $salaryStep, [
            'grade_id' => $grade->public_id,
            'step' => $salaryStep->step,
        ]);

        return new GradeSalaryStepResource($salaryStep);
    }

    public function destroy(Grade $grade, GradeSalaryStep $salaryStep): JsonResponse
    {
        Gate::authorize('org.delete');

        $salaryStep->delete();

        AuditLog::record('grade_salary_step.deleted', $grade, [
            'step' => $salaryStep->step,
        ]);

        return response()->json(null, 204);
    }
}
