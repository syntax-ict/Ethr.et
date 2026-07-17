<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Role;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreCustomRoleRequest;
use App\Http\Requests\Role\UpdateCustomRoleRequest;
use App\Http\Resources\CustomRoleResource;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CustomRoleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('settings.manage');

        $query = CustomRole::query()
            ->with('permissions')
            ->withCount('users');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        $query->orderBy('name');

        return CustomRoleResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StoreCustomRoleRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $customRole = CustomRole::create($request->safe()->except('permissions'));
        $customRole->refresh();

        $permissionIds = Permission::whereIn('name', $request->validated('permissions'))
            ->pluck('id');
        $customRole->permissions()->sync($permissionIds);

        $customRole->load('permissions');

        AuditLog::record('custom_role.created', $customRole, [
            'permissions_count' => $permissionIds->count(),
        ]);

        Permission::clearCacheForCustomRole($customRole->id);

        return (new CustomRoleResource($customRole))
            ->response()
            ->setStatusCode(201);
    }

    public function show(CustomRole $customRole): CustomRoleResource
    {
        Gate::authorize('settings.manage');

        $customRole->load('permissions');
        $customRole->loadCount('users');

        return new CustomRoleResource($customRole);
    }

    public function update(UpdateCustomRoleRequest $request, CustomRole $customRole): CustomRoleResource
    {
        Gate::authorize('settings.manage');

        $customRole->update($request->safe()->except('permissions'));

        if ($request->has('permissions')) {
            $permissionIds = Permission::whereIn('name', $request->validated('permissions'))
                ->pluck('id');
            $customRole->permissions()->sync($permissionIds);

            Permission::clearCacheForCustomRole($customRole->id);
        }

        $customRole->load('permissions');
        $customRole->loadCount('users');

        AuditLog::record('custom_role.updated', $customRole);

        return new CustomRoleResource($customRole);
    }

    public function destroy(CustomRole $customRole): JsonResponse
    {
        Gate::authorize('settings.manage');

        $usersCount = $customRole->users()->count();
        if ($usersCount > 0) {
            return response()->json([
                'type' => 'business_rule_violation',
                'title' => 'Cannot Delete Role',
                'status' => 422,
                'detail' => "This role is assigned to {$usersCount} user(s). Reassign them first.",
            ], 422);
        }

        $customRole->permissions()->detach();
        $customRole->delete();

        AuditLog::record('custom_role.deleted', $customRole);

        Permission::clearCacheForCustomRole($customRole->id);

        return response()->json(null, 204);
    }

    public function permissions(): JsonResponse
    {
        Gate::authorize('settings.manage');

        $permissions = Permission::query()
            ->select('name', 'module', 'action', 'description')
            ->orderBy('module')
            ->orderBy('action')
            ->get()
            ->groupBy('module');

        return response()->json($permissions);
    }
}
