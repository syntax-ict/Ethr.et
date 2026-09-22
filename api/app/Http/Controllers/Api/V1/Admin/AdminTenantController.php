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
use App\Jobs\BackupTenantJob;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantPublicProfile;
use App\Models\User;
use App\Services\Auth\ImpersonationToken;
use App\Services\Auth\SessionCookie;
use App\Services\Auth\SessionHandoff;
use App\Services\AuthService;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AdminTenantController extends Controller
{
    /** How long an impersonation session lasts before it has to be re-authorized with MFA. */
    private const IMPERSONATION_MINUTES = 30;

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('admin.manage');

        // The `withoutGlobalScopes()` on the outer query does not reach the
        // `withCount` subquery — that builds its own Employee query, which still
        // carries BelongsToTenant's scope. With no tenant resolved (the platform
        // admin never has one) that scope is `whereRaw('0 = 1')`, so every
        // tenant reported **0 employees** no matter its real headcount: the
        // 150-employee demo tenant read as empty. Devices below were already
        // counted scope-free; employees were missed.
        $query = Tenant::withoutGlobalScopes()
            ->withCount(['employees' => fn ($q) => $q->withoutGlobalScopes()]);

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

        // Scope-free count, for the same reason as index() above.
        $tenant = Tenant::withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->withCount(['employees' => fn ($q) => $q->withoutGlobalScopes()])
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

    /**
     * Take a tenant's public page down, or put it back.
     *
     * `*.ethr.et` is ETHR's own domain, so whatever a tenant publishes there is
     * published partly on ETHR's reputation. Until now nothing could pull a
     * page that turned out to be fraudulent, offensive or impersonating — this
     * is that lever, and it is the platform's alone.
     *
     * Deliberately separate from `is_published`. A takedown must not destroy
     * the tenant's own publication state: restoring is one column write rather
     * than a guess about what they had wanted, and an administrator who
     * republishes cannot quietly undo a suspension.
     *
     * The suspended page answers exactly as an unpublished one does — same
     * status, same body — so suspension does not become a way to discover
     * which organisations have been moderated.
     */
    public function suspendPublicPage(Request $request, string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $suspend = $request->boolean('suspended', true);

        // Platform surface: there is no tenant context on admin.ethr.et, so
        // the scope is lifted and the tenant is named explicitly instead. The
        // profile is then found by `tenant_id`, a key that is itself tenant
        // owned, which is what keeps this bypass safe.
        $tenant = Tenant::withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->firstOrFail();

        $profile = TenantPublicProfile::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $profile->forceFill(['suspended_at' => $suspend ? now() : null])->save();

        AuditLog::record(
            $suspend ? 'admin.public_page_suspended' : 'admin.public_page_restored',
            $tenant,
            ['subdomain' => $tenant->subdomain],
        );

        return response()->json([
            'public_id' => $tenant->public_id,
            'is_suspended' => $profile->isSuspended(),
        ]);
    }

    /**
     * Record, or withdraw, that this tenant really is a government body.
     *
     * The only thing standing between a private company and state-official
     * branding on its public page, because every other signal — the industry
     * chosen at onboarding, the free-text `type` column — is written by the
     * tenant about itself. Granting it is a human decision made here; the
     * column is absent from Tenant::$fillable so no tenant-facing route can
     * reach it. See App\Rules\SelectablePreset.
     */
    public function verifyGovernment(Request $request, string $publicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $verified = $request->boolean('verified', true);

        $tenant = Tenant::withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->firstOrFail();

        $tenant->forceFill(['government_verified_at' => $verified ? now() : null])->save();

        AuditLog::record(
            $verified ? 'admin.tenant.government_verified' : 'admin.tenant.government_verification_revoked',
            $tenant,
            ['subdomain' => $tenant->subdomain],
        );

        return response()->json([
            'public_id' => $tenant->public_id,
            'government_verified' => $tenant->government_verified_at !== null,
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

    /**
     * Start impersonating a tenant's admin.
     *
     * The minted token is both returned (for API clients that send it as a
     * bearer) and planted in the session cookie, because the browser SPA has no
     * other way to use it — it sends no Authorization header. Without the
     * cookie swap the redirect that follows merely continued the super admin's
     * own session against the target tenant, which is not impersonation and
     * left no exit path for the audit trail to close.
     */
    public function impersonate(ImpersonateTenantRequest $request, string $publicId, MfaService $mfaService, SessionCookie $sessionCookie, SessionHandoff $handoff): JsonResponse
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

        $expiresAt = now()->addMinutes(self::IMPERSONATION_MINUTES);
        $token = $adminUser->createToken(
            ImpersonationToken::nameFor($actor->id),
            ['*', ImpersonationToken::ABILITY],
            $expiresAt
        );

        AuditLog::record('admin.tenant.impersonated', $tenant, [
            'impersonated_user_id' => $adminUser->id,
            'admin_user_id' => $actor->id,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        // Once hostnames are authoritative this request is on admin.ethr.et and
        // the browser is about to be sent to {tenant}.ethr.et. The session
        // cookie is host-only, so issuing it here would plant it on a host the
        // browser is leaving — impersonation would simply arrive logged out.
        // Hand the session over explicitly instead.
        if (SessionHandoff::required()) {
            $nonce = $handoff->issue(
                $token->plainTextToken,
                $tenant->subdomain,
                self::IMPERSONATION_MINUTES * 60,
            );

            AuditLog::record('admin.tenant.impersonation_handoff_issued', $tenant, [
                'admin_user_id' => $actor->id,
                'impersonated_user_id' => $adminUser->id,
            ]);

            return response()->json([
                'tenant' => $tenant->subdomain,
                'expires_at' => $expiresAt,
                // The nonce travels in the fragment of this URL, so it is never
                // sent to a server in a query string or written to a log.
                'handoff_url' => SessionHandoff::urlFor($tenant->subdomain, $nonce),
            ]);
        }

        // Single-host development: console and tenant app share an origin, so
        // the cookie already reaches where it needs to.
        //
        // The admin's own token is deliberately left alive. Revoking it here
        // would strand them if anything downstream of this point failed.
        $sessionCookie->issue($token->plainTextToken, self::IMPERSONATION_MINUTES * 60);

        return response()->json([
            'token' => $token->plainTextToken,
            'tenant' => $tenant->subdomain,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * End an impersonation session and put the super admin back in their own.
     *
     * Revoking the token is not enough on its own: the browser is holding that
     * token in its session cookie, so a bare revoke turns the next request into
     * a 401 that reads as a random logout. The admin's original plaintext token
     * is unrecoverable (it was overwritten in the cookie and only its hash is
     * stored), so restoring them means minting a fresh session here.
     */
    public function exitImpersonation(Request $request, SessionCookie $sessionCookie, AuthService $auth): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Only an active impersonation session (token minted with the
        // 'impersonation' ability) may be ended here. No admin.manage gate:
        // the caller is acting as the impersonated tenant admin, not a super admin.
        if ($user === null || $token === null || ! in_array(ImpersonationToken::ABILITY, $token->abilities ?? [], true)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-impersonating',
                'title' => 'Not Impersonating',
                'status' => 409,
                'detail' => __('auth.not_impersonating'),
            ], 409)->header('Content-Type', 'application/problem+json');
        }

        AuditLog::record('admin.tenant.impersonation_ended', null, [
            'impersonated_user_id' => $user->id,
        ]);

        // Revoke the impersonation token so it can no longer be used.
        $token->delete();

        $impersonator = $this->resolveImpersonator($token->name);

        if ($impersonator === null) {
            $sessionCookie->clear();

            return response()->json([
                'message' => __('auth.impersonation_ended'),
                'session_restored' => false,
                'tenant' => null,
            ]);
        }

        $auth->issueSession($impersonator);

        return response()->json([
            'message' => __('auth.impersonation_ended'),
            'session_restored' => true,
            // The subdomain the client should send as X-Tenant from here on.
            // Server-authoritative, so exiting still works when the browser has
            // lost whatever it stashed at the start of the session.
            'tenant' => Tenant::withoutGlobalScopes()->find($impersonator->tenant_id)?->subdomain,
        ]);
    }

    /**
     * Recover the super admin behind an impersonation token.
     *
     * The name is written by impersonate() and is never client-supplied, so it
     * is trustworthy input — but the role is re-checked regardless, so a token
     * whose name no longer maps to a super admin (one minted before this
     * format, a demoted account, a deleted one) restores nothing rather than
     * minting a session for the wrong person.
     */
    private function resolveImpersonator(?string $tokenName): ?User
    {
        $actorId = ImpersonationToken::impersonatorId($tokenName);

        if ($actorId === null) {
            return null;
        }

        $impersonator = User::withoutGlobalScopes()->find($actorId);

        return $impersonator?->isSuperAdmin() ? $impersonator : null;
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

        BackupTenantJob::dispatch($tenant->id, $request->user()->id);

        return response()->json([
            'message' => 'Backup job queued. You will be notified when the export is ready.',
            'tenant_id' => $tenant->public_id,
        ]);
    }
}
