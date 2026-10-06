<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreDepartmentRequest;
use App\Http\Requests\Organization\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('org.viewAny');

        $query = Department::query()
            ->with(['parent', 'branch'])
            ->withCount('employees');

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

        if ($request->filled('filter.branch_public_id')) {
            $branch = Branch::where('public_id', $request->input('filter.branch_public_id'))->first();
            if ($branch) {
                $query->where('branch_id', $branch->id);
            }
        }

        if ($request->boolean('filter.root_only')) {
            $query->whereNull('parent_id');
        }

        $sortField = $request->input('sort', 'name');
        $sortDir = str_starts_with($sortField, '-') ? 'desc' : 'asc';
        $sortField = ltrim($sortField, '-');
        $allowed = ['name', 'code', 'created_at'];
        if (in_array($sortField, $allowed, true)) {
            $query->orderBy($sortField, $sortDir);
        }

        return DepartmentResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        Gate::authorize('org.create');

        $data = $request->validated();

        if (! empty($data['parent_public_id'])) {
            $parent = Department::where('public_id', $data['parent_public_id'])->firstOrFail();
            $data['parent_id'] = $parent->id;
        }
        unset($data['parent_public_id']);

        if (! empty($data['branch_public_id'])) {
            $branch = Branch::where('public_id', $data['branch_public_id'])->firstOrFail();
            $data['branch_id'] = $branch->id;
        }
        unset($data['branch_public_id']);

        $department = Department::create($data);
        $department->load(['parent', 'branch']);

        AuditLog::record('department.created', $department);

        return (new DepartmentResource($department))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Department $department): DepartmentResource
    {
        Gate::authorize('org.view');

        $department->load(['parent', 'branch', 'children']);
        $department->loadCount('employees');

        return new DepartmentResource($department);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): DepartmentResource
    {
        Gate::authorize('org.update');

        $data = $request->validated();

        if (array_key_exists('parent_public_id', $data)) {
            if ($data['parent_public_id']) {
                $parent = Department::where('public_id', $data['parent_public_id'])->firstOrFail();
                $data['parent_id'] = $parent->id;
            } else {
                $data['parent_id'] = null;
            }
        }
        unset($data['parent_public_id']);

        if (array_key_exists('branch_public_id', $data)) {
            if ($data['branch_public_id']) {
                $branch = Branch::where('public_id', $data['branch_public_id'])->firstOrFail();
                $data['branch_id'] = $branch->id;
            } else {
                $data['branch_id'] = null;
            }
        }
        unset($data['branch_public_id']);

        $department->update($data);
        $department->load(['parent', 'branch']);

        AuditLog::record('department.updated', $department);

        return new DepartmentResource($department);
    }

    public function destroy(Department $department): JsonResponse
    {
        Gate::authorize('org.delete');

        $department->delete();

        AuditLog::record('department.deleted', $department);

        return response()->json(null, 204);
    }

    public function tree(): JsonResponse
    {
        Gate::authorize('org.viewAny');

        $departments = Department::whereNull('parent_id')
            ->with(['childrenRecursive', 'branch'])
            ->withCount('employees')
            ->orderBy('name')
            ->get();

        return response()->json(
            DepartmentResource::collection($departments)
        );
    }
}
