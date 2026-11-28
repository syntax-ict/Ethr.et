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
        actingAsUser(['role' => UserRole::SUPERVISOR, 'custom_role_id' => $desk->id], $tenant);
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
        expect($row['custom_role'])->toBe(['public_id' => $role->public_id, 'name' => 'Viewer', 'is_active' => true]);

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

describe('the review of the first version', function () {
    it('offers the lowest built-in level nothing, because it has no one below it', function () {
        $tenant = createTenant();
        $desk = assignableRole($tenant->id, ['users.update', 'employee.view'], 'Team desk', 'team');
        actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $desk->id], $tenant);
        assignableRole($tenant->id, ['employee.view'], 'Same reach', 'team');

        $this->getJson('/api/v1/users/assignable-roles')->assertOk()->assertJsonCount(0, 'data');
    });

    it('does not let an hr admin remove the custom role of someone who holds more than they do', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $senior = assignableRole($tenant->id, ['employee.view', 'settings.manage'], 'Settings owner');
        $holder = createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $senior->id], $tenant);

        $this->patchJson("/api/v1/users/{$holder->public_id}", ['custom_role_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors('custom_role_id');
        expect($holder->fresh()->custom_role_id)->toBe($senior->id);
    });

    it('does not let an hr admin swap that role for a narrower one either', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $senior = assignableRole($tenant->id, ['employee.view', 'settings.manage'], 'Settings owner');
        $narrow = assignableRole($tenant->id, ['employee.view'], 'Viewer');
        $holder = createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $senior->id], $tenant);

        $this->patchJson("/api/v1/users/{$holder->public_id}", ['custom_role_id' => $narrow->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('custom_role_id');
        expect($holder->fresh()->custom_role_id)->toBe($senior->id);
    });

    it('lets an organisation admin, who holds all of it, remove that role', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $senior = assignableRole($tenant->id, ['employee.view', 'settings.manage'], 'Settings owner');
        $holder = createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $senior->id], $tenant);

        $this->patchJson("/api/v1/users/{$holder->public_id}", ['custom_role_id' => null])->assertOk();
        expect($holder->fresh()->custom_role_id)->toBeNull();
    });

    it('lets an hr admin remove a role that is switched off, which grants nothing', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $off = assignableRole($tenant->id, ['employee.view', 'settings.manage'], 'Old role', 'all', false);
        $holder = createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $off->id], $tenant);

        $this->patchJson("/api/v1/users/{$holder->public_id}", ['custom_role_id' => null])->assertOk();
        expect($holder->fresh()->custom_role_id)->toBeNull();
    });

    it('says on each row whether the custom role is switched off', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $off = assignableRole($tenant->id, ['employee.view'], 'Old role', 'all', false);
        $holder = createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $off->id], $tenant);

        $row = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))->firstWhere('public_id', $holder->public_id);
        expect($row['custom_role']['is_active'])->toBeFalse();
    });

    it('answers an array sent as the custom role filter instead of failing', function () {
        actingAsUser(['role' => UserRole::TENANT_ADMIN]);

        $this->getJson('/api/v1/users?filter[custom_role][]=x')->assertOk();
    });
});

describe('whoever can edit roles may give any of them', function () {
    it('offers an organisation admin, and lets them give, a role that carries an ability no built-in role holds', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $regional = assignableRole($tenant->id, ['employee.view', 'dashboard.regional'], 'Regional manager', 'branch');
        $target = createUser(['role' => UserRole::EMPLOYEE], $tenant);

        $ids = collect($this->getJson('/api/v1/users/assignable-roles')->assertOk()->json('data'))->pluck('public_id')->all();
        expect($ids)->toBe([$regional->public_id]);

        $this->patchJson("/api/v1/users/{$target->public_id}", ['custom_role_id' => $regional->public_id])->assertOk();
        expect($target->fresh()->custom_role_id)->toBe($regional->id);
    });

    it('still refuses an hr admin that role, which they do not hold', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $regional = assignableRole($tenant->id, ['employee.view', 'dashboard.regional'], 'Regional manager', 'branch');
        $target = createUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->getJson('/api/v1/users/assignable-roles')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/users/{$target->public_id}", ['custom_role_id' => $regional->public_id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['custom_role_id' => __('user.errors.role_above_your_level')]);
        expect($target->fresh()->custom_role_id)->toBeNull();
    });

    it('keeps the subset rule for an organisation admin whose own custom role has no settings.manage', function () {
        $tenant = createTenant();
        $narrow = assignableRole($tenant->id, ['users.update', 'employee.view'], 'Narrow admin', 'all');
        actingAsUser(['role' => UserRole::TENANT_ADMIN, 'custom_role_id' => $narrow->id], $tenant);
        assignableRole($tenant->id, ['employee.view'], 'Viewer', 'all');
        assignableRole($tenant->id, ['employee.view', 'payroll.approve'], 'Payroll approver', 'all');

        $names = collect($this->getJson('/api/v1/users/assignable-roles')->assertOk()->json('data'))->pluck('name')->all();
        expect($names)->toContain('Viewer')->and($names)->not->toContain('Payroll approver');
    });

    it('lets a custom role that holds settings.manage give a role with a wider reach than its own', function () {
        $tenant = createTenant();
        $manager = assignableRole($tenant->id, ['users.update', 'settings.manage', 'employee.view'], 'Role manager', 'team');
        actingAsUser(['role' => UserRole::SUPERVISOR, 'custom_role_id' => $manager->id], $tenant);
        $wide = assignableRole($tenant->id, ['employee.view', 'payroll.approve'], 'Wide approver', 'all');
        $target = createUser(['role' => UserRole::EMPLOYEE], $tenant);

        $ids = collect($this->getJson('/api/v1/users/assignable-roles')->assertOk()->json('data'))->pluck('public_id')->all();
        expect($ids)->toContain($wide->public_id);

        $this->patchJson("/api/v1/users/{$target->public_id}", ['custom_role_id' => $wide->public_id])->assertOk();
        expect($target->fresh()->custom_role_id)->toBe($wide->id);
    });

    it('still never lists or accepts another organisations role', function () {
        $foreign = assignableRole(createTenant()->id, ['employee.view', 'dashboard.regional'], 'Foreign', 'all');
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $target = createUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->getJson('/api/v1/users/assignable-roles')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson("/api/v1/users/{$target->public_id}", ['custom_role_id' => $foreign->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('custom_role_id');
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
