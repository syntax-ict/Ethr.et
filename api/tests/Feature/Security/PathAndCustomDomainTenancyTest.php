<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Support\FrontendUrl;

/**
 * Tenancy without wildcard subdomains (owner decision, 2026-10-06).
 *
 * The production host cannot serve `{tenant}.ethr.et` yet (M3), so three
 * selectors now identify an organisation, and each must be as strict as the
 * subdomain it stands in for:
 *
 *   - a CUSTOM DOMAIN the platform admin assigned (`hr.acme.com`), by host;
 *   - `ethr.et/{slug}`, the organisation's entry URL, which redirects to its
 *     sign-in page; after sign-in the slug travels as `X-Tenant`, which is
 *     honoured on the APEX only, and only for a member of that organisation
 *     (EnsureUserBelongsToTenant still decides);
 *   - the subdomain, unchanged, wherever the host can serve it.
 *
 * @see docs/decisions/OWNER-DECISION-TENANCY-WITHOUT-SUBDOMAINS.md
 */
function pathTenant(string $slug, array $attributes = []): array
{
    $tenant = Tenant::factory()->create(['subdomain' => $slug] + $attributes);
    app(CurrentTenant::class)->set($tenant);

    $admin = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => UserRole::TENANT_ADMIN,
    ]);
    Employee::factory()->create(['tenant_id' => $tenant->id]);

    app(CurrentTenant::class)->forget();

    return [$tenant, $admin];
}

function asProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
    config(['app.domain' => 'ethr.et']);
    app(CurrentTenant::class)->forget();
}

afterEach(function () {
    app()->detectEnvironment(fn () => 'testing');
});

// ── ethr.et/{slug}: X-Tenant on the apex ────────────────────────────────────

it('serves a member their own organisation on the apex when X-Tenant names it', function () {
    [, $admin] = pathTenant('acme');
    $token = $admin->createToken('auth', ['*'])->plainTextToken;
    asProduction();

    test()->withToken($token)
        ->withHeaders(['X-Tenant' => 'acme'])
        ->getJson('http://ethr.et/api/v1/employees')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuses a member who names another organisation on the apex', function () {
    [, $acmeAdmin] = pathTenant('acme');
    pathTenant('habru');
    $token = $acmeAdmin->createToken('auth', ['*'])->plainTextToken;
    asProduction();

    test()->withToken($token)
        ->withHeaders(['X-Tenant' => 'habru'])
        ->getJson('http://ethr.et/api/v1/employees')
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-mismatch');
});

it('honours X-Tenant on www, the apex alias Plesk redirects to', function () {
    [, $admin] = pathTenant('acme');
    $token = $admin->createToken('auth', ['*'])->plainTextToken;
    asProduction();

    test()->withToken($token)
        ->withHeaders(['X-Tenant' => 'acme'])
        ->getJson('http://www.ethr.et/api/v1/employees')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('ignores X-Tenant on a tenant subdomain, where the host already decides', function () {
    pathTenant('acme');
    [, $habruAdmin] = pathTenant('habru');
    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;
    asProduction();

    test()->withToken($token)
        ->withHeaders(['X-Tenant' => 'acme'])
        ->getJson('http://habru.ethr.et/api/v1/employees')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('ignores X-Tenant on a host that is neither the apex nor a known custom domain', function () {
    [, $admin] = pathTenant('acme');
    $token = $admin->createToken('auth', ['*'])->plainTextToken;
    asProduction();

    // Someone pointing their own DNS at the account must not get a tenant
    // selector for free: nothing resolves, and the scope fails closed.
    test()->withToken($token)
        ->withHeaders(['X-Tenant' => 'acme'])
        ->getJson('http://evil.example/api/v1/employees')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('never resolves a tenant on the platform host, header or not', function () {
    pathTenant('acme');
    asProduction();

    test()->withHeaders(['X-Tenant' => 'acme'])
        ->getJson('http://admin.ethr.et/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant', null);
});

it('names the organisation on the public sign-in context from the header on the apex', function () {
    pathTenant('acme', ['name' => 'Acme Ltd']);
    asProduction();

    test()->withHeaders(['X-Tenant' => 'acme'])
        ->getJson('http://ethr.et/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.name', 'Acme Ltd')
        ->assertJsonPath('tenant.subdomain', 'acme');
});

// ── Custom domains ──────────────────────────────────────────────────────────

it('resolves an organisation by the custom domain the platform assigned it', function () {
    [, $admin] = pathTenant('acme', ['name' => 'Acme Ltd', 'custom_domain' => 'hr.acme.com']);
    $token = $admin->createToken('auth', ['*'])->plainTextToken;
    asProduction();

    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.name', 'Acme Ltd');

    test()->withToken($token)
        ->getJson('http://hr.acme.com/api/v1/employees')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('lets the custom domain win over a header naming someone else', function () {
    pathTenant('acme', ['custom_domain' => 'hr.acme.com']);
    pathTenant('habru', ['name' => 'Habru Textiles']);
    asProduction();

    test()->withHeaders(['X-Tenant' => 'habru'])
        ->getJson('http://hr.acme.com/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.subdomain', 'acme');
});

it('refuses a member of another organisation on a custom domain', function () {
    pathTenant('acme', ['custom_domain' => 'hr.acme.com']);
    [, $habruAdmin] = pathTenant('habru');
    $token = $habruAdmin->createToken('auth', ['*'])->plainTextToken;
    asProduction();

    test()->withToken($token)
        ->getJson('http://hr.acme.com/api/v1/employees')
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-mismatch');
});

it('treats a custom domain as a first-party origin for the session cookie', function () {
    pathTenant('acme', ['custom_domain' => 'hr.acme.com']);
    asProduction();

    test()->getJson('http://hr.acme.com/api/v1/auth/tenant-context')->assertOk();

    expect(config('sanctum.stateful'))->toContain('hr.acme.com');
});

it('matches a custom domain case-insensitively and ignores the port', function () {
    pathTenant('acme', ['name' => 'Acme Ltd', 'custom_domain' => 'hr.acme.com']);
    asProduction();

    test()->getJson('http://HR.Acme.com:8443/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.name', 'Acme Ltd');
});

// ── ethr.et/{slug}: the entry URL ───────────────────────────────────────────

it('sends ethr.et/{slug} to that organisation\'s sign-in page', function () {
    pathTenant('acme');
    asProduction();

    test()->get('http://ethr.et/acme')
        ->assertRedirect('/login?org=acme');
});

it('answers a real 404 for a slug no organisation holds', function () {
    asProduction();

    test()->get('http://ethr.et/no-such-org')->assertNotFound();
});

it('starts no session on the entry URL, which every unknown path now reaches', function () {
    pathTenant('acme');
    asProduction();

    // .htaccess hands Laravel any lower-case segment with no file behind it, so
    // scanners hit this route constantly. With SESSION_DRIVER=database each hit
    // would write a sessions row against the hosting quota.
    test()->get('http://ethr.et/acme')->assertRedirect()->assertCookieMissing(config('session.cookie'));
    test()->get('http://ethr.et/wp-admin')->assertNotFound()->assertCookieMissing(config('session.cookie'));
});

it('never routes a reserved name as an organisation entry', function () {
    asProduction();

    test()->get('http://ethr.et/dashboard')->assertNotFound();
});

// ── Mail links ──────────────────────────────────────────────────────────────

it('links an organisation to ethr.et/{slug} when subdomains are not served', function () {
    pathTenant('acme');
    config(['app.domain' => 'ethr.et', 'app.frontend_url' => 'https://ethr.et', 'tenancy.subdomains' => false]);

    expect(FrontendUrl::forTenant('acme', '/login'))->toBe('https://ethr.et/acme')
        ->and(FrontendUrl::forTenant('acme', '/login/reset?token=t&tenant=acme'))
        ->toBe('https://ethr.et/login/reset?token=t&tenant=acme');
});

it('links an organisation to its own subdomain once subdomains are served', function () {
    pathTenant('acme');
    config(['app.domain' => 'ethr.et', 'app.frontend_url' => 'https://ethr.et', 'tenancy.subdomains' => true]);

    expect(FrontendUrl::forTenant('acme', '/login'))->toBe('https://acme.ethr.et/login');
});

it('links an organisation to its custom domain above everything else', function () {
    pathTenant('acme', ['custom_domain' => 'hr.acme.com']);
    config(['app.domain' => 'ethr.et', 'app.frontend_url' => 'https://ethr.et', 'tenancy.subdomains' => true]);

    expect(FrontendUrl::forTenant('acme', '/login'))->toBe('https://hr.acme.com/login');
});

// ── Reserved names ──────────────────────────────────────────────────────────

it('reserves every top-level frontend route so no organisation can shadow one', function () {
    // ethr.et/{slug} shares one namespace with the frontend's own pages. A
    // tenant called "dashboard" would be unreachable, and worse, would make the
    // dashboard unreachable. Read the routes from the frontend source itself so
    // a new top-level page cannot be added without reserving its name.
    $appDir = dirname(base_path()).'/src/src/app';
    expect(is_dir($appDir))->toBeTrue("frontend app directory not found at {$appDir}");

    $routes = [];
    foreach (scandir($appDir) as $entry) {
        if ($entry === '.' || $entry === '..' || ! is_dir("{$appDir}/{$entry}")) {
            continue;
        }
        $children = preg_match('/^\(.+\)$/', $entry) === 1 ? scandir("{$appDir}/{$entry}") : [$entry];
        $base = preg_match('/^\(.+\)$/', $entry) === 1 ? "{$appDir}/{$entry}" : $appDir;
        foreach ($children as $child) {
            if ($child === '.' || $child === '..' || ! is_dir("{$base}/{$child}")) {
                continue;
            }
            if (str_starts_with($child, '[') || str_starts_with($child, '_') || str_starts_with($child, '(')) {
                continue;
            }
            $routes[] = $child;
        }
    }

    expect($routes)->not->toBeEmpty();
    // The marketing site's locale segment.
    $routes = array_merge($routes, ['en', 'am']);

    $missing = array_values(array_diff(array_unique($routes), Tenant::RESERVED_SUBDOMAINS));
    expect($missing)->toBe([], 'top-level routes an organisation could claim: '.implode(', ', $missing));
});
