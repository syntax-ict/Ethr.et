<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SessionCookie;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use App\Services\PlanLimitService;
use App\Services\Sso\SsoProviderInterface;
use App\Services\Sso\SsoUser;
use App\Support\EthiopianPhone;
use App\Support\FrontendUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SsoController extends Controller
{
    public function __construct(
        private readonly SsoProviderInterface $sso,
        private readonly AuthService $authService,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function initiate(Request $request, string $subdomain): JsonResponse
    {
        $tenant = Tenant::where('subdomain', $subdomain)->firstOrFail();

        if (! $this->sso->isConfigured($tenant)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/sso-not-configured',
                'title' => 'SSO Not Configured',
                'status' => 422,
                'detail' => __('auth.sso_not_configured'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        $relayState = $request->input('relay_state', '/dashboard');
        $loginUrl = $this->sso->getLoginUrl($tenant, $relayState);

        return response()->json([
            'redirect_url' => $loginUrl,
        ]);
    }

    /**
     * The identity provider's form POST back after sign-in (SAML ACS).
     *
     * Every outcome is a 303 redirect to the app's `/login/sso` page with the
     * organisation (`org`) and either `next` (plus `mfa=1` while a second
     * factor is owed) or `error`: `failed`, `no_account`, `account_inactive`,
     * `tenant_inactive`, `not_configured` or `seat_limit`. On success the
     * session cookie is set on the redirect.
     */
    public function callback(Request $request, string $subdomain): RedirectResponse
    {
        // This answered the provider's form POST with JSON until 2026-10-07,
        // so a user signing in through SSO was left on a raw JSON page and
        // never reached the app (audit N60).
        $tenant = Tenant::where('subdomain', $subdomain)->firstOrFail();
        $this->currentTenant->set($tenant);

        // The callback runs on the API host, where ResolveTenant's suspension
        // check does not apply, so a suspended tenant's users still got
        // sessions — and auto-provisioning still wrote into the tenant.
        if (! $tenant->isActive()) {
            return $this->backToApp($tenant, ['error' => 'tenant_inactive']);
        }

        if (! $this->sso->isConfigured($tenant)) {
            return $this->backToApp($tenant, ['error' => 'not_configured']);
        }

        try {
            $ssoUser = $this->sso->handleCallback($tenant, $request->all());
        } catch (\RuntimeException $e) {
            AuditLog::record('sso.callback_failed', $tenant, [
                'error' => $e->getMessage(),
                'subdomain' => $subdomain,
            ]);

            return $this->backToApp($tenant, ['error' => 'failed']);
        }

        try {
            $user = $this->findOrProvisionUser($tenant, $ssoUser);
        } catch (HttpException $e) {
            // PlanLimitService refuses a provisioned seat past the plan's cap
            // with a 403; it must reach the user as a page, not as JSON.
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }

            return $this->backToApp($tenant, ['error' => 'seat_limit']);
        }

        if (! $user) {
            return $this->backToApp($tenant, ['error' => 'no_account']);
        }

        if ($user->status !== 'active') {
            AuditLog::record('sso.login_blocked', $user, [
                'reason' => 'account_inactive',
            ]);

            return $this->backToApp($tenant, ['error' => 'account_inactive']);
        }

        $result = $this->authService->login($user);

        AuditLog::record('sso.login', $user, [
            'provider' => 'saml',
            'name_id' => $ssoUser->nameId,
        ]);

        // login() queued the session cookie, but Sanctum attaches queued
        // cookies only to first-party requests, and this one is a cross-site
        // form POST from the identity provider. So the cookie goes on the
        // redirect itself, or the sign-in would not survive it.
        $cookie = cookie()->queued(SessionCookie::NAME, null, SessionCookie::PATH);

        return $this->backToApp($tenant, [
            'next' => $this->safeNext($request->input('RelayState')),
            'mfa' => $result['mfa_required'] ? '1' : null,
        ], $cookie instanceof Cookie ? $cookie : null);
    }

    /**
     * The app's SSO landing page, on the shared host. That is the host the
     * cookie above is set for, because the ACS URL is built from APP_URL.
     *
     * @param  array<string, string|null>  $query
     */
    private function backToApp(Tenant $tenant, array $query, ?Cookie $cookie = null): RedirectResponse
    {
        $url = FrontendUrl::to('login/sso').'?'.http_build_query(
            ['org' => $tenant->subdomain] + array_filter($query, fn ($v) => $v !== null),
        );

        $response = redirect()->away($url, 303);

        return $cookie ? $response->withCookie($cookie) : $response;
    }

    /**
     * Where to go after sign-in, from the RelayState the identity provider
     * echoes back. RelayState is whatever the request carries, so only a path
     * on this site is accepted: anything else, including `//evil.example`
     * and the API itself, falls back to the dashboard. Otherwise this would be
     * an open redirect.
     */
    private function safeNext(mixed $relayState): string
    {
        if (! is_string($relayState)
            || preg_match('#^/(?![/\\\\])[^\s]*$#', $relayState) !== 1
            || str_starts_with($relayState, '/api')) {
            return '/dashboard';
        }

        return $relayState;
    }

    public function metadata(string $subdomain): Response
    {
        $tenant = Tenant::where('subdomain', $subdomain)->firstOrFail();

        $xml = $this->sso->getMetadataXml($tenant);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
        ]);
    }

    private function findOrProvisionUser(Tenant $tenant, SsoUser $ssoUser): ?User
    {
        $user = User::where('tenant_id', $tenant->id)
            ->where('email', $ssoUser->email)
            ->first();

        if ($user) {
            return $user;
        }

        $ssoSettings = $tenant->ssoSetting;
        if (! $ssoSettings || ! $ssoSettings->auto_provision) {
            return null;
        }

        // The plan's seat cap applies however an employee arrives.
        app(PlanLimitService::class)->assertCanAdd($tenant, 'employees');

        return DB::transaction(function () use ($tenant, $ssoUser, $ssoSettings) {
            $defaultRole = UserRole::tryFrom($ssoSettings->default_role) ?? UserRole::EMPLOYEE;

            $employee = Employee::create([
                'tenant_id' => $tenant->id,
                'name' => $ssoUser->fullName(),
                'email' => $ssoUser->email,
                'phone' => EthiopianPhone::canonicalOrRaw($ssoUser->phone),
                // 'active' is not an EmployeeStatus: every auto-provisioned
                // first sign-in failed with a 500 here. HIRED, as SCIM uses.
                'status' => EmployeeStatus::HIRED,
                'hire_date' => now(),
            ]);

            $user = User::create([
                'tenant_id' => $tenant->id,
                'employee_id' => $employee->id,
                'email' => $ssoUser->email,
                'password' => '',
                'role' => $defaultRole,
                'status' => 'active',
                'locale' => 'en',
            ]);

            AuditLog::record('sso.user_provisioned', $user, [
                'provider' => 'saml',
                'name_id' => $ssoUser->nameId,
            ]);

            return $user;
        });
    }
}
