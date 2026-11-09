<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\DB;

/**
 * Review of 2026-11-09. On the apex, a signed-in member who sends no
 * X-Tenant resolves no tenant, and EnsureUserBelongsToTenant let the request
 * through. Reads came back empty, but a write created rows with tenant_id
 * NULL, and on tax_brackets and pension_rules NULL is the platform's own
 * ladder: any self-registered organisation's admin rewrote the tax and
 * pension every organisation falls back to.
 */
function noTenantAdmin(): string
{
    $tenant = Tenant::factory()->create(['subdomain' => 'acme']);
    app(CurrentTenant::class)->set($tenant);
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);
    app(CurrentTenant::class)->forget();

    app()->detectEnvironment(fn () => 'production');
    config(['app.domain' => 'ethr.et']);

    return $admin->createToken('auth', ['*'])->plainTextToken;
}

afterEach(fn () => app()->detectEnvironment(fn () => 'testing'));

test('a member cannot write the platform tax ladder by leaving out the organisation', function () {
    $token = noTenantAdmin();
    $platformRows = DB::table('tax_brackets')->whereNull('tenant_id')->count();

    test()->withToken($token)
        ->putJson('http://ethr.et/api/v1/payroll/tax-brackets', [
            'effective_from' => '2026-11-01',
            'brackets' => [['min_amount_cents' => 0, 'max_amount_cents' => null, 'rate' => 100, 'deduction_cents' => 0]],
        ])
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-required');

    expect(DB::table('tax_brackets')->whereNull('tenant_id')->count())->toBe($platformRows);
});

test('a member cannot add a platform pension rule by leaving out the organisation', function () {
    $token = noTenantAdmin();
    $platformRows = DB::table('pension_rules')->whereNull('tenant_id')->count();

    test()->withToken($token)
        ->postJson('http://ethr.et/api/v1/payroll/pension-rules', [
            'effective_from' => '2026-11-01',
            'employee_rate' => 50,
            'employer_rate' => 50,
        ])
        ->assertForbidden();

    expect(DB::table('pension_rules')->whereNull('tenant_id')->count())->toBe($platformRows);
});

test('with no organisation a member still reads (empty) and can still sign out', function () {
    $token = noTenantAdmin();

    test()->withToken($token)->getJson('http://ethr.et/api/v1/employees')->assertOk()->assertJsonCount(0, 'data');
    expect(test()->withToken($token)->postJson('http://ethr.et/api/v1/auth/logout')->status())->not->toBe(403);
});
