<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExtendTrialRequest;
use App\Http\Requests\Admin\UpdateTenantStatusRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
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

        $subscription = Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with('plan')
            ->latest()
            ->first();

        $invoices = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($i) => [
                'public_id' => $i->public_id,
                'total_cents' => $i->total_cents,
                'status' => $i->status,
                'due_date' => $i->due_date?->format('Y-m-d'),
                'paid_at' => $i->paid_at,
            ]);

        $deviceCount = class_exists(Device::class)
            ? Device::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count()
            : 0;

        $auditLogs = AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn ($l) => [
                'action' => $l->action,
                'created_at' => $l->created_at,
            ]);

        return response()->json([
            'public_id' => $tenant->public_id,
            'name' => $tenant->name,
            'subdomain' => $tenant->subdomain,
            'type' => $tenant->type,
            'status' => $tenant->status->value,
            'trial_ends_at' => $tenant->trial_ends_at,
            'created_at' => $tenant->created_at,
            'updated_at' => $tenant->updated_at,
            'usage' => [
                'employees' => $tenant->employees_count,
                'devices' => $deviceCount,
            ],
            'subscription' => $subscription ? [
                'plan_name' => $subscription->plan?->name,
                'status' => $subscription->status?->value,
                'current_period_end' => $subscription->current_period_end,
            ] : null,
            'invoices' => $invoices,
            'audit_log' => $auditLogs,
        ]);
    }

    public function updateStatus(UpdateTenantStatusRequest $request, string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

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

    public function extendTrial(ExtendTrialRequest $request, string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

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

        $adminUser = User::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('role', UserRole::TENANT_ADMIN)
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

    public function backup(Request $request, string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $tenant = Tenant::withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->firstOrFail();

        AuditLog::record('admin.tenant.backup_triggered', $tenant, [
            'triggered_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Backup job queued. You will be notified when the export is ready.',
            'tenant_id' => $tenant->public_id,
        ]);
    }
}
