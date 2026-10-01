<?php

declare(strict_types=1);

use App\Enums\ConflictType;
use App\Enums\UserRole;
use App\Models\AttendanceConflict;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\KioskSession;
use App\Models\Permission;
use App\Models\Tenant;

/**
 * Audit N12: the conflict and kiosk-session lists were not scoped or paged
 * like their siblings.
 */
function conflictScopeConflictFor(Tenant $tenant, Employee $employee): AttendanceConflict
{
    $records = AttendanceRecord::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    return AttendanceConflict::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'record_a_id' => $records[0]->id,
        'record_b_id' => $records[1]->id,
        'conflict_type' => ConflictType::MULTI_SOURCE_FAR,
    ]);
}

test('a custom role scoped to direct reports lists only its team\'s attendance conflicts', function () {
    $tenant = createTenant();
    $lead = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $lead->id]);
    $stranger = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Shift Lead', 'org_scope' => 'direct_reports']);
    $role->permissions()->sync(Permission::whereIn('name', ['attendance.viewConflicts'])->pluck('id'));
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id, 'custom_role_id' => $role->id], $tenant);

    $teamConflict = conflictScopeConflictFor($tenant, $report);
    conflictScopeConflictFor($tenant, $stranger);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/conflicts")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('public_id')->all())->toBe([$teamConflict->public_id]);
});

test('HR still lists every attendance conflict in the tenant', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    conflictScopeConflictFor($tenant, Employee::factory()->create(['tenant_id' => $tenant->id]));
    conflictScopeConflictFor($tenant, Employee::factory()->create(['tenant_id' => $tenant->id]));

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/conflicts")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('the kiosk session list honours per_page like its siblings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    KioskSession::factory()->count(3)->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/kiosk-sessions?per_page=2")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3);
});
