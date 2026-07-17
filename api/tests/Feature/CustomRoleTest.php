<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Permission;

function roleUrl(string $path = '', ?string $subdomain = null): string
{
    $subdomain ??= 'test';
    $base = "http://{$subdomain}.ethr.test/api/v1/roles";

    return $path ? "{$base}/{$path}" : $base;
}

function permissionsUrl(?string $subdomain = null): string
{
    $subdomain ??= 'test';

    return "http://{$subdomain}.ethr.test/api/v1/permissions";
}

// ──────────────────────── Permissions list endpoint ────────────────────────

describe('GET /permissions', function () {
    it('returns all permissions grouped by module', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $response = test()->getJson(permissionsUrl());

        $response->assertOk();
        $body = $response->json();
        expect($body)->toBeArray()
            ->and(count($body))->toBeGreaterThan(5);
    });

    it('denies access to non-admin users', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        test()->getJson(permissionsUrl())->assertForbidden();
    });
});

// ──────────────────────── CRUD ────────────────────────

describe('custom role CRUD', function () {
    it('creates a custom role with permissions', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $response = test()->postJson(roleUrl(), [
            'name' => 'Junior HR',
            'description' => 'Limited HR access',
            'permissions' => ['employee.viewAny', 'employee.view', 'attendance.viewAll'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Junior HR')
            ->assertJsonPath('description', 'Limited HR access')
            ->assertJsonPath('is_active', true);

        expect($response->json('permissions'))->toHaveCount(3)
            ->toContain('employee.viewAny')
            ->toContain('employee.view')
            ->toContain('attendance.viewAll');

        expect(CustomRole::where('tenant_id', $tenant->id)->count())->toBe(1);
    });

    it('lists custom roles with user counts', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $role = CustomRole::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Role',
        ]);
        $role->permissions()->sync(
            Permission::whereIn('name', ['employee.viewAny'])->pluck('id')
        );

        createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant);

        $response = test()->getJson(roleUrl());

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Test Role')
            ->assertJsonPath('data.0.users_count', 1);
    });

    it('shows a single custom role', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $role = CustomRole::create([
            'tenant_id' => $tenant->id,
            'name' => 'Viewer',
            'description' => 'Read-only access',
        ]);
        $role->permissions()->sync(
            Permission::whereIn('name', ['employee.viewAny', 'employee.view'])->pluck('id')
        );

        $response = test()->getJson(roleUrl($role->public_id));

        $response->assertOk()
            ->assertJsonPath('name', 'Viewer')
            ->assertJsonPath('description', 'Read-only access');

        expect($response->json('permissions'))->toHaveCount(2);
    });

    it('updates a custom role and its permissions', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $role = CustomRole::create([
            'tenant_id' => $tenant->id,
            'name' => 'Old Name',
        ]);
        $role->permissions()->sync(
            Permission::whereIn('name', ['employee.viewAny'])->pluck('id')
        );

        $response = test()->putJson(roleUrl($role->public_id), [
            'name' => 'New Name',
            'permissions' => ['employee.viewAny', 'employee.view', 'employee.create'],
        ]);

        $response->assertOk()
            ->assertJsonPath('name', 'New Name');

        expect($response->json('permissions'))->toHaveCount(3);
    });

    it('deletes a custom role with no assigned users', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $role = CustomRole::create([
            'tenant_id' => $tenant->id,
            'name' => 'Temporary',
        ]);

        test()->deleteJson(roleUrl($role->public_id))->assertNoContent();

        expect(CustomRole::withTrashed()->find($role->id)->deleted_at)->not->toBeNull();
    });

    it('rejects deleting a role with assigned users', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $role = CustomRole::create([
            'tenant_id' => $tenant->id,
            'name' => 'Active Role',
        ]);

        createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant);

        $response = test()->deleteJson(roleUrl($role->public_id));

        $response->assertStatus(422)
            ->assertJsonPath('detail', 'This role is assigned to 1 user(s). Reassign them first.');
    });
});

// ──────────────────────── Validation ────────────────────────

describe('validation', function () {
    it('requires at least one permission on create', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->postJson(roleUrl(), [
            'name' => 'Empty Role',
            'permissions' => [],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('permissions');
    });

    it('rejects duplicate role names within the same tenant', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Existing']);

        test()->postJson(roleUrl(), [
            'name' => 'Existing',
            'permissions' => ['employee.viewAny'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    });

    it('rejects invalid permission names', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->postJson(roleUrl(), [
            'name' => 'Bad Perms',
            'permissions' => ['nonexistent.ability'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('permissions.0');
    });
});

// ──────────────────────── Authorization ────────────────────────

describe('authorization', function () {
    it('denies CRUD to non-admin roles', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        test()->getJson(roleUrl())->assertForbidden();
        test()->postJson(roleUrl(), [
            'name' => 'Blocked',
            'permissions' => ['employee.viewAny'],
        ])->assertForbidden();
    });

    it('allows tenant admin full access', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->getJson(roleUrl())->assertOk();
    });
});

// ──────────────────────── Tenant isolation ────────────────────────

describe('tenant isolation', function () {
    it('does not leak custom roles across tenants', function () {
        $tenantA = createTenant(['subdomain' => 'alpha']);
        $tenantB = createTenant(['subdomain' => 'beta']);

        $roleA = CustomRole::create(['tenant_id' => $tenantA->id, 'name' => 'Role A']);

        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenantB);

        $response = test()->getJson(roleUrl(subdomain: 'beta'));
        $response->assertOk();
        expect($response->json('data'))->toHaveCount(0);

        test()->getJson(roleUrl($roleA->public_id, 'beta'))->assertNotFound();
    });
});

// ──────────────────────── hasPermission integration ────────────────────────

describe('hasPermission with custom role', function () {
    it('uses custom role permissions when assigned', function () {
        $tenant = createTenant();

        $customRole = CustomRole::create([
            'tenant_id' => $tenant->id,
            'name' => 'Custom Viewer',
        ]);
        $customRole->permissions()->sync(
            Permission::whereIn('name', ['employee.viewAny', 'employee.view'])->pluck('id')
        );

        $user = createUser([
            'role' => UserRole::EMPLOYEE,
            'custom_role_id' => $customRole->id,
        ], $tenant);

        expect($user->hasPermission('employee.viewAny'))->toBeTrue()
            ->and($user->hasPermission('employee.view'))->toBeTrue()
            ->and($user->hasPermission('employee.create'))->toBeFalse()
            ->and($user->hasPermission('attendance.checkIn'))->toBeFalse();
    });

    it('falls back to enum role when no custom role assigned', function () {
        $tenant = createTenant();
        $user = createUser(['role' => UserRole::EMPLOYEE], $tenant);

        expect($user->hasPermission('attendance.checkIn'))->toBeTrue()
            ->and($user->hasPermission('employee.create'))->toBeFalse();
    });

    it('super admin bypasses custom role checks', function () {
        $tenant = createTenant();

        $customRole = CustomRole::create([
            'tenant_id' => $tenant->id,
            'name' => 'Restricted',
        ]);
        $customRole->permissions()->sync(
            Permission::whereIn('name', ['employee.viewAny'])->pluck('id')
        );

        $user = createUser([
            'role' => UserRole::SUPER_ADMIN,
            'custom_role_id' => $customRole->id,
        ], $tenant);

        expect($user->hasPermission('anything.at.all'))->toBeTrue();
    });
});

// ──────────────────────── Audit trail ────────────────────────

describe('audit trail', function () {
    it('logs custom role creation', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->postJson(roleUrl(), [
            'name' => 'Audited Role',
            'permissions' => ['employee.viewAny'],
        ])->assertCreated();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'custom_role.created',
            'auditable_type' => CustomRole::class,
        ]);
    });

    it('logs custom role deletion', function () {
        $tenant = createTenant(['subdomain' => 'test']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'To Delete']);

        test()->deleteJson(roleUrl($role->public_id))->assertNoContent();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'custom_role.deleted',
        ]);
    });
});
