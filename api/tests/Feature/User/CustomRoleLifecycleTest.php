<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Permission;

/*
 * A-Z review 2026-11-03: switching a custom role off changed nothing (C-05),
 * and an edit to its permissions was audited without saying what changed
 * (C-06).
 */

function lifecycleRole(int $tenantId, array $permissions): CustomRole
{
    $role = CustomRole::create(['tenant_id' => $tenantId, 'name' => 'Recruiter', 'org_scope' => 'all']);
    $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));

    return $role;
}

it('grants nothing while switched off, and its holder is back on their base role', function () {
    $tenant = createTenant();
    $role = lifecycleRole($tenant->id, ['payroll.process']);
    $holder = createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant);

    expect($holder->hasPermission('payroll.process'))->toBeTrue();

    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);
    $this->putJson("/api/v1/roles/{$role->public_id}", ['is_active' => false])->assertOk();

    $holder = $holder->fresh();
    expect($holder->hasPermission('payroll.process'))->toBeFalse()
        ->and($holder->hasPermission('profile.view'))->toBe(in_array('profile.view', Permission::permissionsForRole('employee'), true));
});

it('cannot be handed to someone new while switched off', function () {
    $tenant = createTenant();
    $role = lifecycleRole($tenant->id, ['profile.view']);
    $role->update(['is_active' => false]);
    $someone = createUser(['role' => UserRole::EMPLOYEE], $tenant);
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->patchJson("/api/v1/users/{$someone->public_id}", ['custom_role_id' => $role->public_id])
        ->assertUnprocessable()->assertJsonValidationErrors('custom_role_id');
});

it('records which permissions an edit granted and took away', function () {
    $tenant = createTenant();
    $role = lifecycleRole($tenant->id, ['profile.view', 'employee.viewAny']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson("/api/v1/roles/{$role->public_id}", ['permissions' => ['profile.view', 'payroll.process']])->assertOk();

    $payload = AuditLog::query()->where('action', 'custom_role.updated')->latest('id')->firstOrFail()->payload;
    expect($payload['permissions_added'])->toBe(['payroll.process'])
        ->and($payload['permissions_removed'])->toBe(['employee.viewAny']);
});
