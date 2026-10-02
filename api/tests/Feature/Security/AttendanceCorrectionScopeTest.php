<?php

declare(strict_types=1);

use App\Enums\CorrectionStatus;
use App\Enums\UserRole;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use Carbon\Carbon;

/**
 * Audit N3: anyone could correct anyone's attendance.
 *
 * `store` accepted any record in the tenant, so an employee could file against
 * a colleague's punches, and `approve` then rewrote them. `pending`, `approve`,
 * `reject` and `payroll-impact` were authorised on the permission alone, so a
 * supervisor acted on — and read the hourly rate of — every employee in the
 * tenant. The rule now lives in `AttendanceCorrectionPolicy`.
 */
function correctionScopeRecordFor(Tenant $tenant, Employee $employee): AttendanceRecord
{
    return AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'check_in' => Carbon::today()->setTime(9, 0),
        'check_out' => Carbon::today()->setTime(17, 0),
    ]);
}

function correctionScopePendingFor(Tenant $tenant, Employee $employee, ?AttendanceRecord $record = null): AttendanceCorrection
{
    $record ??= correctionScopeRecordFor($tenant, $employee);

    return AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
        'proposed_check_in' => Carbon::today()->setTime(6, 0),
        'status' => CorrectionStatus::PENDING,
    ]);
}

// ── Filing ──

test('an employee cannot file a correction against a colleague\'s attendance record', function () {
    $tenant = createTenant();
    $me = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id], $tenant);

    $mine = correctionScopeRecordFor($tenant, $me);
    $theirs = correctionScopeRecordFor($tenant, $colleague);

    // Control: the same caller filing against their own record succeeds, so
    // the 403 below is the ownership rule and not a missing permission.
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections", [
        'attendance_record_public_id' => $mine->public_id,
        'reason' => 'I arrived earlier',
    ])->assertCreated();

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections", [
        'attendance_record_public_id' => $theirs->public_id,
        'reason' => 'Rewriting a colleague\'s day',
    ])->assertForbidden();

    expect(AttendanceCorrection::where('attendance_record_id', $theirs->id)->exists())->toBeFalse();
});

test('HR files a correction on an employee\'s behalf and it belongs to that employee', function () {
    $tenant = createTenant();
    $hrEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrEmployee->id], $tenant);

    $subject = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $record = correctionScopeRecordFor($tenant, $subject);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections", [
        'attendance_record_public_id' => $record->public_id,
        'reason' => 'Device was down',
    ])->assertCreated();

    // It used to take the filer's employee id first, filing HR's own
    // correction against another person's record.
    $response->assertJsonPath('employee_public_id', $subject->public_id);
    expect(AttendanceCorrection::firstOrFail()->employee_id)->toBe($subject->id);
});

test('a supervisor cannot file for their report without attendance.manage', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections", [
        'attendance_record_public_id' => correctionScopeRecordFor($tenant, $report)->public_id,
        'reason' => 'On their behalf',
    ])->assertForbidden();
});

test('another tenant\'s record id is refused exactly like a nonexistent one', function () {
    $other = createTenant(['subdomain' => 'beta']);
    $foreign = correctionScopeRecordFor($other, Employee::factory()->create(['tenant_id' => $other->id]));

    $tenant = createTenant(['subdomain' => 'alpha']);
    $me = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $me->id], $tenant);

    $nonexistent = test()->postJson('http://alpha.ethr.test/api/v1/attendance/corrections', [
        'attendance_record_public_id' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        'reason' => 'Probe',
    ])->assertStatus(422);

    $crossTenant = test()->postJson('http://alpha.ethr.test/api/v1/attendance/corrections', [
        'attendance_record_public_id' => $foreign->public_id,
        'reason' => 'Probe',
    ])->assertStatus(422);

    expect($crossTenant->json('errors.attendance_record_public_id'))
        ->toBe($nonexistent->json('errors.attendance_record_public_id'));
    expect(AttendanceCorrection::withoutGlobalScopes()->count())->toBe(0);
});

// ── Deciding ──

test('a supervisor cannot approve a correction for someone outside their team', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id]);
    $stranger = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $teamCorrection = correctionScopePendingFor($tenant, $report);
    $strangerRecord = correctionScopeRecordFor($tenant, $stranger);
    $strangerCorrection = correctionScopePendingFor($tenant, $stranger, $strangerRecord);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$teamCorrection->public_id}/approve")
        ->assertOk();

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$strangerCorrection->public_id}/approve")
        ->assertForbidden();

    expect($strangerCorrection->fresh()->status)->toBe(CorrectionStatus::PENDING);
    expect($strangerRecord->fresh()->check_in->format('H:i'))->toBe('09:00');
});

test('a supervisor cannot reject a correction for someone outside their team', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $stranger = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $correction = correctionScopePendingFor($tenant, $stranger);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$correction->public_id}/reject", [
        'reason' => 'Not mine to decide',
    ])->assertForbidden();

    expect($correction->fresh()->status)->toBe(CorrectionStatus::PENDING);
});

test('an approver cannot approve a correction to their own attendance', function () {
    $tenant = createTenant();
    $hrEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hrEmployee->id], $tenant);

    $own = correctionScopePendingFor($tenant, $hrEmployee);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$own->public_id}/approve")
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/self-approval');

    expect($own->fresh()->status)->toBe(CorrectionStatus::PENDING);
});

test('HR approves a correction for any employee in the tenant', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $anyone = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $correction = correctionScopePendingFor($tenant, $anyone);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$correction->public_id}/approve")
        ->assertOk()
        ->assertJsonPath('status', 'approved');
});

test('another tenant\'s correction id is a 404 like a nonexistent one', function () {
    $other = createTenant(['subdomain' => 'beta']);
    $foreign = correctionScopePendingFor($other, Employee::factory()->create(['tenant_id' => $other->id]));

    $tenant = createTenant(['subdomain' => 'alpha']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->putJson('http://alpha.ethr.test/api/v1/attendance/corrections/01JZZZZZZZZZZZZZZZZZZZZZZZ/approve')
        ->assertNotFound();
    test()->putJson("http://alpha.ethr.test/api/v1/attendance/corrections/{$foreign->public_id}/approve")
        ->assertNotFound();
    test()->getJson("http://alpha.ethr.test/api/v1/attendance/corrections/{$foreign->public_id}/payroll-impact")
        ->assertNotFound();

    expect(AttendanceCorrection::withoutGlobalScopes()->find($foreign->id)->status)->toBe(CorrectionStatus::PENDING);
});

// ── Reading ──

test('the pending queue lists only the approver\'s team, and not their own', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id]);
    $stranger = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $teamCorrection = correctionScopePendingFor($tenant, $report);
    correctionScopePendingFor($tenant, $stranger);
    correctionScopePendingFor($tenant, $supervisor);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/pending")
        ->assertOk();

    expect(collect($response->json('data'))->pluck('public_id')->all())->toBe([$teamCorrection->public_id]);
});

test('the payroll impact of a correction outside the team is not revealed', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $report = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id, 'salary_cents' => 1_760_000]);
    $stranger = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 9_000_000]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $teamCorrection = correctionScopePendingFor($tenant, $report);
    $strangerCorrection = correctionScopePendingFor($tenant, $stranger);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$teamCorrection->public_id}/payroll-impact")
        ->assertOk();

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$strangerCorrection->public_id}/payroll-impact")
        ->assertForbidden()
        ->assertJsonMissingPath('hourly_rate_cents');
});

test('my corrections lists the caller\'s own corrections and nobody else\'s', function () {
    $other = createTenant(['subdomain' => 'beta']);
    correctionScopePendingFor($other, Employee::factory()->create(['tenant_id' => $other->id]));

    $tenant = createTenant(['subdomain' => 'alpha']);
    $me = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id], $tenant);

    $pending = correctionScopePendingFor($tenant, $me);
    $decided = correctionScopePendingFor($tenant, $me);
    $decided->update(['status' => CorrectionStatus::REJECTED]);
    correctionScopePendingFor($tenant, $colleague);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/attendance/corrections/my')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('public_id')->sort()->values()->all())
        ->toBe(collect([$pending->public_id, $decided->public_id])->sort()->values()->all());

    test()->getJson('http://alpha.ethr.test/api/v1/attendance/corrections/my?filter[status]=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.public_id', $pending->public_id);
});

test('my corrections is empty for a login with no employee record', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    correctionScopePendingFor($tenant, Employee::factory()->create(['tenant_id' => $tenant->id]));

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/my")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
