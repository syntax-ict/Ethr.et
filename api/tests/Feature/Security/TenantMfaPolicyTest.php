<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\ImpersonationToken;
use App\Services\CurrentTenant;
use PragmaRX\Google2FA\Google2FA;

/*
 * Audit N6: a tenant's `mfa_policy` was saved and read by nothing. An admin who
 * chose "Required" saw it reflected back on the settings page while nobody was
 * ever asked to set up a second factor.
 */

function n6TenantWithMfaPolicy(string $policy): Tenant
{
    return createTenant(['settings' => ['mfa_policy' => $policy]]);
}

function n6Url(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

test('a user without MFA in a tenant that requires it is sent to enrol', function () {
    $tenant = n6TenantWithMfaPolicy('required');
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => false], $tenant);

    test()->getJson(n6Url($tenant, 'employees'))
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/mfa-enrolment-required');
});

test('the account-security surface stays reachable while enrolment is owed', function () {
    $tenant = n6TenantWithMfaPolicy('required');
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => false], $tenant);

    test()->getJson(n6Url($tenant, 'auth/me'))->assertOk()->assertJsonPath('user.mfa_enabled', false);
    test()->postJson(n6Url($tenant, 'auth/mfa/setup'))->assertOk()->assertJsonStructure(['secret', 'qr_code_url']);
    test()->getJson(n6Url($tenant, 'auth/sessions'))->assertOk();
});

test('enrolling lifts the restriction', function () {
    $tenant = n6TenantWithMfaPolicy('required');
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => false], $tenant);

    $secret = test()->postJson(n6Url($tenant, 'auth/mfa/setup'))->assertOk()->json('secret');

    test()->postJson(n6Url($tenant, 'auth/mfa/enable'), [
        'secret' => $secret,
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertOk();

    expect($user->fresh()->mfa_enabled)->toBeTrue();

    test()->getJson(n6Url($tenant, 'employees'))->assertOk();
});

test('a user who already has MFA is not affected', function () {
    $tenant = n6TenantWithMfaPolicy('required');
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXP'], $tenant);

    test()->getJson(n6Url($tenant, 'employees'))->assertOk();
});

test('an optional policy, or none, requires nothing', function (?string $policy) {
    $tenant = $policy === null ? createTenant() : n6TenantWithMfaPolicy($policy);
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => false], $tenant);

    test()->getJson(n6Url($tenant, 'employees'))->assertOk();
})->with(['optional', 'disabled', null]);

test('the same restriction applies to a bearer token', function () {
    $tenant = n6TenantWithMfaPolicy('required');
    $user = createUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => false], $tenant);
    $token = $user->createToken('auth', ['*'], now()->addMinutes(15))->plainTextToken;

    test()->withToken($token)->getJson(n6Url($tenant, 'employees'))
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/mfa-enrolment-required');
});

test('an impersonation session is not confined', function () {
    $tenant = n6TenantWithMfaPolicy('required');
    $tenantAdmin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN,
        'mfa_enabled' => false,
    ]);

    // The operator behind it passed platform MFA to start it, and may not enrol
    // MFA on the impersonated account (BlockImpersonatedActions).
    $token = $tenantAdmin->createToken(ImpersonationToken::nameFor(999), ['*', ImpersonationToken::ABILITY], now()->addMinutes(30))->plainTextToken;

    test()->withToken($token)->getJson(n6Url($tenant, 'employees'))->assertOk();
});

test('a platform super admin is never subject to a tenant policy', function () {
    $tenant = n6TenantWithMfaPolicy('required');

    // `tenant_id` set deliberately: even a super admin row that carries one is
    // a platform account, not a member of that tenant's policy.
    $admin = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => false], $tenant);
    $token = $admin->createToken('auth', ['*'])->plainTextToken;
    app(CurrentTenant::class)->forget();

    test()->withToken($token)->getJson('http://admin.ethr.test/api/v1/admin/tenants')->assertOk();
});

test('a tenant whose policy is disabled does not offer enrolment', function () {
    $tenant = n6TenantWithMfaPolicy('disabled');
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => false], $tenant);

    test()->postJson(n6Url($tenant, 'auth/mfa/setup'))
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/mfa-disabled-by-policy');

    $secret = (new Google2FA)->generateSecretKey();
    test()->postJson(n6Url($tenant, 'auth/mfa/enable'), [
        'secret' => $secret,
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertForbidden();
});
