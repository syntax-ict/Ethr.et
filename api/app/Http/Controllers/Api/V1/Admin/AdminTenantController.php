<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TenantStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AdminTenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('admin.manage');

        $query = Tenant::withoutGlobalScopes()
            ->withCount('employees');

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('subdomain', 'like', "%{$search}%");
            });
        }

        if ($request->has('filter.status')) {
            $query->where('status', $request->input('filter.status'));
        }

        $sort = $request->input('sort', '-created_at');
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');
        $query->orderBy($field, $dir);

        $tenants = $query->paginate($request->integer('per_page', 25));

        $tenants->through(fn (Tenant $t) => [
            'public_id' => $t->public_id,
            'name' => $t->name,
            'subdomain' => $t->subdomain,
            'type' => $t->type,
            'status' => $t->status->value,
            'employee_count' => $t->employees_count,
            'trial_ends_at' => $t->trial_ends_at,
            'created_at' => $t->created_at,
        ]);

        return response()->json($tenants);
    }

    public function show(string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $tenant = Tenant::withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->withCount('employees')
            ->firstOrFail();

        $employeeCount = $tenant->employees_count;

        return response()->json([
            'public_id' => $tenant->public_id,
            'name' => $tenant->name,
            'subdomain' => $tenant->subdomain,
            'type' => $tenant->type,
            'status' => $tenant->status->value,
            'employee_count' => $employeeCount,
            'trial_ends_at' => $tenant->trial_ends_at,
            'created_at' => $tenant->created_at,
            'updated_at' => $tenant->updated_at,
        ]);
    }

    public function updateStatus(Request $request, string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $request->validate([
            'status' => ['required', 'string', 'in:active,suspended,cancelled'],
        ]);

        $tenant = Tenant::withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->firstOrFail();

        $oldStatus = $tenant->status->value;
        $tenant->update(['status' => TenantStatus::from($request->input('status'))]);

        AuditLog::record('admin.tenant.status_changed', $tenant, [
            'old_status' => $oldStatus,
            'new_status' => $request->input('status'),
        ]);

        return response()->json([
            'public_id' => $tenant->public_id,
            'status' => $tenant->status->value,
        ]);
    }

    public function extendTrial(Request $request, string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:180'],
        ]);

        $tenant = Tenant::withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->firstOrFail();

        $newEnd = ($tenant->trial_ends_at ?? now())->addDays($request->integer('days'));
        $tenant->update(['trial_ends_at' => $newEnd]);

        AuditLog::record('admin.tenant.trial_extended', $tenant, [
            'days' => $request->integer('days'),
            'new_end' => $newEnd->toDateString(),
        ]);

        return response()->json([
            'public_id' => $tenant->public_id,
            'trial_ends_at' => $newEnd,
        ]);
    }

    public function impersonate(Request $request, string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $tenant = Tenant::withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->firstOrFail();

        $adminUser = \App\Models\User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('role', \App\Enums\UserRole::TENANT_ADMIN)
            ->first();

        if (! $adminUser) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-found',
                'title' => 'No Admin User',
                'status' => 404,
                'detail' => 'No tenant admin user found for this tenant',
            ], 404)->header('Content-Type', 'application/problem+json');
        }

        $token = $adminUser->createToken('impersonation', ['*'], now()->addHour());

        AuditLog::record('admin.tenant.impersonated', $tenant, [
            'impersonated_user_id' => $adminUser->id,
            'admin_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'token' => $token->plainTextToken,
            'tenant' => $tenant->subdomain,
            'expires_at' => now()->addHour(),
        ]);
    }
}
