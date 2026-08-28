<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SessionCookie;
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

/**
 * Headers that make a request look like it came from the SPA.
 *
 * Sanctum only runs the stateful middleware — and therefore only attaches
 * queued cookies to the response — for requests whose Origin is a configured
 * stateful domain. Without this the cookie assertions below pass vacuously
 * against a response that never carried a cookie at all.
 */
function fromSpaOrigin(): array
{
    return ['Origin' => 'http://localhost:3000'];
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

    // Refused with 403 by `RequirePlatformMfa`, not the controller's own 409.
    // The requirement is unchanged and now enforced one layer earlier, for the
    // whole console rather than for impersonation alone: an operator without MFA
    // can no longer change *anything* across tenants, of which impersonation was
    // only the loudest example.
    test()->withToken($token)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/impersonate", [
            'code' => '123456',
        ])->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/mfa-required');
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

test('impersonation plants the token in the session cookie the browser actually uses', function () {
    [$tenant, , $code, $token] = superAdminWithMfaToken();
    [$target] = createTenantAdminForImpersonation();

    $response = test()->withToken($token)
        ->withHeaders(fromSpaOrigin())
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/impersonate", [
            'code' => $code,
        ])->assertOk();

    // The SPA sends no Authorization header, so the cookie is the only thing
    // that makes the redirect land as the tenant admin rather than as the
    // super admin with a tenant header attached.
    //
    // encrypted: false is the assertion's whole point, not a workaround. This
    // cookie is deliberately excluded from encryption in bootstrap/app.php so
    // that AuthenticateFromCookie reads a usable Sanctum token rather than
    // ciphertext; assertCookie decrypts by default, which throws a
    // DecryptException on the plaintext value it is supposed to be checking.
    $response->assertCookie(SessionCookie::NAME, $response->json('token'), encrypted: false);
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

// ── Exiting impersonation ──

test('an impersonated session can exit impersonation, revoking its token', function () {
    [$target, $tenantAdmin] = createTenantAdminForImpersonation();

    $token = $tenantAdmin->createToken('impersonation:1', ['*', 'impersonation'], now()->addMinutes(30))->plainTextToken;

    test()->withToken($token)
        ->postJson("http://{$target->subdomain}.ethr.test/api/v1/admin/exit-impersonation")
        ->assertOk()
        ->assertJsonPath('message', 'Impersonation session ended.');

    // The impersonation token is revoked so it can no longer be used.
    expect($tenantAdmin->tokens()->count())->toBe(0);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'admin.tenant.impersonation_ended',
    ]);
});

test('exiting impersonation restores the super admin to their own session', function () {
    [$adminTenant, $admin] = superAdminWithMfaToken();
    [$target, $tenantAdmin] = createTenantAdminForImpersonation();

    $token = $tenantAdmin->createToken("impersonation:{$admin->id}", ['*', 'impersonation'], now()->addMinutes(30))->plainTextToken;

    $response = test()->withToken($token)
        ->withHeaders(fromSpaOrigin())
        ->postJson("http://{$target->subdomain}.ethr.test/api/v1/admin/exit-impersonation")
        ->assertOk()
        ->assertJsonPath('session_restored', true)
        ->assertJsonPath('tenant', $adminTenant->subdomain);

    // A fresh session for the admin, not the revoked impersonation token: the
    // browser is holding that cookie, so leaving it stale would 401 on the very
    // next request and read as a random logout. Stamped like any other login,
    // so it does not surface as a blank row in the active-sessions list.
    $restored = $admin->tokens()->latest('id')->first();
    expect($restored->name)->toBe('auth')
        ->and($restored->device_hash)->not->toBeNull()
        ->and($restored->expires_at)->not->toBeNull();
    $response->assertCookieNotExpired(SessionCookie::NAME);
    expect($tenantAdmin->tokens()->count())->toBe(0);
});

test('exiting a token whose impersonator is no longer a super admin restores nothing', function () {
    [$target, $tenantAdmin] = createTenantAdminForImpersonation();
    $demoted = User::factory()->create([
        'tenant_id' => $target->id,
        'role' => UserRole::TENANT_ADMIN,
    ]);

    $token = $tenantAdmin->createToken("impersonation:{$demoted->id}", ['*', 'impersonation'], now()->addMinutes(30))->plainTextToken;

    test()->withToken($token)
        ->withHeaders(fromSpaOrigin())
        ->postJson("http://{$target->subdomain}.ethr.test/api/v1/admin/exit-impersonation")
        ->assertOk()
        ->assertJsonPath('session_restored', false)
        ->assertCookieExpired(SessionCookie::NAME);

    expect($demoted->tokens()->count())->toBe(0);
});

test('a normal (non-impersonation) session cannot exit impersonation', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $token = $user->createToken('auth', ['*'])->plainTextToken;

    test()->withToken($token)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/exit-impersonation")
        ->assertStatus(409);
});
