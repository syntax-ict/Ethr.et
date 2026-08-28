<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\CorrectionStatus;
use App\Enums\UserRole;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Services\Attendance\AttendanceIntelligence;
use Carbon\Carbon;

// ── Intelligence: Late Detection ──

test('intelligence service detects late arrival', function () {
    $tenant = createTenant();
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'grace_minutes' => 15,
    ]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
        'shift_id' => $shift->id,
        'check_in' => Carbon::today()->setTime(9, 0),
        'status' => AttendanceStatus::LATE,
    ]);

    $intelligence = app(AttendanceIntelligence::class);
    expect($intelligence->detectLate($record))->toBeTrue();
});

test('intelligence service does not flag on-time arrival as late', function () {
    $tenant = createTenant();
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'grace_minutes' => 15,
    ]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
        'shift_id' => $shift->id,
        'check_in' => Carbon::today()->setTime(8, 30),
        'status' => AttendanceStatus::PRESENT,
    ]);

    $intelligence = app(AttendanceIntelligence::class);
    expect($intelligence->detectLate($record))->toBeFalse();
});

// ── Intelligence: Early Leave ──

test('intelligence service detects early departure', function () {
    $tenant = createTenant();
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'end_time' => '17:30',
        'early_departure_minutes' => 15,
    ]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
        'shift_id' => $shift->id,
        'check_in' => Carbon::today()->setTime(8, 30),
        'check_out' => Carbon::today()->setTime(16, 0),
    ]);

    $intelligence = app(AttendanceIntelligence::class);
    expect($intelligence->detectEarlyLeave($record))->toBeTrue();
});

// ── Intelligence: Missing Punch ──

test('intelligence detects missing check-out', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    AttendanceRecord::factory()->checkInOnly()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => now()->format('Y-m-d'),
        'check_in' => now()->subHours(4),
    ]);

    $intelligence = app(AttendanceIntelligence::class);
    $result = $intelligence->detectMissingPunch($employee, now());

    expect($result)->toBe('missing_check_out');
});

test('intelligence returns null when punch complete', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => now()->format('Y-m-d'),
    ]);

    $intelligence = app(AttendanceIntelligence::class);
    $result = $intelligence->detectMissingPunch($employee, now());

    expect($result)->toBeNull();
});

// ── Intelligence: Overtime ──

test('intelligence calculates overtime correctly', function () {
    $tenant = createTenant();
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'end_time' => '17:30',
    ]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
        'shift_id' => $shift->id,
        'check_in' => Carbon::today()->setTime(8, 30),
        'check_out' => Carbon::today()->setTime(19, 30),
    ]);

    $intelligence = app(AttendanceIntelligence::class);
    expect($intelligence->calculateOvertime($record))->toBe(120);
});

// ── Intelligence Dashboard Endpoint ──

test('hr admin can view intelligence dashboard', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    AttendanceRecord::factory()->late()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => now()->format('Y-m-d'),
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/intelligence");

    $response->assertOk()
        ->assertJsonStructure([
            'date',
            'anomalies' => ['count', 'thresholds', 'records'],
            'late_arrivals' => ['count', 'records'],
            'early_departures' => ['count'],
            'missing_punches' => ['count'],
        ]);
});

test('employee cannot view intelligence dashboard', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/intelligence")
        ->assertForbidden();
});

// ── Anomalies (excessive hours / overtime) ──

test('intelligence dashboard surfaces an excessive-hours anomaly with explanation data', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $date = now()->format('Y-m-d');

    // 19 worked hours (1140 min) trips the >960-minute excessive_hours threshold.
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => now()->setTime(4, 0),
        'check_out' => now()->setTime(23, 0),
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/intelligence?date={$date}");

    $response->assertOk()
        ->assertJsonPath('anomalies.count', 1)
        ->assertJsonPath('anomalies.thresholds.excessive_hours_minutes', AttendanceIntelligence::EXCESSIVE_HOURS_MINUTES)
        ->assertJsonPath('anomalies.thresholds.excessive_overtime_minutes', AttendanceIntelligence::EXCESSIVE_OVERTIME_MINUTES)
        ->assertJsonPath('anomalies.records.0.employee_name', $employee->name)
        ->assertJsonPath('anomalies.records.0.types.0', 'excessive_hours')
        ->assertJsonPath('anomalies.records.0.worked_minutes', 1140);
});

test('a normal working day raises no anomaly on the intelligence dashboard', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $date = now()->format('Y-m-d');

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => now()->setTime(8, 0),
        'check_out' => now()->setTime(17, 0),
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/intelligence?date={$date}");

    $response->assertOk()->assertJsonPath('anomalies.count', 0);
});

// ── Overtime Endpoint ──

test('hr admin can view overtime summary', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/overtime?period=monthly");

    $response->assertOk()
        ->assertJsonStructure(['period', 'employees']);
});

// ── Correction Submission ──

test('employee can submit correction request', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections", [
        'attendance_record_public_id' => $record->public_id,
        'reason' => 'Check-in time was wrong, I arrived earlier',
        'proposed_check_in' => Carbon::today()->setTime(8, 15)->toIso8601String(),
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'pending')
        ->assertJsonMissingPath('id');
});

test('correction requires reason', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections", [
        'attendance_record_public_id' => $record->public_id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

// ── Correction Approval ──

test('supervisor can approve correction', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'check_in' => Carbon::today()->setTime(9, 0),
    ]);

    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
        'proposed_check_in' => Carbon::today()->setTime(8, 15),
        'status' => CorrectionStatus::PENDING,
    ]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$correction->public_id}/approve");

    $response->assertOk()
        ->assertJsonPath('status', 'approved');

    $record->refresh();
    expect($record->check_in->format('H:i'))->toBe('08:15');
});

test('supervisor can preview payroll impact of a pending correction', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'salary_cents' => 1_760_000]);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'check_in' => Carbon::today()->setTime(9, 0),
        'check_out' => Carbon::today()->setTime(17, 0),
    ]);

    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
        'proposed_check_in' => Carbon::today()->setTime(8, 0),
        'proposed_check_out' => Carbon::today()->setTime(17, 0),
        'status' => CorrectionStatus::PENDING,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$correction->public_id}/payroll-impact");

    $response->assertOk()
        ->assertJsonPath('original_hours', 8)
        ->assertJsonPath('proposed_hours', 9)
        ->assertJsonPath('difference_minutes', 60)
        ->assertJsonPath('currency', 'ETB');

    expect($response->json('estimated_impact_cents'))->toBeGreaterThan(0);
});

test('employee cannot approve correction', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$correction->public_id}/approve")
        ->assertForbidden();
});

test('cannot approve already approved correction', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $correction = AttendanceCorrection::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$correction->public_id}/approve")
        ->assertStatus(422);
});

// ── Correction Rejection ──

test('supervisor can reject correction with reason', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
        'status' => CorrectionStatus::PENDING,
    ]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$correction->public_id}/reject", [
        'reason' => 'Insufficient evidence',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'rejected');
});

test('rejection requires reason', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$correction->public_id}/reject", [])
        ->assertStatus(422);
});

// ── Correction Listing ──

test('hr admin can list all corrections', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    AttendanceCorrection::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('supervisor can view pending corrections', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
        'status' => CorrectionStatus::PENDING,
    ]);
    AttendanceCorrection::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/pending");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── Correction Audit Logging ──

test('correction submission is audit logged', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections", [
        'attendance_record_public_id' => $record->public_id,
        'reason' => 'Clock was broken',
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'correction.submitted',
    ]);
});

test('correction approval is audit logged', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'attendance_record_id' => $record->id,
        'employee_id' => $employee->id,
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/corrections/{$correction->public_id}/approve")
        ->assertOk();

    $this->assertDatabaseHas('audit_log', [
        'action' => 'correction.approved',
    ]);
});

// ── Holiday CRUD ──

test('hr admin can create a holiday', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/holidays", [
        'name' => 'Ethiopian New Year',
        'name_am' => 'እንቁጣጣሽ',
        'date' => '2026-09-11',
        'recurring' => true,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('name', 'Ethiopian New Year')
        ->assertJsonPath('name_am', 'እንቁጣጣሽ')
        ->assertJsonMissingPath('id');
});

test('employee can list holidays', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    Holiday::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/holidays");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('employee cannot create holiday', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/holidays", [
        'name' => 'Test',
        'date' => '2026-01-01',
    ])->assertForbidden();
});

test('hr admin can update a holiday', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $holiday = Holiday::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Name']);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/holidays/{$holiday->public_id}", [
        'name' => 'Updated Holiday',
    ]);

    $response->assertOk()
        ->assertJsonPath('name', 'Updated Holiday');
});

test('tenant admin can delete a holiday', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $holiday = Holiday::factory()->create(['tenant_id' => $tenant->id]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/holidays/{$holiday->public_id}")
        ->assertNoContent();

    expect(Holiday::find($holiday->id))->toBeNull();
});

test('hr admin cannot delete a holiday', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $holiday = Holiday::factory()->create(['tenant_id' => $tenant->id]);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/holidays/{$holiday->public_id}")
        ->assertForbidden();
});

test('holiday creation is audit logged', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/holidays", [
        'name' => 'Audit Holiday',
        'date' => '2026-01-01',
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'holiday.created',
    ]);
});

// ── Holiday Tenant Isolation ──

test('holidays are isolated per tenant', function () {
    $tenant1 = createTenant(['subdomain' => 'alpha']);
    $tenant2 = createTenant(['subdomain' => 'beta']);

    Holiday::factory()->count(2)->create(['tenant_id' => $tenant1->id]);
    Holiday::factory()->count(4)->create(['tenant_id' => $tenant2->id]);

    $user1 = actingAsUser(['role' => UserRole::EMPLOYEE], $tenant1);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/holidays');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── Authentication ──

test('corrections require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->postJson('http://authtest.ethr.test/api/v1/attendance/corrections', [])
        ->assertUnauthorized();
});

test('holidays require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->getJson('http://authtest.ethr.test/api/v1/holidays')
        ->assertUnauthorized();
});
