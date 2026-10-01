<?php

declare(strict_types=1);

use App\Enums\DisciplinaryCaseStatus;
use App\Enums\EmployeeStatus;
use App\Enums\OrgScope;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\DB;

/*
 * DisciplinaryCaseTest walks the case lifecycle. These are about who may see
 * and decide a case — the most sensitive record an HR system keeps about a
 * person — and about the ids a caller can put in the URL.
 */

function disciplinarySecUrl(Tenant $tenant, Employee $employee, string $suffix = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}/disciplinary-cases{$suffix}";
}

function disciplinarySecOpen(Tenant $tenant, Employee $employee): DisciplinaryCase
{
    $response = test()->postJson(disciplinarySecUrl($tenant, $employee), [
        'category' => 'misconduct',
        'description' => 'Confidential: alleged harassment of a colleague on 2026-08-01.',
        'incident_date' => '2026-08-01',
    ])->assertCreated();

    return DisciplinaryCase::where('public_id', $response->json('public_id'))->firstOrFail();
}

// ── URL ids ─────────────────────────────────────────────────────────────────

it('answers another tenant\'s employee id with 404 on list and open, and opens nothing', function () {
    $tenant = createTenant();
    $other = createTenant();
    $theirs = Employee::factory()->create(['tenant_id' => $other->id]);
    app(CurrentTenant::class)->set($tenant);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->getJson(disciplinarySecUrl($tenant, $theirs))->assertNotFound();
    $this->postJson(disciplinarySecUrl($tenant, $theirs), [
        'category' => 'misconduct',
        'description' => 'Cross-tenant attempt',
        'incident_date' => '2026-08-01',
    ])->assertNotFound();

    expect(DB::table('disciplinary_cases')->where('employee_id', $theirs->id)->exists())->toBeFalse();
});

it('does not resolve one employee\'s case under another employee\'s URL', function () {
    // scopeBindings() on the employees/{employee} group: without it the case
    // would resolve by its own key alone and the action (and its audit row)
    // would be filed against whoever is in the path.
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $accused = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $bystander = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $case = disciplinarySecOpen($tenant, $accused);

    $this->postJson(disciplinarySecUrl($tenant, $bystander, "/{$case->public_id}/decision"), ['decision' => 'guilty'])
        ->assertNotFound();

    expect($case->fresh()->status)->toBe(DisciplinaryCaseStatus::REPORTED);
});

it('refuses every disciplinary route for a suspended tenant', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $case = disciplinarySecOpen($tenant, $employee);

    $tenant->update(['status' => TenantStatus::SUSPENDED]);

    $this->getJson(disciplinarySecUrl($tenant, $employee))->assertForbidden();
    $this->postJson(disciplinarySecUrl($tenant, $employee, "/{$case->public_id}/decision"), ['decision' => 'guilty'])
        ->assertForbidden();

    expect($case->fresh()->status)->toBe(DisciplinaryCaseStatus::REPORTED);
});

// ── Validation ──────────────────────────────────────────────────────────────

it('refuses an incident dated in the future and an unknown category', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $this->postJson(disciplinarySecUrl($tenant, $employee), [
        'category' => 'being_annoying',
        'description' => 'x',
        'incident_date' => now()->addDay()->toDateString(),
    ])->assertUnprocessable()->assertJsonValidationErrors(['category', 'incident_date']);
});

it('requires an effective date for any sanction', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $case = disciplinarySecOpen($tenant, $employee);

    $this->postJson(disciplinarySecUrl($tenant, $employee, "/{$case->public_id}/decision"), [
        'decision' => 'guilty',
        'sanction_type' => 'termination',
    ])->assertUnprocessable()->assertJsonValidationErrors(['sanction_effective_date']);

    expect(Employee::find($employee->id)->status)->not->toBe(EmployeeStatus::TERMINATED);
});

it('keeps the allegation itself out of the audit log', function () {
    // audit_log is append-only and outlives the case; the allegation text
    // belongs in the case record, which can be access-controlled, not in a log
    // every auditor reads.
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    disciplinarySecOpen($tenant, $employee);

    $payloads = DB::table('audit_log')->where('action', 'like', 'employee.disciplinary%')->pluck('payload');

    expect($payloads)->not->toBeEmpty();
    foreach ($payloads as $payload) {
        expect((string) $payload)->not->toContain('harassment');
    }
});

// ── Who may see and decide ──────────────────────────────────────────────────

it('lets a supervisor read the cases of their own direct report', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $boss = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $boss->id]);
    disciplinarySecOpen($tenant, $report);

    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $boss->id], $tenant);

    $this->getJson(disciplinarySecUrl($tenant, $report))->assertOk()->assertJsonCount(1);
});

it('does not let a supervisor read the cases of someone outside their reports', function () {
    // index() authorized viewAny — a bare permission — and never asked
    // canAccessEmployee($employee), which is how EmployeePolicy limits a
    // SUPERVISOR to direct reports. So every supervisor in the tenant could
    // read every disciplinary case, including their peers' and their own
    // manager's, though the seeder grants it for "their team's".
    // DisciplinaryCasePolicy::view now applies the org scope.
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $boss = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $peer = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => null]);
    disciplinarySecOpen($tenant, $peer);

    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $boss->id], $tenant);

    $this->getJson(disciplinarySecUrl($tenant, $peer))->assertForbidden();
});

it('refuses a supervisor login with no employee record, which reaches no one', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    disciplinarySecOpen($tenant, $employee);

    actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

    $this->getJson(disciplinarySecUrl($tenant, $employee))->assertForbidden();
});

it('limits a department-scoped role holding manage to its own department', function () {
    // The scope applies to acting as well as reading: a custom role with
    // disciplinary_case.manage and a department scope opens cases in its
    // department and nowhere else.
    $tenant = createTenant();
    $mine = Department::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = Department::factory()->create(['tenant_id' => $tenant->id]);
    $head = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);
    $inside = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $mine->id]);
    $outside = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id]);

    $role = CustomRole::create([
        'tenant_id' => $tenant->id,
        'name' => 'Department HR',
        'org_scope' => OrgScope::DEPARTMENT->value,
    ]);
    $role->permissions()->sync(Permission::whereIn('name', [
        'disciplinary_case.viewAny', 'disciplinary_case.manage',
    ])->pluck('id'));

    actingAsUser([
        'role' => UserRole::EMPLOYEE,
        'custom_role_id' => $role->id,
        'employee_id' => $head->id,
    ], $tenant);

    $payload = ['category' => 'misconduct', 'description' => 'x', 'incident_date' => '2026-08-01'];

    $this->postJson(disciplinarySecUrl($tenant, $inside), $payload)->assertCreated();
    $this->postJson(disciplinarySecUrl($tenant, $outside), $payload)->assertForbidden();
    $this->getJson(disciplinarySecUrl($tenant, $outside))->assertForbidden();

    expect(DisciplinaryCase::where('employee_id', $outside->id)->exists())->toBeFalse();
});

it('does not let an HR admin decide a case brought against themselves', function () {
    // Leave and correction approval refuse deciding one's own. Nothing in
    // DisciplinaryCaseController/Service compared the acting user's
    // employee_id with the case's: an HR admin who was the subject could
    // record `not_guilty` on their own case, or uphold their own appeal and
    // erase the sanction. DisciplinaryCasePolicy::decide refuses the subject.
    $tenant = createTenant();
    $hrPerson = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $case = disciplinarySecOpen($tenant, $hrPerson);

    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrPerson->id], $tenant);

    $this->postJson(disciplinarySecUrl($tenant, $hrPerson, "/{$case->public_id}/decision"), ['decision' => 'not_guilty'])
        ->assertForbidden();

    expect($case->fresh()->status)->toBe(DisciplinaryCaseStatus::REPORTED);
});

it('does not let the subject add notes to, resolve the appeal on, or close their own case', function () {
    $tenant = createTenant();
    $hrPerson = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $case = disciplinarySecOpen($tenant, $hrPerson);

    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrPerson->id], $tenant);

    $this->postJson(disciplinarySecUrl($tenant, $hrPerson, "/{$case->public_id}/notes"), ['note' => 'All lies.'])
        ->assertForbidden();
    $this->postJson(disciplinarySecUrl($tenant, $hrPerson, "/{$case->public_id}/close"), [])
        ->assertForbidden();

    DB::table('disciplinary_cases')->where('id', $case->id)->update(['status' => DisciplinaryCaseStatus::APPEALED->value]);

    $this->postJson(disciplinarySecUrl($tenant, $hrPerson, "/{$case->public_id}/appeal-decision"), ['outcome' => 'upheld'])
        ->assertForbidden();

    expect($case->fresh()->status)->toBe(DisciplinaryCaseStatus::APPEALED);
});

it('still lets the subject read their own case, and another HR admin decide it', function () {
    $tenant = createTenant();
    $hrPerson = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $case = disciplinarySecOpen($tenant, $hrPerson);

    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrPerson->id], $tenant);
    $this->getJson(disciplinarySecUrl($tenant, $hrPerson))->assertOk()->assertJsonCount(1);

    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $colleague->id], $tenant);
    $this->postJson(disciplinarySecUrl($tenant, $hrPerson, "/{$case->public_id}/decision"), ['decision' => 'not_guilty'])
        ->assertOk();
});
