<?php

declare(strict_types=1);

use App\Enums\DisciplinaryCaseStatus;
use App\Enums\EmployeeStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
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
    // DisciplinaryCaseController::index (line 27) authorizes viewAny — a bare
    // permission — and never asks canAccessEmployee($employee), which is how
    // EmployeePolicy limits a SUPERVISOR to direct reports. So every
    // supervisor in the tenant can read every disciplinary case, including
    // their peers' and their own manager's. The seeder comment beside the
    // grant ("Supervisors read their team's ...") states the intended scope.
    // Note: DisciplinaryCaseTest "lets a supervisor view cases but not open
    // one" uses a supervisor with no employee record and currently pins the
    // unscoped behaviour; it will need the same fix.
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $boss = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $peer = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => null]);
    disciplinarySecOpen($tenant, $peer);

    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $boss->id], $tenant);

    $this->getJson(disciplinarySecUrl($tenant, $peer))->assertForbidden();
})->todo(note: 'DEFECT: DisciplinaryCaseController::index (line 27) checks only disciplinary_case.viewAny, never canAccessEmployee(); any supervisor reads any employee\'s disciplinary cases');

it('does not let an HR admin decide a case brought against themselves', function () {
    // Leave approval refuses self-approval (LeaveRequestController:263).
    // Nothing in DisciplinaryCaseController/Service compares the acting user's
    // employee_id with the case's: an HR admin who is the subject can record
    // `not_guilty` on their own case, or uphold their own appeal and erase the
    // sanction.
    $tenant = createTenant();
    $hrPerson = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $case = disciplinarySecOpen($tenant, $hrPerson);

    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrPerson->id], $tenant);

    $this->postJson(disciplinarySecUrl($tenant, $hrPerson, "/{$case->public_id}/decision"), ['decision' => 'not_guilty'])
        ->assertForbidden();

    expect($case->fresh()->status)->toBe(DisciplinaryCaseStatus::REPORTED);
})->todo(note: 'DEFECT: DisciplinaryCaseController decide/resolveAppeal/close have no subject-of-the-case guard; an HR admin can decide or uphold the appeal on their own case');
