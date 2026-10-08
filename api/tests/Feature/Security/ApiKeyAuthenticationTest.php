<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Testing\TestResponse;

/**
 * Console-minted API keys on the tenant API.
 *
 * Until 2026-10-09 the keys Settings → API keys issued were accepted by
 * nothing: ScimAuth was the only reader of ApiKey and wants an ability the
 * console cannot grant, so every key answered 401 on every tenant endpoint
 * (QA sweep 2026-10-08, finding 3). A key now acts as its creator, bound to
 * the creator's tenant, and may call only what its abilities cover.
 *
 * Every request here is a bare bearer, no cookie: a browser `fetch` sends the
 * session cookie alongside the key and the session answers instead, which is
 * how the first reading of this gap went wrong.
 */
function keyTenant(string $slug = 'acme'): array
{
    $tenant = Tenant::factory()->create(['subdomain' => $slug]);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);
    Employee::factory()->create(['tenant_id' => $tenant->id]);
    app(CurrentTenant::class)->forget();

    return [$tenant, $admin];
}

/** @param  list<string>  $abilities */
function consoleKey(Tenant $tenant, User $creator, array $abilities, array $overrides = []): string
{
    $plain = 'ethr_'.bin2hex(random_bytes(20));
    ApiKey::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $creator->id,
        'key_hash' => hash('sha256', $plain),
        'key_prefix' => substr($plain, 0, 12),
        'abilities' => $abilities,
    ] + $overrides);
    app(CurrentTenant::class)->forget();

    return $plain;
}

function withKey(string $plain, array $headers = []): TestCase
{
    return test()->withHeaders(['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'] + $headers);
}

function assertAbilityRefused(TestResponse $response): void
{
    $response->assertForbidden()->assertJsonPath('type', 'https://ethr.et/errors/api-key-ability');
}

// ── Who is calling ──────────────────────────────────────────────────────────

it('authenticates a console key as its creator, on its own tenant, with no host or header naming one', function () {
    [$tenant, $admin] = keyTenant();
    $key = consoleKey($tenant, $admin, ['read']);

    withKey($key)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.public_id', $admin->public_id)
        ->assertJsonPath('tenant.subdomain', 'acme');

    withKey($key)->getJson('/api/v1/employees')->assertOk()->assertJsonCount(1, 'data');
});

it('refuses a key it does not know, and says nothing more', function () {
    keyTenant();

    withKey('ethr_'.str_repeat('0', 40))->getJson('/api/v1/employees')
        ->assertUnauthorized()
        ->assertJsonPath('type', 'https://ethr.et/errors/unauthenticated');
});

it('refuses an expired key and a revoked key alike', function (array $state) {
    [$tenant, $admin] = keyTenant();
    $key = consoleKey($tenant, $admin, ['read'], $state);

    withKey($key)->getJson('/api/v1/employees')->assertUnauthorized();
})->with([
    'expired' => [['expires_at' => now()->subMinute()]],
    'revoked' => [['revoked_at' => now()]],
]);

it('refuses a key whose creator is no longer active, or whose tenant is suspended', function (string $case) {
    [$tenant, $admin] = keyTenant();
    $key = consoleKey($tenant, $admin, ['read']);

    if ($case === 'creator inactive') {
        $admin->update(['status' => 'suspended']);
    } else {
        $tenant->update(['status' => TenantStatus::SUSPENDED]);
    }

    withKey($key)->getJson('/api/v1/employees')->assertUnauthorized();
})->with(['creator inactive', 'tenant suspended']);

it('never lets a key reach another organisation, however the request names it', function () {
    [$acme, $acmeAdmin] = keyTenant('acme');
    keyTenant('habru');
    $key = consoleKey($acme, $acmeAdmin, ['read', 'employees']);

    withKey($key, ['X-Tenant' => 'habru'])->getJson('/api/v1/employees')
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-mismatch');
});

it('records when the key was last used', function () {
    [$tenant, $admin] = keyTenant();
    $key = consoleKey($tenant, $admin, ['read']);

    withKey($key)->getJson('/api/v1/employees')->assertOk();

    expect(ApiKey::withoutGlobalScopes()->where('key_hash', hash('sha256', $key))->value('last_used_at'))->not->toBeNull();
});

// ── What it may call ────────────────────────────────────────────────────────

it('lets a module key into its module and nowhere else', function () {
    [$tenant, $admin] = keyTenant();
    $key = consoleKey($tenant, $admin, ['employees']);

    withKey($key)->getJson('/api/v1/employees')->assertOk();
    assertAbilityRefused(withKey($key)->getJson('/api/v1/payroll/runs'));
    assertAbilityRefused(withKey($key)->getJson('/api/v1/leave/my'));
    assertAbilityRefused(withKey($key)->getJson('/api/v1/organization/departments'));
});

it('lets a read key read anything on the tenant API, and write nothing', function () {
    [$tenant, $admin] = keyTenant();
    $key = consoleKey($tenant, $admin, ['read']);

    withKey($key)->getJson('/api/v1/employees')->assertOk();
    withKey($key)->getJson('/api/v1/payroll/runs')->assertOk();
    withKey($key)->getJson('/api/v1/organization/departments')->assertOk();
    assertAbilityRefused(withKey($key)->postJson('/api/v1/organization/departments', ['name' => 'QA']));
});

it('lets a write key write, and a module key write within its module', function () {
    [$tenant, $admin] = keyTenant();
    $writeKey = consoleKey($tenant, $admin, ['write']);
    $employeesKey = consoleKey($tenant, $admin, ['employees']);

    withKey($writeKey)->postJson('/api/v1/organization/departments', ['name' => 'QA Dept'])->assertCreated();

    withKey($employeesKey)->postJson('/api/v1/employees', [
        'name' => 'Via Key',
        'email' => 'viakey@acme.test',
        'employee_code' => 'EMP-KEY-1',
        'hire_date' => '2026-01-05',
        'salary_cents' => 500000,
    ])->assertCreated();

    assertAbilityRefused(withKey($employeesKey)->postJson('/api/v1/organization/departments', ['name' => 'Nope']));
});

it('names the abilities that would have allowed the call', function () {
    [$tenant, $admin] = keyTenant();
    $key = consoleKey($tenant, $admin, ['attendance']);

    withKey($key)->postJson('/api/v1/employees', [])
        ->assertForbidden()
        ->assertJsonPath('detail', 'This API key needs one of these abilities: write, employees.');
});

it('keeps a SCIM-only key out of the tenant API', function () {
    [$tenant, $admin] = keyTenant();
    $key = consoleKey($tenant, $admin, ['scim']);

    assertAbilityRefused(withKey($key)->getJson('/api/v1/employees'));
});

it('narrows a key, and never widens its creator: the creator\'s own permissions still decide', function () {
    [$tenant] = keyTenant();
    app(CurrentTenant::class)->set($tenant);
    $employee = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE]);
    app(CurrentTenant::class)->forget();
    $key = consoleKey($tenant, $employee, ['write', 'employees']);

    // An employee may not create employees, key or no key.
    withKey($key)->postJson('/api/v1/employees', ['name' => 'X', 'hire_date' => '2026-01-05'])->assertForbidden();
});

it('leaves a session untouched: no key, no ability check', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson('/api/v1/payroll/runs')->assertOk();
    test()->postJson('/api/v1/organization/departments', ['name' => 'Session'])->assertCreated();
});
