<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use PragmaRX\Google2FA\Google2FA;

/**
 * Builds a super admin with MFA enabled and a real bearer token, mirroring how
 * the app actually authenticates. Deliberately avoids actingAs(): Sanctum's guard
 * checks the session ("web") guard before the bearer token, so mixing actingAs()
 * with a later withToken() for a different user silently keeps the actingAs() user.
 */
function superAdminWithMfaToken(): array
{
    $secret = 'JBSWY3DPEHPK3PXP';
    $tenant = createTenant();
    $admin = createUser([
        'role' => UserRole::SUPER_ADMIN,
        'mfa_enabled' => true,
        'mfa_secret' => $secret,
    ], $tenant);

    $token = $admin->createToken('auth', ['*'])->plainTextToken;
    $code = (new Google2FA)->getCurrentOtp($secret);

    return [$tenant, $admin, $code, $token];
}

function createTenantAdminForImpersonation(): array
{
    $target = Tenant::factory()->create();
    $tenantAdmin = User::factory()->create([
        'tenant_id' => $target->id,
        'role' => UserRole::TENANT_ADMIN,
    ]);

    return [$target, $tenantAdmin];
}

// ── MFA gate on starting impersonation ──

test('impersonation requires mfa to be enabled on the acting admin', function () {
    $tenant = createTenant();
    $admin = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => false], $tenant);
    $token = $admin->createToken('auth', ['*'])->plainTextToken;

    [$target] = createTenantAdminForImpersonation();

    test()->withToken($token)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/impersonate", [
            'code' => '123456',
        ])->assertStatus(409);
});

test('impersonation rejects an invalid mfa code', function () {
    [$tenant, , , $token] = superAdminWithMfaToken();
    [$target] = createTenantAdminForImpersonation();

    test()->withToken($token)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/impersonate", [
            'code' => '000000',
        ])->assertStatus(422);
});

test('impersonation succeeds with a valid mfa code and expires in 30 minutes', function () {
    [$tenant, $admin, $code, $token] = superAdminWithMfaToken();
    [$target, $tenantAdmin] = createTenantAdminForImpersonation();

    $response = test()->withToken($token)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/impersonate", [
            'code' => $code,
        ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'tenant', 'expires_at']);

    $expiresAt = Carbon::parse($response->json('expires_at'));
    expect($expiresAt->diffInMinutes(now(), true))->toBeGreaterThan(25)
        ->and($expiresAt->diffInMinutes(now(), true))->toBeLessThanOrEqual(31);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'admin.tenant.impersonated',
    ]);

    $impersonationToken = $tenantAdmin->tokens()->latest('id')->first();
    expect($impersonationToken->name)->toBe("impersonation:{$admin->id}")
        ->and($impersonationToken->abilities)->toContain('impersonation');
});

// ── Blocked actions while impersonating ──

test('impersonated session cannot change password', function () {
    [$target, $tenantAdmin] = createTenantAdminForImpersonation();
    $tenantAdmin->update(['password' => bcrypt('OldPassword123!')]);

    $token = $tenantAdmin->createToken('impersonation:1', ['*', 'impersonation'], now()->addMinutes(30))->plainTextToken;

    test()->withToken($token)
        ->postJson("http://{$target->subdomain}.ethr.test/api/v1/auth/password/change", [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ])
        ->assertStatus(403);
});

test('impersonated session cannot create an api key', function () {
    [$target, $tenantAdmin] = createTenantAdminForImpersonation();

    $token = $tenantAdmin->createToken('impersonation:1', ['*', 'impersonation'], now()->addMinutes(30))->plainTextToken;

    test()->withToken($token)
        ->postJson("http://{$target->subdomain}.ethr.test/api/v1/api-keys", [
            'name' => 'Malicious Key',
        ])
        ->assertStatus(403);
});

test('impersonated session cannot delete resources', function () {
    [$target, $tenantAdmin] = createTenantAdminForImpersonation();

    $token = $tenantAdmin->createToken('impersonation:1', ['*', 'impersonation'], now()->addMinutes(30))->plainTextToken;

    test()->withToken($token)
        ->deleteJson("http://{$target->subdomain}.ethr.test/api/v1/api-keys/some-public-id")
        ->assertStatus(403);
});

test('a normal (non-impersonation) session can still change its password', function () {
    $tenant = createTenant();
    $user = createUser([
        'role' => UserRole::TENANT_ADMIN,
        'password' => bcrypt('OldPassword123!'),
    ], $tenant);
    $token = $user->createToken('auth', ['*'])->plainTextToken;

    test()->withToken($token)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/password/change", [
            'current_password' => 'OldPassword123!',
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ])->assertOk();
});

// ── impersonated_by audit trail ──

test('actions taken during impersonation are tagged with impersonated_by', function () {
    [, $admin] = superAdminWithMfaToken();
    [$target, $tenantAdmin] = createTenantAdminForImpersonation();

    $token = $tenantAdmin->createToken("impersonation:{$admin->id}", ['*', 'impersonation'], now()->addMinutes(30))->plainTextToken;

    test()->withToken($token)
        ->putJson("http://{$target->subdomain}.ethr.test/api/v1/settings", [
            'settings' => ['grace_period_minutes' => 15],
        ])->assertOk();

    $entry = AuditLog::withoutGlobalScopes()
        ->where('action', 'settings.updated')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->payload['impersonated_by'])->toBe($admin->id);
});
