<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PlanFeature;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExtendTrialRequest;
use App\Http\Requests\Admin\ImpersonateTenantRequest;
use App\Http\Requests\Admin\UpdateTenantDomainRequest;
use App\Http\Requests\Admin\UpdateTenantStatusRequest;
use App\Http\Resources\AdminTenantResource;
use App\Jobs\BackupTenantJob;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ImpersonationToken;
use App\Services\Auth\SessionCookie;
use App\Services\Auth\SessionHandoff;
use App\Services\AuthService;
use App\Services\CustomDomainVerifier;
use App\Services\MfaService;
use App\Services\PlanFeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

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

    public function show(string $publicId, PlanFeatureService $plans): JsonResponse
    {
        Gate::authorize('admin.manage');

        // Scope-free count, for the same reason as index() above.
        $tenant = Tenant::withoutGlobalScopes()
            ->where('public_id', $publicId)
            ->withCount(['employees' => fn ($q) => $q->withoutGlobalScopes()])
            ->firstOrFail();

        $subscription = $this->latestSubscription($tenant);
        $tenant->setRelation('subscription', $subscription);

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
            'custom_domain' => $tenant->custom_domain,
            'custom_domain_status' => $this->domainStatus($tenant),
            'custom_domain_dns' => $this->domainDns($tenant),
            'custom_domain_allowed' => $plans->allows($tenant, PlanFeature::CustomDomain),
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
     * Assign, change or clear the tenant's custom domain.
     *
     * A new domain is stored **pending**, with a fresh verification token: it
     * resolves nothing and no link uses it until the Verify action finds the
     * DNS records `custom_domain_dns` lists. `null` clears it at once.
     */
    public function updateDomain(UpdateTenantDomainRequest $request, string $publicId, PlanFeatureService $plans): JsonResponse
    {
        Gate::authorize('admin.manage');

        // Tenant::query(), not withoutGlobalScopes(): Tenant carries no tenant
        // scope, so there is nothing to bypass, and SoftDeletes keeps a deleted
        // tenant from being handed a domain. Tenant's saved() hook forgets the
        // resolver's cache for the old and the new domain, which is why the
        // change applies at once rather than after the five-minute cache.
        $tenant = Tenant::query()
            ->where('public_id', $publicId)
            ->firstOrFail();

        $newDomain = $request->input('custom_domain');
        $tenant->setRelation('subscription', $this->latestSubscription($tenant));

        // A custom domain is a paid add-on: assigning one needs a plan that
        // names `custom_domain`. Clearing one never does.
        if ($newDomain !== null && ! $plans->allows($tenant, PlanFeature::CustomDomain)) {
            throw ValidationException::withMessages([
                'custom_domain' => [__('validation.custom_domain_not_in_plan')],
            ]);
        }

        $oldDomain = $tenant->custom_domain;
        $tenant->update(['custom_domain' => $newDomain]);

        AuditLog::record('admin.tenant.domain_changed', $tenant, [
            'old_domain' => $oldDomain,
            'new_domain' => $tenant->custom_domain,
        ]);

        return response()->json([
            'public_id' => $tenant->public_id,
            'custom_domain' => $tenant->custom_domain,
            'custom_domain_status' => $this->domainStatus($tenant),
            'custom_domain_dns' => $this->domainDns($tenant),
        ]);
    }

    /**
     * Check the tenant's pending custom domain and, if both DNS records are in
     * place, make it its address.
     *
     * Looks up the TXT record carrying the verification token and the CNAME to
     * the platform target. Both must pass; a 422 names each that did not. A
     * domain already verified is returned as it is: a DNS hiccup at the moment
     * someone presses Verify must not take an organisation's address away.
     * Changing the domain is what starts verification over.
     */
    public function verifyDomain(string $publicId, CustomDomainVerifier $verifier): JsonResponse
    {
        Gate::authorize('admin.manage');

        $tenant = Tenant::query()
            ->where('public_id', $publicId)
            ->firstOrFail();

        if ($tenant->custom_domain === null) {
            throw ValidationException::withMessages([
                'custom_domain' => [__('validation.custom_domain_none')],
            ]);
        }

        if (! $tenant->hasVerifiedCustomDomain()) {
            $errors = $this->failedDnsChecks($tenant, $verifier->check($tenant));
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $tenant->forceFill(['custom_domain_verified_at' => now()])->save();
            AuditLog::record('admin.tenant.domain_verified', $tenant, [
                'domain' => $tenant->custom_domain,
            ]);
        }

        return response()->json([
            'public_id' => $tenant->public_id,
            'custom_domain' => $tenant->custom_domain,
            'custom_domain_status' => $this->domainStatus($tenant),
            'custom_domain_dns' => $this->domainDns($tenant),
        ]);
    }

    /**
     * One message per DNS check that did not pass, keyed `txt` and `cname`.
     *
     * @param  array{txt: bool, cname: bool}  $checks
     * @return array<string, list<string>>
     */
    private function failedDnsChecks(Tenant $tenant, array $checks): array
    {
        $dns = $this->domainDns($tenant);
        $errors = [];

        if (! $checks['txt'] && $dns !== null) {
            $errors['txt'] = [__('validation.custom_domain_txt_missing', [
                'name' => $dns['txt_name'],
                'value' => $dns['txt_value'],
            ])];
        }

        if (! $checks['cname']) {
            $errors['cname'] = [__('validation.custom_domain_cname_missing', [
                'domain' => (string) $tenant->custom_domain,
                'target' => CustomDomainVerifier::target() ?? '-',
            ])];
        }

        return $errors;
    }

    /**
     * The tenant's current subscription, with its plan.
     *
     * Scope-free and stating `tenant_id` itself: the console resolves no
     * tenant, so `$tenant->subscription` would answer null through
     * BelongsToTenant's fail-closed scope, and PlanFeatureService would then
     * read every plan as absent. One site for show() and updateDomain(), so
     * the plan the console displays is the plan it enforces.
     */
    private function latestSubscription(Tenant $tenant): ?Subscription
    {
        return Subscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with('plan')
            ->latest()
            ->first();
    }

    /** `null` with no domain, `pending` until Verify passes, then `verified`. */
    private function domainStatus(Tenant $tenant): ?string
    {
        if ($tenant->custom_domain === null) {
            return null;
        }

        return $tenant->hasVerifiedCustomDomain() ? 'verified' : 'pending';
    }

    /**
     * The two DNS records the organisation publishes, or null with no domain.
     *
     * @return array{txt_name: string, txt_value: string, cname_name: string, cname_target: string|null}|null
     */
    private function domainDns(Tenant $tenant): ?array
    {
        $domain = $tenant->custom_domain;
        if ($domain === null) {
            return null;
        }

        return [
            'txt_name' => CustomDomainVerifier::txtName($domain),
            'txt_value' => CustomDomainVerifier::txtValue((string) $tenant->custom_domain_token),
            'cname_name' => $domain,
            'cname_target' => CustomDomainVerifier::target(),
        ];
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
     * End an impersonation session and send the super admin back to their own.
     *
     * Routed at `POST /auth/impersonation/exit`, inside the ordinary
     * authenticated group and *outside* `/admin`: the caller is the impersonated
     * tenant admin on the tenant's own host, where `EnsurePlatformContext` 404s
     * every `/admin` route (audit N19). The authority is the impersonation
     * ability on the presented token — checked first, so an ordinary session
     * gets a 403 and nothing else happens.
     *
     * Revoking the token is not enough on its own: the browser is holding that
     * token in its session cookie, so a bare revoke turns the next request into
     * a 401 that reads as a random logout. What replaces it depends on the mode:
     *
     * - Hostname mode: this request is on {tenant}.ethr.et, and the operator's
     *   own session lives on admin.ethr.et — impersonate() never touched it. A
     *   super-admin session minted here would be a cookie for the wrong host,
     *   refused by EnsureUserBelongsToTenant on its first use. So the tenant
     *   host's cookie is cleared and the client is told where to go back to.
     * - Single host: the admin's original plaintext token is unrecoverable (it
     *   was overwritten in the cookie and only its hash is stored), so restoring
     *   them means minting a fresh session here.
     */
    public function exitImpersonation(Request $request, SessionCookie $sessionCookie, AuthService $auth): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        // Only an active impersonation session (token minted with the
        // 'impersonation' ability) may be ended here. No admin.manage gate:
        // the caller is acting as the impersonated tenant admin, not a super
        // admin. Read from the abilities list, never tokenCan(): an ordinary
        // token holds '*', which tokenCan('impersonation') would match.
        if ($user === null || $token === null || ! in_array(ImpersonationToken::ABILITY, $token->abilities ?? [], true)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/not-impersonating',
                'title' => 'Not Impersonating',
                'status' => 403,
                'detail' => __('auth.not_impersonating'),
            ], 403)->header('Content-Type', 'application/problem+json');
        }

        $impersonator = $this->resolveImpersonator($token->name);

        AuditLog::record('admin.tenant.impersonation_ended', null, [
            'impersonated_user_id' => $user->id,
            'host' => $request->getHost(),
        ]);

        // Revoke the impersonation token so it can no longer be used.
        $token->delete();

        if (SessionHandoff::required()) {
            $sessionCookie->clear();

            return response()->json([
                'message' => __('auth.impersonation_ended'),
                'session_restored' => false,
                'tenant' => null,
                'return_url' => $impersonator !== null ? SessionHandoff::platformConsoleUrl() : null,
            ]);
        }

        if ($impersonator === null) {
            $sessionCookie->clear();

            return response()->json([
                'message' => __('auth.impersonation_ended'),
                'session_restored' => false,
                'tenant' => null,
                'return_url' => null,
            ]);
        }

        $auth->issueSession($impersonator);

        // The subdomain the client should send as X-Tenant from here on.
        // Server-authoritative, so exiting still works when the browser has lost
        // whatever it stashed at the start of the session.
        $restoredTenant = Tenant::withoutGlobalScopes()->find($impersonator->tenant_id)?->subdomain;

        return response()->json([
            'message' => __('auth.impersonation_ended'),
            'session_restored' => true,
            'tenant' => $restoredTenant,
            'return_url' => null,
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

        // Deliberately NOT tenant-scoped, and scoping it would break exiting an
        // impersonation. During impersonation the resolved tenant is the
        // *impersonated* one, while the actor being recovered is a super admin
        // belonging to another tenant or none — so any predicate here finds
        // nobody and the session can never be restored. The authority is the
        // `isSuperAdmin()` re-check below, not the lookup. Reviewed under
        // BASELINE.md §11g.
        $impersonator = User::withoutGlobalScopes()->find($actorId);

        return $impersonator?->isSuperAdmin() ? $impersonator : null;
    }

    /**
     * Record that an organisation's invoice has been paid.
     *
     * Payment is by bank transfer to the provider, so it is the provider who
     * knows it arrived. This was a tenant endpoint behind the tenant's own
     * `billing.manage`, which let an organisation mark its own invoices paid,
     * defeat overdue suspension and count as revenue (audit N94).
     */
    public function markInvoicePaid(string $publicId, string $invoicePublicId): JsonResponse
    {
        Gate::authorize('admin.manage');

        $tenant = Tenant::where('public_id', $publicId)->firstOrFail();

        $invoice = Invoice::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('public_id', $invoicePublicId)
            ->firstOrFail();

        if ($invoice->status !== 'paid') {
            $invoice->update(['status' => 'paid', 'paid_at' => now()]);
            AuditLog::record('billing.invoice_paid', $invoice);
        }

        return response()->json([
            'public_id' => $invoice->public_id,
            'status' => $invoice->status,
            'paid_at' => $invoice->paid_at,
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

        BackupTenantJob::dispatch($tenant->id, $request->user()->id);

        return response()->json([
            'message' => 'Backup job queued. You will be notified when the export is ready.',
            'tenant_id' => $tenant->public_id,
        ]);
    }
}
