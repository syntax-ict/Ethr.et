<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreGradeRequest;
use App\Http\Requests\Organization\UpdateGradeRequest;
use App\Http\Resources\GradeResource;
use App\Models\AuditLog;
use App\Models\Grade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class GradeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('org.viewAny');

        $query = Grade::query()
            ->withCount('employees')
            ->orderBy('sort_order');

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->input('search')}%");
        }

        return GradeResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreGradeRequest $request): JsonResponse
    {
        Gate::authorize('org.create');

        $grade = Grade::create($request->validated());

        AuditLog::record('grade.created', $grade);

        return (new GradeResource($grade))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Grade $grade): GradeResource
    {
        Gate::authorize('org.view');

        $grade->loadCount('employees');

        return new GradeResource($grade);
    }

    public function update(UpdateGradeRequest $request, Grade $grade): GradeResource
    {
        Gate::authorize('org.update');

        $grade->update($request->validated());

        AuditLog::record('grade.updated', $grade);

        return new GradeResource($grade);
    }

    public function destroy(Grade $grade): JsonResponse
    {
        Gate::authorize('org.delete');

        $grade->delete();

        AuditLog::record('grade.deleted', $grade);

        return response()->json(null, 204);
    }
}
