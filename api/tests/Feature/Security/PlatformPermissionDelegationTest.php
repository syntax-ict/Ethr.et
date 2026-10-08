<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Http\Middleware\EnsurePlatformContext;
use App\Models\CustomRole;
use App\Models\Permission;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
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

    // Refused twice over: the middleware (N93) answers before the gate would.
    test()->getJson('http://ethr.test/api/v1/admin/tenants')->assertForbidden();
});

// The second wall on its own. Since N86 nobody but a super admin passes the
// ability gate, so an HTTP test cannot tell the wall from the gate; this calls
// the middleware with a handler that would answer 200 (audit N93).
test('the platform middleware admits a super admin and nobody else', function () {
    $tenant = createTenant();
    app(CurrentTenant::class)->forget();
    $middleware = app(EnsurePlatformContext::class);
    $next = fn () => response()->json(['ok' => true]);

    $asUser = function (User $user): Request {
        $request = Request::create('http://ethr.test/api/v1/admin/tenants');
        $request->setUserResolver(fn () => $user);

        return $request;
    };

    $superAdmin = createUser(['role' => UserRole::SUPER_ADMIN], $tenant);
    expect($middleware->handle($asUser($superAdmin), $next)->getStatusCode())->toBe(200);

    $tenantAdmin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    expect(fn () => $middleware->handle($asUser($tenantAdmin), $next))
        ->toThrow(AuthorizationException::class);
});
