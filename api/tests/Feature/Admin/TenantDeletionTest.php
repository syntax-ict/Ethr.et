<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Jobs\HandleOverdueInvoicesJob;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Admin\PlatformAnalyticsService;
use PragmaRX\Google2FA\Google2FA;

/**
 * Deleting an organisation from the platform console (2026-10-19).
 *
 * Delete is a reversible closure: the tenant row is soft-deleted and nothing
 * else is removed, because payroll and attendance are NeverDelete and the audit
 * log is append-only. It is the second of two steps (cancel, then delete), and
 * asks for the address typed out, a current authenticator code and a reason.
 */
function deletionConsole(): array
{
    $secret = 'JBSWY3DPEHPK3PXP';
    $home = createTenant();
    $admin = createUser([
        'role' => UserRole::SUPER_ADMIN,
        'mfa_enabled' => true,
        'mfa_secret' => $secret,
    ], $home);

    return [
        "http://{$home->subdomain}.ethr.test/api/v1/admin/tenants",
        $admin->createToken('auth', ['*'])->plainTextToken,
        (new Google2FA)->getCurrentOtp($secret),
        $admin,
    ];
}

function cancelledOrganisation(): Tenant
{
    return Tenant::factory()->create(['status' => TenantStatus::CANCELLED]);
}

test('a cancelled organisation is deleted, its records kept and its sessions ended', function () {
    [$base, $token, $code, $admin] = deletionConsole();
    $target = cancelledOrganisation();
    $person = User::factory()->create(['tenant_id' => $target->id, 'role' => UserRole::TENANT_ADMIN]);
    $person->createToken('auth');
    $employee = Employee::factory()->create(['tenant_id' => $target->id]);

    test()->withToken($token)
        ->postJson("{$base}/{$target->public_id}/delete", [
            'confirm' => $target->subdomain,
            'code' => $code,
            'reason' => 'Duplicate registration',
        ])
        ->assertOk()
        ->assertJsonPath('public_id', $target->public_id);

    expect(Tenant::withTrashed()->find($target->id)->trashed())->toBeTrue()
        // Nothing but the tenant row changes: the organisation's own records stay.
        ->and(Employee::withoutGlobalScopes()->whereKey($employee->id)->exists())->toBeTrue()
        ->and(DB::table('personal_access_tokens')->where('tokenable_id', $person->id)->count())->toBe(0)
        // The console's own session is not one of them.
        ->and($admin->tokens()->count())->toBe(1);

    $audit = AuditLog::withoutGlobalScopes()->where('action', 'admin.tenant.deleted')->sole();
    expect($audit->payload['reason'])->toBe('Duplicate registration')
        ->and($audit->payload['subdomain'])->toBe($target->subdomain)
        ->and($audit->payload['sessions_ended'])->toBe(1)
        ->and($audit->payload['deleted_by'])->toBe($admin->public_id);
});

test('an organisation that is not cancelled cannot be deleted', function (TenantStatus $status) {
    [$base, $token, $code] = deletionConsole();
    $target = Tenant::factory()->create(['status' => $status]);

    test()->withToken($token)
        ->postJson("{$base}/{$target->public_id}/delete", [
            'confirm' => $target->subdomain,
            'code' => $code,
            'reason' => 'Closing it',
        ])
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://ethr.et/errors/tenant-not-cancelled');

    expect(Tenant::query()->whereKey($target->id)->exists())->toBeTrue();
})->with([
    'trial' => [TenantStatus::TRIAL],
    'active' => [TenantStatus::ACTIVE],
    'suspended' => [TenantStatus::SUSPENDED],
]);

test('the address must be typed exactly', function () {
    [$base, $token, $code] = deletionConsole();
    $target = cancelledOrganisation();

    test()->withToken($token)
        ->postJson("{$base}/{$target->public_id}/delete", [
            'confirm' => strtoupper($target->subdomain).'x',
            'code' => $code,
            'reason' => 'Closing it',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['confirm']);

    expect(Tenant::query()->whereKey($target->id)->exists())->toBeTrue();
});

test('a wrong authenticator code deletes nothing and is recorded', function () {
    [$base, $token] = deletionConsole();
    $target = cancelledOrganisation();

    test()->withToken($token)
        ->postJson("{$base}/{$target->public_id}/delete", [
            'confirm' => $target->subdomain,
            'code' => '000000',
            'reason' => 'Closing it',
        ])
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://ethr.et/errors/invalid-mfa-code');

    expect(Tenant::query()->whereKey($target->id)->exists())->toBeTrue()
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'admin.tenant.delete_mfa_failed')->exists())->toBeTrue();
});

test('a reason is required', function () {
    [$base, $token, $code] = deletionConsole();
    $target = cancelledOrganisation();

    test()->withToken($token)
        ->postJson("{$base}/{$target->public_id}/delete", [
            'confirm' => $target->subdomain,
            'code' => $code,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

test('only the platform can delete an organisation', function () {
    $tenant = createTenant(['status' => TenantStatus::CANCELLED]);
    $tenantAdmin = createUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

    test()->actingAs($tenantAdmin)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/admin/tenants/{$tenant->public_id}/delete", [
            'confirm' => $tenant->subdomain,
            'code' => '123456',
            'reason' => 'Closing it',
        ])
        ->assertForbidden();

    expect(Tenant::query()->whereKey($tenant->id)->exists())->toBeTrue();
});

test('a deleted organisation stops resolving at once, cached or not', function () {
    [$base, $token, $code] = deletionConsole();
    $target = cancelledOrganisation();

    // Warm ResolveTenant's five-minute cache for the organisation's host.
    test()->getJson("http://{$target->subdomain}.ethr.test/api/v1/auth/me");
    expect(Cache::has("tenant:{$target->subdomain}"))->toBeTrue();

    test()->withToken($token)
        ->postJson("{$base}/{$target->public_id}/delete", [
            'confirm' => $target->subdomain,
            'code' => $code,
            'reason' => 'Closing it',
        ])
        ->assertOk();

    expect(Cache::has("tenant:{$target->subdomain}"))->toBeFalse();
    test()->getJson("http://{$target->subdomain}.ethr.test/api/v1/auth/me")
        ->assertNotFound();
});

test('the console lists a deleted organisation only when asked, and can restore it', function () {
    [$base, $token, $code] = deletionConsole();
    $target = cancelledOrganisation();

    test()->withToken($token)
        ->postJson("{$base}/{$target->public_id}/delete", [
            'confirm' => $target->subdomain,
            'code' => $code,
            'reason' => 'Closing it',
        ])
        ->assertOk();

    $listed = fn (string $query = '') => collect(
        test()->withToken($token)->getJson("{$base}{$query}")->assertOk()->json('data')
    )->pluck('public_id');

    expect($listed())->not->toContain($target->public_id)
        ->and($listed('?filter[status]=deleted'))->toContain($target->public_id)
        ->and($listed('?filter[status]=deleted'))->toHaveCount(1);

    test()->withToken($token)->getJson("{$base}/{$target->public_id}")
        ->assertOk()
        ->assertJsonPath('deleted_at', fn ($at) => $at !== null);

    // Nothing else acts on a deleted organisation.
    test()->withToken($token)
        ->putJson("{$base}/{$target->public_id}/status", ['status' => 'active'])
        ->assertNotFound();

    test()->withToken($token)
        ->postJson("{$base}/{$target->public_id}/restore")
        ->assertOk()
        ->assertJsonPath('status', 'cancelled');

    expect(Tenant::query()->whereKey($target->id)->exists())->toBeTrue()
        ->and($listed())->toContain($target->public_id)
        ->and(AuditLog::withoutGlobalScopes()->where('action', 'admin.tenant.restored')->exists())->toBeTrue();

    // Restoring what is not deleted finds nothing.
    test()->withToken($token)
        ->postJson("{$base}/{$target->public_id}/restore")
        ->assertNotFound();
});

test('a deleted organisation is not counted as a customer', function () {
    $target = cancelledOrganisation();
    $before = app(PlatformAnalyticsService::class)->revenue();

    $target->delete();

    $after = app(PlatformAnalyticsService::class)->revenue();
    expect($after['total_tenants'])->toBe($before['total_tenants'] - 1);
});

test("a deleted organisation's device can no longer record attendance", function () {
    $deleted = cancelledOrganisation();
    $branch = Branch::factory()->create(['tenant_id' => $deleted->id]);
    Device::factory()->zkteco()->create([
        'tenant_id' => $deleted->id,
        'branch_id' => $branch->id,
        'serial_number' => 'ZK-GONE',
        'webhook_token' => 'gone-token',
    ]);
    Employee::factory()->create(['tenant_id' => $deleted->id, 'employee_code' => 'EMP-GONE']);
    $deleted->delete();

    // Sent through an organisation that is still in business.
    $live = createTenant();

    test()->withHeader('X-Webhook-Token', 'gone-token')
        ->postJson("http://{$live->subdomain}.ethr.test/api/v1/devices/webhook/zkteco", [
            'sn' => 'ZK-GONE',
            'records' => [['pin' => 'EMP-GONE', 'timestamp' => now()->toIso8601String(), 'punch' => 0]],
        ])
        ->assertStatus(401);

    $this->assertDatabaseMissing('attendance_records', ['tenant_id' => $deleted->id]);
});

test('an unpaid invoice does not turn a cancelled organisation back into a suspended one', function () {
    $tenant = cancelledOrganisation();
    Invoice::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'past_due',
        'due_date' => now()->subDays(90),
    ]);

    (new HandleOverdueInvoicesJob)->handle();

    expect($tenant->fresh()->status)->toBe(TenantStatus::CANCELLED);
});
