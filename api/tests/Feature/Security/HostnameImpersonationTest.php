<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SessionCookie;
use App\Services\Auth\SessionHandoff;
use App\Services\CurrentTenant;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

/**
 * Impersonation end to end in hostname mode — what production runs.
 *
 * `ImpersonationTest` exercises single-host mode only: it never sets
 * `app.domain`, so `EnsurePlatformContext` is inert there and the handoff is
 * never taken. Audit N19 found, by reading, that in hostname mode the way *out*
 * of an impersonation was a 404: the exit endpoint sat under `/admin`, behind a
 * middleware that refuses every `/admin` route on a tenant host — which is the
 * only host an impersonated session lives on.
 *
 * Every request here is made on the host a browser would make it on: start on
 * admin.ethr.test, claim and work and exit on habru.ethr.test.
 */
beforeEach(function () {
    config([
        'app.domain' => 'ethr.test',
        'app.url' => 'http://ethr.test',
        // Queued cookies are only attached for requests Sanctum considers
        // stateful, which it decides by Origin — production lists the tenant
        // hosts the same way (SANCTUM_STATEFUL_DOMAINS=ethr.et,*.ethr.et).
        'sanctum.stateful' => ['ethr.test', '*.ethr.test'],
    ]);
});

/**
 * A tenant-less platform super admin with MFA, plus a bearer token for the
 * platform host. Built with no tenant resolved: BelongsToTenant's `creating`
 * hook would otherwise fill a null tenant_id from CurrentTenant.
 *
 * @return array{0: User, 1: string, 2: string}
 */
function hostnameModeOperator(): array
{
    app(CurrentTenant::class)->forget();

    $secret = 'JBSWY3DPEHPK3PXP';
    $operator = User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'mfa_enabled' => true,
        'mfa_secret' => $secret,
    ]);

    return [
        $operator,
        $operator->createToken('auth', ['*'])->plainTextToken,
        (new Google2FA)->getCurrentOtp($secret),
    ];
}

/** @return array{0: Tenant, 1: User} */
function hostnameModeTarget(string $subdomain = 'habru'): array
{
    $tenant = Tenant::factory()->create(['subdomain' => $subdomain]);
    $tenantAdmin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN,
        'mfa_enabled' => false,
    ]);
    app(CurrentTenant::class)->forget();

    return [$tenant, $tenantAdmin];
}

/** Start an impersonation on the platform host and return the response. */
function startOnPlatformHost(string $operatorToken, string $code, Tenant $target): TestResponse
{
    return test()->withToken($operatorToken)
        ->withHeaders(['Origin' => 'http://admin.ethr.test'])
        ->postJson(
            "http://admin.ethr.test/api/v1/admin/tenants/{$target->public_id}/impersonate",
            ['code' => $code],
        );
}

/** The nonce a handoff URL carries in its fragment. */
function nonceFrom(string $handoffUrl): string
{
    parse_str((string) parse_url($handoffUrl, PHP_URL_FRAGMENT), $fragment);

    return (string) $fragment['nonce'];
}

/**
 * Drop every trace of the previous request's identity, as a browser on a
 * different host would have: no bearer header, no cached guard user, no tenant.
 */
function asFreshBrowserOn(string $host): void
{
    test()->withoutToken()->flushHeaders();
    app('auth')->forgetGuards();
    app(CurrentTenant::class)->forget();
    // withCredentials(): the JSON helpers send no cookies without it, and the
    // session cookie is the only credential a browser on this host holds.
    test()->withHeaders(['Origin' => "http://{$host}"])->withCredentials();
}

function claimOnTenantHost(Tenant $tenant, string $nonce): TestResponse
{
    asFreshBrowserOn("{$tenant->subdomain}.ethr.test");

    return test()->postJson(
        "http://{$tenant->subdomain}.ethr.test/api/v1/auth/session/claim",
        ['nonce' => $nonce],
    );
}

function sessionCookieValue(TestResponse $response): ?string
{
    return collect($response->headers->getCookies())
        ->firstWhere(fn ($cookie) => $cookie->getName() === SessionCookie::NAME)
        ?->getValue();
}

it('starts on the platform host, claims and works on the tenant host, and exits there', function () {
    [$operator, $operatorToken, $code] = hostnameModeOperator();
    [$tenant, $tenantAdmin] = hostnameModeTarget();

    // 1. Platform host: no token in the body and no cookie on this host — the
    //    session is meant for habru.ethr.test, and only the handoff gets it there.
    $started = startOnPlatformHost($operatorToken, $code, $tenant)->assertOk();

    expect($started->json('token'))->toBeNull()
        ->and(sessionCookieValue($started))->toBeNull()
        ->and($started->json('handoff_url'))
        ->toStartWith('http://habru.ethr.test/impersonate/claim#nonce=');

    // 2. Tenant host: the nonce buys a host-only session cookie.
    $claimed = claimOnTenantHost($tenant, nonceFrom($started->json('handoff_url')))->assertOk();
    $sessionToken = sessionCookieValue($claimed);
    expect($sessionToken)->not->toBeNull();

    // 3. That cookie authenticates as the tenant admin on the tenant host.
    asFreshBrowserOn('habru.ethr.test');
    test()->withUnencryptedCookie(SessionCookie::NAME, $sessionToken)
        ->getJson('http://habru.ethr.test/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.public_id', $tenantAdmin->public_id);

    // 4. Exit, on the tenant host. This was a 404 before N19.
    asFreshBrowserOn('habru.ethr.test');
    $exited = test()->withUnencryptedCookie(SessionCookie::NAME, $sessionToken)
        ->postJson('http://habru.ethr.test/api/v1/auth/impersonation/exit')
        ->assertOk()
        ->assertJsonPath('return_url', 'http://admin.ethr.test/admin')
        // Nothing is minted on the tenant host: the operator's own session
        // lives on the platform host and was never touched.
        ->assertJsonPath('session_restored', false)
        ->assertCookieExpired(SessionCookie::NAME);

    expect($exited->json('token'))->toBeNull()
        ->and($tenantAdmin->tokens()->count())->toBe(0)
        ->and($operator->tokens()->count())->toBe(1);

    // 5. The session really ended: the same cookie no longer authenticates.
    asFreshBrowserOn('habru.ethr.test');
    test()->withUnencryptedCookie(SessionCookie::NAME, $sessionToken)
        ->getJson('http://habru.ethr.test/api/v1/auth/me')
        ->assertUnauthorized();

    // 6. Both ends are on the record. The start is the operator's own act on
    //    the platform host, which resolves no tenant, so it is written by the
    //    operator against the Tenant row — the platform audit trail. The claim
    //    and the exit happen on the tenant host and are filed under the tenant.
    $started = AuditLog::withoutGlobalScopes()
        ->where('auditable_type', Tenant::class)
        ->where('auditable_id', $tenant->id)
        ->where('user_id', $operator->id)
        ->pluck('action')
        ->all();
    expect($started)->toContain(
        'admin.tenant.impersonated',
        'admin.tenant.impersonation_handoff_issued',
    );

    $onTenantHost = AuditLog::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->pluck('action')
        ->all();
    expect($onTenantHost)->toContain(
        'admin.tenant.impersonation_handoff_claimed',
        'admin.tenant.impersonation_ended',
    );

    $ended = AuditLog::withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('action', 'admin.tenant.impersonation_ended')
        ->sole();
    expect($ended->payload['impersonated_by'])->toBe($operator->id)
        ->and($ended->payload['host'])->toBe('habru.ethr.test');
});

it('does not send a demoted operator back to the platform console', function () {
    [$operator, $operatorToken, $code] = hostnameModeOperator();
    [$tenant] = hostnameModeTarget();

    $started = startOnPlatformHost($operatorToken, $code, $tenant)->assertOk();
    $sessionToken = sessionCookieValue(
        claimOnTenantHost($tenant, nonceFrom($started->json('handoff_url')))->assertOk()
    );

    // Demoted mid-session. The resolveImpersonator() re-check is what decides,
    // not the token name.
    $operator->forceFill(['role' => UserRole::TENANT_ADMIN])->save();

    asFreshBrowserOn('habru.ethr.test');
    test()->withUnencryptedCookie(SessionCookie::NAME, $sessionToken)
        ->postJson('http://habru.ethr.test/api/v1/auth/impersonation/exit')
        ->assertOk()
        ->assertJsonPath('return_url', null)
        ->assertCookieExpired(SessionCookie::NAME);
});

it('refuses an ordinary tenant session at the exit endpoint', function () {
    [$tenant, $tenantAdmin] = hostnameModeTarget();
    $ordinary = $tenantAdmin->createToken('auth', ['*'])->plainTextToken;

    asFreshBrowserOn('habru.ethr.test');
    test()->withUnencryptedCookie(SessionCookie::NAME, $ordinary)
        ->postJson('http://habru.ethr.test/api/v1/auth/impersonation/exit')
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/not-impersonating');

    // Refused before anything happened: the session is untouched and no
    // impersonation_ended row was invented for it.
    expect($tenantAdmin->tokens()->count())->toBe(1)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'admin.tenant.impersonation_ended')->exists())
        ->toBeFalse();
});

it('refuses a spent nonce', function () {
    [, $operatorToken, $code] = hostnameModeOperator();
    [$tenant] = hostnameModeTarget();

    $nonce = nonceFrom(startOnPlatformHost($operatorToken, $code, $tenant)->assertOk()->json('handoff_url'));

    claimOnTenantHost($tenant, $nonce)->assertOk();
    claimOnTenantHost($tenant, $nonce)->assertStatus(422);
});

it('refuses a nonce once its 30 seconds have passed', function () {
    [, $operatorToken, $code] = hostnameModeOperator();
    [$tenant] = hostnameModeTarget();

    $nonce = nonceFrom(startOnPlatformHost($operatorToken, $code, $tenant)->assertOk()->json('handoff_url'));

    $this->travel(31)->seconds();

    $refused = claimOnTenantHost($tenant, $nonce)->assertStatus(422);
    expect(sessionCookieValue($refused))->toBeNull();
});

it('points the way back at the platform console over the configured scheme', function () {
    config(['app.url' => 'https://www.ethr.test']);

    expect(SessionHandoff::platformConsoleUrl())->toBe('https://admin.ethr.test/admin');
});
