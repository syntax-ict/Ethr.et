<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\Broadcast;

/**
 * The hostname security model.
 *
 * Every assertion here corresponds to a finding from the 2026-08-15 domain audit
 * that had no test at the time — which is how two of them reached production
 * unnoticed. If a control below is reverted, its test must fail; a test that
 * still passes without the fix is not protecting anything.
 *
 * @see docs/audits/DOMAIN_TLS_MULTITENANT_AUDIT.md
 */

/**
 * A genuinely tenant-less platform super admin.
 *
 * `User::factory()->create(['tenant_id' => null])` does NOT produce one while a
 * tenant is resolved: BelongsToTenant's `creating` hook fills a null tenant_id
 * from CurrentTenant, so the user comes back owned by whichever tenant the test
 * happened to set up. A "super admin" built that way then satisfies the ordinary
 * tenant check and never exercises the exemption at all — which is exactly how
 * the cross-tenant test in this file was passing for the wrong reason.
 */
function createSuperAdmin(): User
{
    $current = app(CurrentTenant::class);
    $previous = $current->get();
    $current->forget();

    $user = User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
    ]);

    if ($previous !== null) {
        $current->set($previous);
    }

    return $user;
}

/** A tenant with an admin and one employee, ready to be queried across hosts. */
function tenantWithAdmin(string $subdomain): array
{
    $tenant = Tenant::factory()->create(['subdomain' => $subdomain]);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN,
    ]);

    Employee::factory()->create(['tenant_id' => $tenant->id]);

    return [$tenant, $admin];
}

// ── B-2: a token is not a passport to other tenants ─────────────────────────

it('refuses a valid token aimed at another tenant host', function () {
    [, $habruAdmin] = tenantWithAdmin('habru');
    [$woldia] = tenantWithAdmin('woldia');

    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;

    test()->withToken($token)
        ->getJson("http://{$woldia->subdomain}.ethr.et/api/v1/employees")
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-mismatch');
});

it('still serves a token aimed at its own tenant host', function () {
    [$habru, $habruAdmin] = tenantWithAdmin('habru');

    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;

    test()->withToken($token)
        ->getJson("http://{$habru->subdomain}.ethr.et/api/v1/employees")
        ->assertOk();
});

it('lets a super admin cross tenants, which the platform console requires', function () {
    [$habru] = tenantWithAdmin('habru');

    $superAdmin = createSuperAdmin();
    $token = $superAdmin->createToken('auth', ['*'])->plainTextToken;

    test()->withToken($token)
        ->getJson("http://{$habru->subdomain}.ethr.et/api/v1/employees")
        ->assertOk();
});

// ── B-1: the hostname outranks anything the client can set ──────────────────

it('ignores X-Tenant when the host already names a tenant', function () {
    [$habru, $habruAdmin] = tenantWithAdmin('habru');
    [$woldia] = tenantWithAdmin('woldia');

    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;

    // Subdomain resolution runs first, so the header is dead weight: the request
    // stays on habru and succeeds rather than being redirected to woldia.
    test()->withToken($token)
        ->withHeaders(['X-Tenant' => $woldia->subdomain])
        ->getJson("http://{$habru->subdomain}.ethr.et/api/v1/employees")
        ->assertOk();
});

it('refuses X-Tenant outright once the environment is not local or testing', function () {
    [, $habruAdmin] = tenantWithAdmin('habru');

    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;

    app()->detectEnvironment(fn () => 'production');

    // A feature test reuses one container for setup and request, so the tenant
    // that tenantWithAdmin() resolved during setup would still be in scope and
    // the assertion below would pass for the wrong reason. A real request
    // starts with nothing resolved; say so explicitly.
    app(CurrentTenant::class)->forget();

    // No subdomain on this host, and the header is now refused, so no tenant is
    // resolved at all — BelongsToTenant degrades to WHERE 0 = 1 rather than
    // serving whatever tenant the client asked for.
    test()->withToken($token)
        ->withHeaders(['X-Tenant' => 'habru'])
        ->getJson('http://ethr.et/api/v1/employees')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('still honours X-Tenant in local development, where there is no subdomain to read', function () {
    [, $habruAdmin] = tenantWithAdmin('habru');

    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;

    // Same reasoning as above: clear the setup's tenant so the header is what
    // actually resolves it, not a leftover from the container.
    app(CurrentTenant::class)->forget();

    test()->withToken($token)
        ->withHeaders(['X-Tenant' => 'habru'])
        ->getJson('http://localhost/api/v1/employees')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── A-3: a forwarded host header must not move the tenant ───────────────────

it('does not let X-Forwarded-Host choose the tenant', function () {
    [$habru, $habruAdmin] = tenantWithAdmin('habru');
    [$woldia] = tenantWithAdmin('woldia');

    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;

    // No proxy is trusted, so Symfony reads Host and ignores the forwarded
    // header. Were that ever reversed, this request would land on woldia and
    // be rejected by the tenant guard — either way it must not return woldia's
    // data as though the header had won.
    test()->withToken($token)
        ->withHeaders(['X-Forwarded-Host' => "{$woldia->subdomain}.ethr.et"])
        ->getJson("http://{$habru->subdomain}.ethr.et/api/v1/employees")
        ->assertOk();
});

// ── A-2 / C-1: reserved hostnames are never tenants ─────────────────────────

it('never resolves a reserved subdomain as a tenant', function (string $reserved) {
    // A tenant deliberately squatting a reserved name must still not be reachable
    // by hostname — this is what keeps admin.ethr.et out of tenant context.
    Tenant::factory()->create(['subdomain' => $reserved]);

    $superAdmin = createSuperAdmin();
    $token = $superAdmin->createToken('auth', ['*'])->plainTextToken;

    test()->withToken($token)
        ->getJson("http://{$reserved}.ethr.et/api/v1/health")
        ->assertOk();
})->with([
    'admin', 'platform', 'api', 'app', 'www',
    'mail', 'smtp', 'ftp',
    'cdn', 'static', 'assets',
    'status', 'support', 'help', 'docs',
]);

// ── M-2: WebSocket channels are tenant-scoped too ───────────────────────────

/**
 * Channel authorisation only actually runs on a real broadcaster.
 *
 * phpunit.xml sets BROADCAST_CONNECTION=null, and NullBroadcaster::auth() is an
 * empty method — every /broadcasting/auth request returns 200 with an empty
 * body no matter what the channel callback would have decided. A test left on
 * the null driver therefore passes whether the tenant check exists or not,
 * which is worse than having no test.
 *
 * Reverb is Pusher-compatible: it throws AccessDeniedHttpException before
 * signing when the callback denies, and signs locally with HMAC when it allows,
 * so neither path touches the network.
 */
function useRealBroadcaster(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
    ]);

    Broadcast::purge('reverb');

    // Broadcast::channel() registers callbacks on whichever driver is current,
    // and routes/channels.php ran at boot against the null driver. Without
    // re-registering, the fresh reverb broadcaster knows no channels at all and
    // rejects everything with 403 — which would make the "refuses" test above
    // pass for entirely the wrong reason.
    require base_path('routes/channels.php');
}

it('refuses a device channel belonging to another tenant', function () {
    [$habru, $habruAdmin] = tenantWithAdmin('habru');
    [$woldia] = tenantWithAdmin('woldia');

    // The device the caller has no business watching.
    app(CurrentTenant::class)->set($woldia);
    $foreignDevice = Device::factory()->create([
        'tenant_id' => $woldia->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $woldia->id])->id,
        'adapter_type' => 'mock',
    ]);

    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;

    // A permission check alone would pass here — the tenant clause is what
    // stops it. Without it this leak travels over the WebSocket and bypasses
    // every HTTP-layer control.
    useRealBroadcaster();

    test()->withToken($token)
        ->postJson("http://{$habru->subdomain}.ethr.et/api/v1/broadcasting/auth", [
            'socket_id' => '1234.5678',
            'channel_name' => "private-device.{$foreignDevice->public_id}",
        ])
        ->assertForbidden();
});

it('allows a device channel within the caller tenant', function () {
    [$habru, $habruAdmin] = tenantWithAdmin('habru');

    $ownDevice = Device::factory()->create([
        'tenant_id' => $habru->id,
        'branch_id' => Branch::factory()->create(['tenant_id' => $habru->id])->id,
        'adapter_type' => 'mock',
    ]);

    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;

    useRealBroadcaster();

    // Guards against the mirror-image failure: a tenant clause so tight that
    // nobody can subscribe. That was the real state of this channel until the
    // `devices.view` / `device.view` typo was corrected.
    test()->withToken($token)
        ->postJson("http://{$habru->subdomain}.ethr.et/api/v1/broadcasting/auth", [
            'socket_id' => '1234.5678',
            'channel_name' => "private-device.{$ownDevice->public_id}",
        ])
        ->assertOk();
});

// ── A-2: a reserved slug must be refused at the point it is claimed ─────────

it('refuses to register a tenant on a reserved subdomain', function () {
    // Enforcement, not advice. The availability endpoint below is what the UI
    // consults, but registration is what actually creates the row — and it
    // previously validated only the character set and uniqueness, so `admin`
    // registered successfully and produced a tenant that could never resolve.
    test()->postJson('http://ethr.et/api/v1/auth/register', [
        'organization_name' => 'Squatter Ltd',
        'subdomain' => 'admin',
        'admin_name' => 'Squatter Admin',
        'admin_email' => 'squatter@example.test',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.subdomain.0', 'That subdomain is reserved. Please choose another.');

    expect(Tenant::withoutGlobalScopes()->where('subdomain', 'admin')->exists())->toBeFalse();
});

it('reports reserved subdomains as unavailable', function (string $reserved) {
    test()->getJson("http://ethr.et/api/v1/register/check-subdomain?subdomain={$reserved}")
        ->assertOk()
        ->assertJsonPath('available', false);
})->with(['admin', 'cdn', 'status', 'docs']);

// ── D4/D7: platform administration is confined to the platform host ─────────

it('serves platform admin routes where no tenant resolves', function () {
    config(['app.domain' => 'ethr.et']);
    tenantWithAdmin('habru');

    $superAdmin = createSuperAdmin();
    $token = $superAdmin->createToken('auth', ['*'])->plainTextToken;

    app(CurrentTenant::class)->forget();

    // `admin` is reserved, so admin.ethr.et resolves no tenant — which is
    // exactly the condition EnsurePlatformContext requires.
    test()->withToken($token)
        ->getJson('http://admin.ethr.et/api/v1/admin/tenants')
        ->assertOk();
});

it('hides platform admin routes from a tenant user on their own host', function () {
    config(['app.domain' => 'ethr.et']);
    [$habru, $habruAdmin] = tenantWithAdmin('habru');

    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;

    // nginx refuses the /admin console on tenant hosts, but /api/v1/admin is a
    // different prefix and stayed reachable. This caller legitimately belongs to
    // habru, so the tenant guard passes them through and EnsurePlatformContext
    // is what answers — 404 rather than 403, because on this host the platform
    // API is not part of the application at all, and 403 would confirm its shape
    // to a tenant user probing for it.
    //
    // (A super admin aimed at the same URL is refused earlier, by the tenant
    // guard, with 403 — see the test below.)
    test()->withToken($token)
        ->getJson("http://{$habru->subdomain}.ethr.et/api/v1/admin/tenants")
        ->assertNotFound()
        ->assertJsonPath('detail', 'Platform administration is not available on this host.');
});

it('refuses a tenant selector in the login body on the platform host', function () {
    config(['app.domain' => 'ethr.et']);
    [$habru] = tenantWithAdmin('habru');
    User::factory()->create([
        'tenant_id' => $habru->id,
        'role' => UserRole::TENANT_ADMIN,
        'password' => 'Str0ng-Passw0rd!',
    ])->update(['email' => 'staff@habru.test']);

    app(CurrentTenant::class)->forget();

    // `admin` being reserved stopped the *subdomain* naming a tenant, but
    // resolution then fell through to the form-field fallback the login
    // endpoints honour — so a Habru user could authenticate on the platform
    // hostname by putting their subdomain in the request body. Nothing leaked
    // (the cookie is host-only), but tenant authentication was reachable on the
    // platform host, which is the separation this split exists to make
    // structural. Now no selector resolves here at all, so the tenant-scoped
    // lookup finds nobody and the credentials are simply wrong.
    test()->postJson('http://admin.ethr.et/api/v1/auth/login', [
        'email' => 'staff@habru.test',
        'password' => 'Str0ng-Passw0rd!',
        'tenant' => 'habru',
    ])->assertStatus(422);

    expect(app(CurrentTenant::class)->resolved())->toBeFalse();
});

it('still lets the apex accept a tenant selector, which the mobile client needs', function () {
    config(['app.domain' => 'ethr.et']);
    [$habru] = tenantWithAdmin('habru');
    User::factory()->create([
        'tenant_id' => $habru->id,
        'role' => UserRole::TENANT_ADMIN,
        'password' => 'Str0ng-Passw0rd!',
    ])->update(['email' => 'staff@habru.test']);

    app(CurrentTenant::class)->forget();

    // The platform-host refusal above must not become "the body field is dead".
    // It is still the only way a client with no subdomain to sit on — the mobile
    // app, a shared marketing URL — can say which organisation it means.
    test()->postJson('http://ethr.et/api/v1/auth/login', [
        'email' => 'staff@habru.test',
        'password' => 'Str0ng-Passw0rd!',
        'tenant' => 'habru',
    ])->assertOk()->assertJsonStructure(['access_token']);
});

it('refuses a super admin browsing a tenant host once hostnames are authoritative', function () {
    config(['app.domain' => 'ethr.et']);
    [$habru] = tenantWithAdmin('habru');

    $superAdmin = createSuperAdmin();
    $token = $superAdmin->createToken('auth', ['*'])->plainTextToken;

    // The route to a tenant's data is impersonation — MFA-gated, time-boxed and
    // audited — not the platform account wandering onto the tenant's hostname.
    test()->withToken($token)
        ->getJson("http://{$habru->subdomain}.ethr.et/api/v1/employees")
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-mismatch');
});

// ── D-5: a super admin must be able to sign in before any tenant exists ─────

it('signs a super admin in on a fresh install with no tenants and no subdomain', function () {
    User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'password' => 'Str0ng-Passw0rd!',
    ])->update(['email' => 'platform@ethr.et']);

    expect(Tenant::withoutGlobalScopes()->count())->toBe(0);

    // No subdomain, no tenant field, nothing to resolve — the bootstrap case.
    // LoginRequest checks for a super admin before tenant resolution precisely
    // so this works; the login form used to block it client-side, leaving a new
    // installation with no way in.
    test()->postJson('http://ethr.et/api/v1/auth/login', [
        'email' => 'platform@ethr.et',
        'password' => 'Str0ng-Passw0rd!',
    ])->assertOk()->assertJsonStructure(['access_token']);
});

it('tells an ordinary user which field is missing when no tenant is given', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'habru']);
    app(CurrentTenant::class)->set($tenant);
    User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN,
        'password' => 'Str0ng-Passw0rd!',
    ])->update(['email' => 'staff@habru.test']);

    app(CurrentTenant::class)->forget();

    // The rule moved from the browser to the server, so the server has to say
    // something actionable — "The given data was invalid." would not do.
    test()->postJson('http://ethr.et/api/v1/auth/login', [
        'email' => 'staff@habru.test',
        'password' => 'Str0ng-Passw0rd!',
    ])
        ->assertStatus(422)
        ->assertJsonPath('errors.tenant.0', 'Organization is required. Provide your subdomain.');
});

// ── D-2: credentials never travel in a URL ──────────────────────────────────

it('exposes no login route that accepts credentials in the query string', function () {
    $getLogin = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'auth/login')
            && in_array('GET', $route->methods(), true));

    expect($getLogin)->toBeEmpty();
});
