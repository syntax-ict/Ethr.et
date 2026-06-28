<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreCostCenterRequest;
use App\Http\Requests\Organization\UpdateCostCenterRequest;
use App\Http\Resources\CostCenterResource;
use App\Models\AuditLog;
use App\Models\CostCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CostCenterController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('org.viewAny');

        $query = CostCenter::query()->withCount('employees');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        $query->orderBy('name');

        return CostCenterResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreCostCenterRequest $request): JsonResponse
    {
        Gate::authorize('org.create');

        $costCenter = CostCenter::create($request->validated());

        AuditLog::record('cost_center.created', $costCenter);

        return (new CostCenterResource($costCenter))
            ->response()
            ->setStatusCode(201);
    }

    public function show(CostCenter $costCenter): CostCenterResource
    {
        Gate::authorize('org.view');

        $costCenter->loadCount('employees');

        return new CostCenterResource($costCenter);
    }

    public function update(UpdateCostCenterRequest $request, CostCenter $costCenter): CostCenterResource
    {
        Gate::authorize('org.update');

        $costCenter->update($request->validated());

        AuditLog::record('cost_center.updated', $costCenter);

        return new CostCenterResource($costCenter);
    }

    public function destroy(CostCenter $costCenter): JsonResponse
    {
        Gate::authorize('org.delete');

        $costCenter->delete();

        AuditLog::record('cost_center.deleted', $costCenter);

        return response()->json(null, 204);
    }
}
