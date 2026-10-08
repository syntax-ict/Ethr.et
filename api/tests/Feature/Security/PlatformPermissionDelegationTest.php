<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Permission;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\DB;

/*
 * `admin.manage` is the platform console's ability. It sits in the permissions
 * catalogue, which custom roles draw from, and `Gate::before` resolves every
 * catalogue ability through the user's role, custom role included. A tenant
 * admin could therefore create a role holding it, give it to a user, and call
 * /api/v1/admin/* on the apex host with no tenant header: no tenant resolved,
 * so the tenant-membership and platform-context middleware both stood aside,
 * and the gate said yes. Every tenant's data, from one tenant's settings page
 * (audit N86).
 */

function platformAbilityRole(int $tenantId): CustomRole
{
    $role = CustomRole::query()->forceCreate([
        'tenant_id' => $tenantId,
        'name' => 'Escalated',
        'is_active' => true,
    ]);
    DB::table('custom_role_permissions')->insert([
        'custom_role_id' => $role->id,
        'permission_id' => Permission::query()->where('name', 'admin.manage')->value('id'),
    ]);
    Permission::clearCache();

    return $role;
}

test('a custom role cannot be given a platform permission', function () {
    $tenant = createTenant(['subdomain' => 'test']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->postJson('http://test.ethr.test/api/v1/roles', [
        'name' => 'Escalated',
        'permissions' => ['employee.view', 'admin.manage'],
    ])->assertUnprocessable()->assertJsonValidationErrors('permissions.1');
});

test('the permission catalogue offered to tenants leaves platform permissions out', function () {
    $tenant = createTenant(['subdomain' => 'test']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $names = collect(test()->getJson('http://test.ethr.test/api/v1/permissions')->assertOk()->json())
        ->flatten(2)
        ->filter(fn ($v) => is_string($v) && str_contains($v, '.'))
        ->values()
        ->all();

    expect($names)->not->toContain('admin.manage')
        ->and($names)->toContain('employee.view');
});

test('a custom role already holding a platform permission does not open the platform API', function () {
    $tenant = createTenant();
    $user = createUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);
    $user->forceFill(['custom_role_id' => platformAbilityRole($tenant->id)->id])->save();

    expect($user->fresh()->hasPermission('admin.manage'))->toBeFalse();

    test()->actingAs($user->fresh());
    // The apex host with no tenant header: nothing resolves a tenant.
    app(CurrentTenant::class)->forget();

    test()->getJson('http://ethr.test/api/v1/admin/tenants')->assertForbidden();
});
