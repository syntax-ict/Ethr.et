<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;

/*
 * Settings > Roles lets an admin create custom roles, and the Users page gives
 * them to people. The picker reads GET /users/assignable-roles because an HR
 * admin, who may assign, cannot read GET /roles (`settings.manage`).
 */

function assignableRole(int $tenantId, array $permissions, string $name = 'Role', string $scope = 'all', bool $active = true): CustomRole
{
    $role = CustomRole::query()->forceCreate([
        'tenant_id' => $tenantId,
        'name' => $name,
        'is_active' => $active,
        'org_scope' => $scope,
    ]);
    $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
    Permission::clearCache();

    return $role;
}

describe('GET /users/assignable-roles', function () {
    it('lists only active roles the actor may give, with the fields the picker needs', function () {
        $hr = actingAsUser(['role' => UserRole::HR_ADMIN]);
        $ok = assignableRole($hr->tenant_id, ['employee.view', 'leave.approve'], 'Leave desk');
        assignableRole($hr->tenant_id, ['employee.view'], 'Switched off', 'all', false);
        assignableRole($hr->tenant_id, ['employee.view', 'settings.manage'], 'Settings owner');

        $response = $this->getJson('/api/v1/users/assignable-roles')->assertOk();

        expect($response->json('data'))->toHaveCount(1);
        $response->assertJsonPath('data.0.public_id', $ok->public_id)
            ->assertJsonPath('data.0.name', 'Leave desk')
            ->assertJsonPath('data.0.org_scope', 'all')
            ->assertJsonPath('data.0.permissions_count', 2)
            ->assertJsonStructure(['data' => [['public_id', 'name', 'description', 'org_scope', 'permissions_count']]]);
    });

    it('leaves out a role that reaches wider than the actor', function () {
        $tenant = createTenant();
        $desk = assignableRole($tenant->id, ['users.update', 'employee.view'], 'Team desk', 'team');
        actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $desk->id], $tenant);
        assignableRole($tenant->id, ['employee.view'], 'Everyone', 'all');
        $team = assignableRole($tenant->id, ['employee.view'], 'Same reach', 'team');

        $ids = collect($this->getJson('/api/v1/users/assignable-roles')->assertOk()->json('data'))->pluck('public_id')->all();
        expect($ids)->toContain($team->public_id)->and($ids)->toHaveCount(2);
    });

    it('offers a tenant admin the role an hr admin is not offered', function () {
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN]);
        assignableRole($admin->tenant_id, ['employee.view', 'settings.manage'], 'Settings owner');

        $names = collect($this->getJson('/api/v1/users/assignable-roles')->assertOk()->json('data'))->pluck('name')->all();
        expect($names)->toBe(['Settings owner']);
    });

    it('never lists another organisations roles', function () {
        $foreign = assignableRole(createTenant()->id, ['employee.view'], 'Foreign');
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN]);
        $own = assignableRole($admin->tenant_id, ['employee.view'], 'Own');

        $ids = collect($this->getJson('/api/v1/users/assignable-roles')->assertOk()->json('data'))->pluck('public_id')->all();
        expect($ids)->toBe([$own->public_id])->not->toContain($foreign->public_id);
    });

    it('is refused without users.update', function () {
        actingAsUser(['role' => UserRole::EMPLOYEE]);

        $this->getJson('/api/v1/users/assignable-roles')->assertForbidden();
    });
});

describe('the custom role on the users list', function () {
    it('returns custom_role on index and filters by it', function () {
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN]);
        $tenant = Tenant::find($admin->tenant_id);
        $role = assignableRole($admin->tenant_id, ['employee.view'], 'Viewer');
        $holder = createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant);
        createUser(['role' => UserRole::EMPLOYEE], $tenant);

        $all = $this->getJson('/api/v1/users')->assertOk()->json('data');
        $row = collect($all)->firstWhere('public_id', $holder->public_id);
        expect($row['custom_role'])->toBe(['public_id' => $role->public_id, 'name' => 'Viewer']);

        $filtered = $this->getJson('/api/v1/users?filter[custom_role]='.$role->public_id)->assertOk()->json('data');
        expect(collect($filtered)->pluck('public_id')->all())->toBe([$holder->public_id]);
    });

    it('finds no one for another organisations role', function () {
        $foreignTenant = createTenant();
        $foreign = assignableRole($foreignTenant->id, ['employee.view'], 'Foreign');
        createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $foreign->id], $foreignTenant);
        actingAsUser(['role' => UserRole::TENANT_ADMIN]);

        $this->getJson('/api/v1/users?filter[custom_role]='.$foreign->public_id)->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('returns custom_role on update', function () {
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN]);
        $target = createUser(['role' => UserRole::EMPLOYEE], Tenant::find($admin->tenant_id));
        $role = assignableRole($admin->tenant_id, ['employee.view'], 'Viewer');

        $this->patchJson("/api/v1/users/{$target->public_id}", ['custom_role_id' => $role->public_id])
            ->assertOk()
            ->assertJsonPath('custom_role.public_id', $role->public_id);
    });
});

describe('your own custom role', function () {
    it('is not yours to change unless you are the platform', function () {
        $tenant = createTenant();
        $second = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $role = assignableRole($tenant->id, ['employee.view'], 'Narrow');

        $this->patchJson("/api/v1/users/{$second->public_id}", ['custom_role_id' => $role->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('custom_role_id');
        expect($second->fresh()->custom_role_id)->toBeNull();
    });

    it('can be changed by another administrator', function () {
        $tenant = createTenant();
        $first = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $role = assignableRole($tenant->id, ['employee.view'], 'Narrow');

        $this->patchJson("/api/v1/users/{$first->public_id}", ['custom_role_id' => $role->public_id])->assertOk();
        expect($first->fresh()->custom_role_id)->toBe($role->id);
    });

    it('lets an unrelated edit of your own account through', function () {
        $tenant = createTenant();
        $me = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $role = assignableRole($tenant->id, ['employee.view', 'users.update'], 'Narrow');
        User::whereKey($me->id)->update(['custom_role_id' => $role->id]);

        $this->patchJson("/api/v1/users/{$me->public_id}", ['custom_role_id' => $role->public_id, 'locale' => 'en'])->assertOk();
    });
});
