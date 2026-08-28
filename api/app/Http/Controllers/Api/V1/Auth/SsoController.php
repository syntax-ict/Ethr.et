<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\CurrentTenant;
use App\Services\Sso\SsoProviderInterface;
use App\Services\Sso\SsoUser;
use App\Support\EthiopianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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

    public function callback(Request $request, string $subdomain): JsonResponse
    {
        $tenant = Tenant::where('subdomain', $subdomain)->firstOrFail();
        $this->currentTenant->set($tenant);

        if (! $this->sso->isConfigured($tenant)) {
            return response()->json([
                'type' => 'https://ethr.et/errors/sso-not-configured',
                'title' => 'SSO Not Configured',
                'status' => 422,
                'detail' => __('auth.sso_not_configured'),
            ], 422)->header('Content-Type', 'application/problem+json');
        }

        try {
            $ssoUser = $this->sso->handleCallback($tenant, $request->all());
        } catch (\RuntimeException $e) {
            AuditLog::record('sso.callback_failed', $tenant, [
                'error' => $e->getMessage(),
                'subdomain' => $subdomain,
            ]);

            return response()->json([
                'type' => 'https://ethr.et/errors/sso-failed',
                'title' => 'SSO Authentication Failed',
                'status' => 401,
                'detail' => __('auth.sso_failed'),
            ], 401)->header('Content-Type', 'application/problem+json');
        }

        $user = $this->findOrProvisionUser($tenant, $ssoUser);

        if (! $user) {
            return response()->json([
                'type' => 'https://ethr.et/errors/sso-no-account',
                'title' => 'No Account Found',
                'status' => 403,
                'detail' => __('auth.sso_no_account'),
            ], 403)->header('Content-Type', 'application/problem+json');
        }

        if ($user->status !== 'active') {
            AuditLog::record('sso.login_blocked', $user, [
                'reason' => 'account_inactive',
            ]);

            return response()->json([
                'type' => 'https://ethr.et/errors/account-inactive',
                'title' => 'Account Inactive',
                'status' => 403,
                'detail' => __('auth.account_suspended'),
            ], 403)->header('Content-Type', 'application/problem+json');
        }

        $result = $this->authService->login($user);

        AuditLog::record('sso.login', $user, [
            'provider' => 'saml',
            'name_id' => $ssoUser->nameId,
        ]);

        $result['relay_state'] = $request->input('RelayState', '/dashboard');

        return response()->json($result);
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

        return DB::transaction(function () use ($tenant, $ssoUser, $ssoSettings) {
            $defaultRole = UserRole::tryFrom($ssoSettings->default_role) ?? UserRole::EMPLOYEE;

            $employee = Employee::create([
                'tenant_id' => $tenant->id,
                'name' => $ssoUser->fullName(),
                'email' => $ssoUser->email,
                'phone' => EthiopianPhone::canonicalOrRaw($ssoUser->phone),
                'status' => 'active',
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
