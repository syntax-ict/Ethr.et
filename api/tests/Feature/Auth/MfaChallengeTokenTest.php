<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Middleware\RejectUnverifiedMfaToken;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/**
 * The credential issued after a *password* check, before the second factor.
 *
 * It used to be created with `['*']` abilities and merely a shorter lifetime —
 * so a stolen password bought a fully privileged five-minute session on an
 * MFA-protected account, renewable by signing in again. Verified against the
 * running stack before the fix: a pre-MFA token drove `POST
 * /admin/failed-jobs/retry-all` to a 200.
 */
function loginAwaitingMfa(User $user, string $host): string
{
    $response = test()->postJson("http://{$host}/api/v1/auth/login", [
        'email' => $user->email,
        'password' => 'password',
    ])->assertOk();

    expect($response->json('mfa_required'))->toBeTrue();

    // CLAUDE.md's testing rules: forget the guards between requests that change
    // auth state, or the next request is resolved against the user the login
    // just authenticated instead of against the bearer token under test — which
    // makes a challenge-token assertion silently pass through.
    app('auth')->forgetGuards();

    return $response->json('access_token');
}

describe('a token that still owes MFA', function () {
    it('carries only the challenge ability, not full access', function () {
        $tenant = createTenant();
        $user = createUser([
            'role' => UserRole::TENANT_ADMIN,
            'mfa_enabled' => true,
            'password' => bcrypt('password'),
        ], $tenant);

        loginAwaitingMfa($user, $tenant->subdomain.'.ethr.test');

        $token = $user->tokens()->latest('id')->first();

        expect($token->abilities)->toBe([RejectUnverifiedMfaToken::CHALLENGE_ABILITY])
            ->and($token->abilities)->not->toContain('*');
    });

    it('is refused on ordinary tenant endpoints', function () {
        $tenant = createTenant();
        $user = createUser([
            'role' => UserRole::TENANT_ADMIN,
            'mfa_enabled' => true,
            'password' => bcrypt('password'),
        ], $tenant);

        $host = $tenant->subdomain.'.ethr.test';
        $token = loginAwaitingMfa($user, $host);

        test()->withHeader('Authorization', "Bearer {$token}")
            ->getJson("http://{$host}/api/v1/employees")
            ->assertForbidden()
            ->assertJsonPath('type', 'https://ethr.et/errors/mfa-incomplete');
    });

    it('is refused on the platform console, which it previously drove to a 200', function () {
        $admin = User::factory()->create([
            'tenant_id' => null,
            'role' => UserRole::SUPER_ADMIN,
            'mfa_enabled' => true,
            'password' => bcrypt('password'),
        ]);

        $token = loginAwaitingMfa($admin, 'admin.ethr.test');

        test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson('http://admin.ethr.test/api/v1/admin/failed-jobs/retry-all')
            ->assertForbidden();

        app('auth')->forgetGuards();

        test()->withHeader('Authorization', "Bearer {$token}")
            ->getJson('http://admin.ethr.test/api/v1/admin/tenants')
            ->assertForbidden();
    });

    it('may still reach the endpoints that finish or abandon the sign-in', function () {
        // Refusing these too would strand the user on a screen they cannot
        // complete and cannot leave.
        $tenant = createTenant();
        $user = createUser([
            'role' => UserRole::EMPLOYEE,
            'mfa_enabled' => true,
            'password' => bcrypt('password'),
        ], $tenant);

        $host = $tenant->subdomain.'.ethr.test';
        $token = loginAwaitingMfa($user, $host);

        test()->withHeader('Authorization', "Bearer {$token}")
            ->getJson("http://{$host}/api/v1/auth/me")
            ->assertOk();
    });

    it('becomes a full session only after the code is verified', function () {
        $tenant = createTenant();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();
        $user = createUser([
            'role' => UserRole::TENANT_ADMIN,
            'mfa_enabled' => true,
            'mfa_secret' => encrypt($secret),
            'password' => bcrypt('password'),
        ], $tenant);

        $host = $tenant->subdomain.'.ethr.test';
        $token = loginAwaitingMfa($user, $host);

        $verified = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson("http://{$host}/api/v1/auth/mfa/verify", [
                'code' => $google2fa->getCurrentOtp($secret),
            ])->assertOk();

        $session = $verified->json('access_token');
        expect($session)->not->toBeNull();

        app('auth')->forgetGuards();

        test()->withHeader('Authorization', "Bearer {$session}")
            ->getJson("http://{$host}/api/v1/employees")
            ->assertOk();
    });
});

describe('a normal sign-in', function () {
    it('is unaffected — no MFA owed, full abilities', function () {
        $tenant = createTenant();
        $user = createUser([
            'role' => UserRole::TENANT_ADMIN,
            'mfa_enabled' => false,
            'password' => bcrypt('password'),
        ], $tenant);

        $host = $tenant->subdomain.'.ethr.test';

        $response = test()->postJson("http://{$host}/api/v1/auth/login", [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        expect($response->json('mfa_required'))->toBeFalse();

        test()->withHeader('Authorization', 'Bearer '.$response->json('access_token'))
            ->getJson("http://{$host}/api/v1/employees")
            ->assertOk();
    });
});
