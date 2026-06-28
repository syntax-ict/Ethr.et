<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreBranchRequest;
use App\Http\Requests\Organization\UpdateBranchRequest;
use App\Http\Resources\BranchResource;
use App\Models\AuditLog;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class BranchController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('org.viewAny');

        $query = Branch::query()
            ->withCount(['departments', 'employees']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        $sortField = $request->input('sort', 'name');
        $sortDir = str_starts_with($sortField, '-') ? 'desc' : 'asc';
        $sortField = ltrim($sortField, '-');
        $allowed = ['name', 'code', 'city', 'created_at'];
        if (in_array($sortField, $allowed, true)) {
            $query->orderBy($sortField, $sortDir);
        }

        return BranchResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreBranchRequest $request): JsonResponse
    {
        Gate::authorize('org.create');

        $branch = Branch::create($request->validated());

        AuditLog::record('branch.created', $branch);

        return (new BranchResource($branch))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Branch $branch): BranchResource
    {
        Gate::authorize('org.view');

        $branch->loadCount(['departments', 'employees']);

        return new BranchResource($branch);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): BranchResource
    {
        Gate::authorize('org.update');

        $branch->update($request->validated());

        AuditLog::record('branch.updated', $branch);

        return new BranchResource($branch);
    }

    public function destroy(Branch $branch): JsonResponse
    {
        Gate::authorize('org.delete');

        $branch->delete();

        AuditLog::record('branch.deleted', $branch);

        return response()->json(null, 204);
    }
}
