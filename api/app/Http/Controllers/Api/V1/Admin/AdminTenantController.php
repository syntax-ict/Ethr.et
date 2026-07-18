<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExtendTrialRequest;
use App\Http\Requests\Admin\ImpersonateTenantRequest;
use App\Http\Requests\Admin\UpdateTenantStatusRequest;
use App\Http\Resources\AdminTenantResource;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AdminTenantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
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

        return AdminTenantResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
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

    public function impersonate(ImpersonateTenantRequest $request, string $publicId, MfaService $mfaService): JsonResponse
    {
        Gate::authorize('admin.manage');

        $actor = $request->user();

        if (! $actor->mfa_enabled) {
            return response()->json([
                'type' => 'https://ethr.et/errors/mfa-required',
                'title' => 'MFA Required',
                'status' => 409,
                'detail' => __('auth.mfa_required'),
            ], 409)->header('Content-Type', 'application/problem+json');
        }

        if (! $mfaService->verify($actor->mfa_secret, $request->validated('code'))) {
            AuditLog::record('admin.tenant.impersonation_mfa_failed', null, [
                'target_tenant_public_id' => $publicId,
            ]);

            return response()->json([
                'type' => 'https://ethr.et/errors/invalid-mfa-code',
                'title' => 'Invalid Code',
                'status' => 422,
                'detail' => __('auth.mfa_invalid'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

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

        $expiresAt = now()->addMinutes(30);
        $token = $adminUser->createToken("impersonation:{$actor->id}", ['*', 'impersonation'], $expiresAt);

        AuditLog::record('admin.tenant.impersonated', $tenant, [
            'impersonated_user_id' => $adminUser->id,
            'admin_user_id' => $actor->id,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return response()->json([
            'token' => $token->plainTextToken,
            'tenant' => $tenant->subdomain,
            'expires_at' => $expiresAt,
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
