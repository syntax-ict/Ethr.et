<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantProfileAssignment;
use App\Models\User;
use App\Services\Auth\SessionCookie;
use App\Services\Onboarding\IndustryCatalog;
use Carbon\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
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
            'settings' => ['run_day' => 15],
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
        ->postJson("http://{$target->subdomain}.ethr.test/api/v1/auth/impersonation/exit")
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
        ->postJson("http://{$target->subdomain}.ethr.test/api/v1/auth/impersonation/exit")
        ->assertOk()
        ->assertJsonPath('session_restored', true)
        ->assertJsonPath('tenant', $adminTenant->subdomain)
        // Single host: the console is on this origin, so there is nowhere else
        // to send the browser — the client navigates to /admin itself.
        ->assertJsonPath('return_url', null);

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
        ->postJson("http://{$target->subdomain}.ethr.test/api/v1/auth/impersonation/exit")
        ->assertOk()
        ->assertJsonPath('session_restored', false)
        ->assertCookieExpired(SessionCookie::NAME);

    expect($demoted->tokens()->count())->toBe(0);
});

test('a normal (non-impersonation) session cannot exit impersonation', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $token = $user->createToken('auth', ['*'])->plainTextToken;

    // 403, not the 409 this used to answer: the endpoint now sits in the
    // ordinary authenticated group, reachable by every tenant user, so refusing
    // a session without the impersonation ability is an authorisation decision.
    test()->withToken($token)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/impersonation/exit")
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/not-impersonating');

    // Refused before anything happened: the caller's own session survives.
    expect($user->tokens()->count())->toBe(1);
});

// A-Z review H-13 (2026-11-04): an organisation with a deactivated admin
// listed first impersonated that one, and the session was revoked at once.
test('impersonation acts as an active admin of the organisation', function () {
    [$tenant, , $code, $token] = superAdminWithMfaToken();
    $target = Tenant::factory()->create();
    User::factory()->create(['tenant_id' => $target->id, 'role' => UserRole::TENANT_ADMIN, 'status' => 'inactive']);
    $active = User::factory()->create(['tenant_id' => $target->id, 'role' => UserRole::TENANT_ADMIN, 'status' => 'active']);

    test()->withToken($token)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$target->public_id}/impersonate", ['code' => $code])
        ->assertOk();

    expect(PersonalAccessToken::query()->latest('id')->firstOrFail()->tokenable_id)->toBe($active->id);
});

// ── The organisation's kind in law and its statutory settings ──
//
// A kind decides which statutory rules apply, and so which agency the pension
// schedule is addressed to. An operator looking at an organisation as it sees
// itself must not move it, but may still fix a name, timezone or language for a
// customer who asked.

/**
 * A bearer request that carries no tenant, user or guard left over from the
 * request before it. A backend test of a credential-authenticated route that
 * keeps the previous request's context passes on state a real request never
 * has (root CLAUDE.md, section 4).
 */
function freshBearer(string $token): void
{
    app()->forgetScopedInstances();
    app('auth')->forgetGuards();
    test()->withToken($token);
}

/** @return array{0: Tenant, 1: string, 2: string} the organisation, an impersonation token, and its own administrator's token */
function kindFixture(): array
{
    $tenant = Tenant::factory()->create(['organization_type' => 'private']);
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);

    return [
        $tenant,
        $admin->createToken('impersonation:1', ['*', 'impersonation'], now()->addMinutes(30))->plainTextToken,
        $admin->createToken('auth', ['*'])->plainTextToken,
    ];
}

function kindUrl(Tenant $tenant, string $path = 'settings/organization'): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

/** Rows that prove a save of the kind happened. */
function kindWrites(Tenant $tenant): array
{
    return [
        'assignments' => TenantProfileAssignment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count(),
        'audit' => AuditLog::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('action', 'settings.organization_updated')->count(),
        'kind' => Tenant::query()->whereKey($tenant->id)->value('organization_type'),
    ];
}

test('an impersonated session cannot change the kind of organisation', function () {
    [$tenant, $impersonation] = kindFixture();
    $before = kindWrites($tenant);

    freshBearer($impersonation);
    test()->putJson(kindUrl($tenant), ['organization_type' => 'ngo'])
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/impersonation-restricted')
        ->assertJsonPath('status', 403);

    expect(kindWrites($tenant))->toBe($before)
        ->and($before['kind'])->toBe('private');
});

test('an impersonated session cannot schedule or withdraw a change of kind', function (array $body) {
    [$tenant, $impersonation] = kindFixture();
    $before = kindWrites($tenant);

    freshBearer($impersonation);
    test()->putJson(kindUrl($tenant), $body)
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/impersonation-restricted');

    expect(kindWrites($tenant))->toBe($before);
})->with([
    'a date' => [['effective_from' => '2027-01-01']],
    'a withdrawal' => [['cancel_pending_organization_type' => true]],
    'the same kind, confirmed' => [['organization_type' => 'private']],
    'a name sent with a kind' => [['name' => 'Renamed', 'organization_type' => 'ngo']],
]);

test('an impersonated session can still correct the name, timezone and language', function () {
    [$tenant, $impersonation] = kindFixture();
    $before = kindWrites($tenant);

    freshBearer($impersonation);
    test()->putJson(kindUrl($tenant), ['name' => 'Corrected Name', 'timezone' => 'Africa/Addis_Ababa', 'locale' => 'am'])
        ->assertOk()
        ->assertJsonPath('organization.name', 'Corrected Name')
        ->assertJsonPath('organization.organization_type', 'private');

    $after = kindWrites($tenant);
    expect(Tenant::query()->whereKey($tenant->id)->value('name'))->toBe('Corrected Name')
        ->and($after['kind'])->toBe('private')
        ->and($after['assignments'])->toBe($before['assignments'])
        ->and($after['audit'])->toBe($before['audit'] + 1);
});

test('an impersonated session cannot change a statutory setting', function (string $key, mixed $value) {
    [$tenant, $impersonation] = kindFixture();
    $stored = Tenant::query()->whereKey($tenant->id)->value('settings');

    freshBearer($impersonation);
    test()->putJson(kindUrl($tenant, 'settings'), ['settings' => ['run_day' => 14, $key => $value]])
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/impersonation-restricted');

    // Refused whole: the permitted key sent beside it was not saved either.
    expect(Tenant::query()->whereKey($tenant->id)->value('settings'))->toEqual($stored);
})->with([
    'the pension scheme' => ['pension_scheme', 'public'],
    'the fiscal year' => ['fiscal_year_start_month', 11],
    'the Pagume rule' => ['pagumen_proration_strategy', 'daily_rate'],
    'working days per month' => ['working_days_per_month', 26],
]);

test('the own administrator of the organisation can still change the kind and a statutory setting', function () {
    [$tenant, , $own] = kindFixture();

    freshBearer($own);
    test()->putJson(kindUrl($tenant), ['organization_type' => 'ngo'])
        ->assertOk()
        ->assertJsonPath('organization.organization_type', 'ngo');

    freshBearer($own);
    test()->putJson(kindUrl($tenant, 'settings'), ['settings' => ['pension_scheme' => 'public']])
        ->assertOk();

    expect(kindWrites($tenant)['kind'])->toBe('ngo')
        ->and(Tenant::query()->whereKey($tenant->id)->value('settings')['pension_scheme'])->toBe('public');
});

// The setup wizard's apply writes the same kind and the same settings keys as
// the Settings routes, so it is refused by the same fields.

test('an impersonated session cannot apply the setup wizard with a kind', function () {
    [$tenant, $impersonation] = kindFixture();
    $before = kindWrites($tenant);
    $settings = Tenant::query()->whereKey($tenant->id)->value('settings');

    freshBearer($impersonation);
    test()->postJson(kindUrl($tenant, 'onboarding/configuration/apply'), [
        'organization_type' => 'ngo',
        'plan' => ['settings' => ['run_day' => 12]],
    ])
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/impersonation-restricted');

    expect(kindWrites($tenant))->toBe($before)
        ->and(Tenant::query()->whereKey($tenant->id)->value('settings'))->toEqual($settings)
        ->and(Tenant::query()->whereKey($tenant->id)->value('organization_type'))->toBe('private');
});

test('an impersonated session cannot apply a statutory setting through the setup wizard', function (string $key, mixed $value) {
    [$tenant, $impersonation] = kindFixture();
    $before = kindWrites($tenant);
    $settings = Tenant::query()->whereKey($tenant->id)->value('settings');

    freshBearer($impersonation);
    test()->postJson(kindUrl($tenant, 'onboarding/configuration/apply'), [
        'plan' => ['settings' => ['run_day' => 12, $key => $value]],
    ])
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/impersonation-restricted');

    expect(kindWrites($tenant))->toBe($before)
        ->and(Tenant::query()->whereKey($tenant->id)->value('settings'))->toEqual($settings);
})->with([
    'the pension scheme' => ['pension_scheme', 'public'],
    'the fiscal year' => ['fiscal_year_start_month', 11],
    'the Pagume rule' => ['pagumen_proration_strategy', 'daily_rate'],
    'working days per month' => ['working_days_per_month', 26],
]);

test('an impersonated session can still preview the setup wizard, which writes nothing', function () {
    [$tenant, $impersonation] = kindFixture();
    $before = kindWrites($tenant);

    freshBearer($impersonation);
    test()->postJson(kindUrl($tenant, 'onboarding/configuration/preview'), [
        'industry' => app(IndustryCatalog::class)->keys()[0],
        'organization_type' => 'ngo',
    ])->assertOk();

    expect(kindWrites($tenant))->toBe($before);
});

test('the own administrator of the organisation can still apply the setup wizard with a kind', function () {
    [$tenant, , $own] = kindFixture();

    freshBearer($own);
    test()->postJson(kindUrl($tenant, 'onboarding/configuration/apply'), [
        'organization_type' => 'ngo',
        'plan' => ['settings' => ['run_day' => 12, 'pension_scheme' => 'public']],
    ])->assertOk();

    $stored = Tenant::query()->whereKey($tenant->id)->first();
    expect($stored->organization_type)->toBe('ngo')
        ->and($stored->settings['pension_scheme'])->toBe('public');
});
