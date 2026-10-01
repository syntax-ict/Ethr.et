<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\ConflictResolutionStatus;
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

/**
 * A custom role scoped to direct reports holding both conflict permissions,
 * acting as $lead.
 */
function conflictScopeLeadRole(Tenant $tenant, Employee $lead): void
{
    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Shift Lead', 'org_scope' => 'direct_reports']);
    $role->permissions()->sync(Permission::whereIn('name', [
        'attendance.viewConflicts', 'attendance.resolveConflicts',
    ])->pluck('id'));
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $lead->id, 'custom_role_id' => $role->id], $tenant);
}

function conflictScopeResolveUrl(Tenant $tenant, AttendanceConflict $conflict): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/attendance/conflicts/{$conflict->public_id}/resolve";
}

test('a scoped role cannot resolve a conflict outside its scope, and voids nothing', function () {
    // resolve() checked attendance.resolveConflicts alone while index() was
    // already scoped, so the lead could resolve — by public id — a conflict
    // it was not allowed to list, voiding a stranger's punch.
    $tenant = createTenant();
    $lead = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $stranger = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $conflict = conflictScopeConflictFor($tenant, $stranger);
    conflictScopeLeadRole($tenant, $lead);

    test()->putJson(conflictScopeResolveUrl($tenant, $conflict), ['resolution' => ConflictResolutionStatus::KEEP_A->value])
        ->assertForbidden();

    expect($conflict->fresh()->resolution)->toBe(ConflictResolutionStatus::PENDING)
        ->and($conflict->recordB->fresh()->status)->not->toBe(AttendanceStatus::VOIDED);
});

test('a scoped role resolves a conflict inside its scope', function () {
    $tenant = createTenant();
    $lead = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $lead->id]);
    $conflict = conflictScopeConflictFor($tenant, $report);
    conflictScopeLeadRole($tenant, $lead);

    test()->putJson(conflictScopeResolveUrl($tenant, $conflict), ['resolution' => ConflictResolutionStatus::KEEP_A->value])
        ->assertOk();

    expect($conflict->fresh()->resolution)->toBe(ConflictResolutionStatus::KEEP_A)
        ->and($conflict->recordB->fresh()->status)->toBe(AttendanceStatus::VOIDED);
});

test('nobody resolves a conflict on their own attendance, HR included', function () {
    $tenant = createTenant();
    $hrPerson = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $conflict = conflictScopeConflictFor($tenant, $hrPerson);
    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrPerson->id], $tenant);

    test()->putJson(conflictScopeResolveUrl($tenant, $conflict), ['resolution' => ConflictResolutionStatus::KEEP_A->value])
        ->assertForbidden();

    expect($conflict->fresh()->resolution)->toBe(ConflictResolutionStatus::PENDING);
});

test('HR still resolves any other employee\'s conflict', function () {
    $tenant = createTenant();
    $conflict = conflictScopeConflictFor($tenant, Employee::factory()->create(['tenant_id' => $tenant->id]));
    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id], $tenant);

    test()->putJson(conflictScopeResolveUrl($tenant, $conflict), ['resolution' => ConflictResolutionStatus::DISMISSED->value])
        ->assertOk();
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
