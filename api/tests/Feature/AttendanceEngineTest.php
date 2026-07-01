<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Attendance\ConfidenceScorer;
use App\Services\Attendance\ShiftMatcher;
use Carbon\Carbon;

// Reset Carbon mock after every test that uses setTestNow
afterEach(function () {
    Carbon::setTestNow();
});

// ── Attendance Check-in / Check-out ──

test('employee can check in via web', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in", [
        'idempotency_key' => 'test-key-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('source', 'web')
        ->assertJsonPath('was_duplicate', false)
        ->assertJsonMissingPath('id');
});

test('duplicate check-in returns existing record with was_duplicate', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->actingAs($user);

    $response1 = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in", [
        'idempotency_key' => 'dup-key-001',
    ]);

    $response1->assertStatus(201);

    $response2 = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in", [
        'idempotency_key' => 'dup-key-001',
    ]);

    $response2->assertStatus(200)
        ->assertJsonPath('was_duplicate', true)
        ->assertJsonPath('public_id', $response1->json('public_id'));
});

test('employee can check out after checking in', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $record = AttendanceRecord::factory()->checkInOnly()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => now()->format('Y-m-d'),
        'check_in' => now()->subHours(4),
        'source' => AttendanceSource::WEB,
    ]);

    $engine = app(AttendanceEngine::class);
    $result = $engine->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: AttendanceSource::WEB,
        type: 'check_out',
        idempotencyKey: 'co-direct-001',
    ));

    expect($result->record->check_out)->not->toBeNull();
    expect($result->record->id)->toBe($record->id);
});

test('check-in requires idempotency key', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in", [])
        ->assertStatus(422);
});

// ── Confidence Scoring ──

test('confidence scorer returns correct base scores per source', function () {
    $scorer = new ConfidenceScorer;

    $webInput = new AttendanceInput(1, 1, AttendanceSource::WEB, 'check_in');
    expect($scorer->calculate($webInput))->toBe(75);

    $bioInput = new AttendanceInput(1, 1, AttendanceSource::BIOMETRIC, 'check_in');
    expect($scorer->calculate($bioInput))->toBe(100);

    $manualInput = new AttendanceInput(1, 1, AttendanceSource::MANUAL, 'check_in');
    expect($scorer->calculate($manualInput))->toBe(60);

    $csvInput = new AttendanceInput(1, 1, AttendanceSource::CSV, 'check_in');
    expect($scorer->calculate($csvInput))->toBe(50);

    $kioskInput = new AttendanceInput(1, 1, AttendanceSource::KIOSK, 'check_in');
    expect($scorer->calculate($kioskInput))->toBe(80);

    $qrInput = new AttendanceInput(1, 1, AttendanceSource::QR, 'check_in');
    expect($scorer->calculate($qrInput))->toBe(85);
});

test('mobile confidence varies with GPS and selfie', function () {
    $scorer = new ConfidenceScorer;

    $withSelfie = new AttendanceInput(1, 1, AttendanceSource::MOBILE, 'check_in', photoPath: 'selfie.jpg');
    expect($scorer->calculate($withSelfie))->toBe(95);

    $withGps = new AttendanceInput(1, 1, AttendanceSource::MOBILE, 'check_in', latitude: 9.0, longitude: 38.0);
    expect($scorer->calculate($withGps, geofenceVerified: true))->toBe(90);

    $gpsNoGeofence = new AttendanceInput(1, 1, AttendanceSource::MOBILE, 'check_in', latitude: 9.0, longitude: 38.0);
    expect($scorer->calculate($gpsNoGeofence, geofenceVerified: false))->toBe(88);

    $noGps = new AttendanceInput(1, 1, AttendanceSource::MOBILE, 'check_in');
    expect($scorer->calculate($noGps))->toBe(75);
});

test('biometric offline score is 95', function () {
    $scorer = new ConfidenceScorer;

    $input = new AttendanceInput(1, 1, AttendanceSource::BIOMETRIC, 'check_in', offlineToken: 'abc123');
    expect($scorer->calculate($input))->toBe(95);
});

// ── Geofence Verification ──

test('geofence verification works within radius', function () {
    $scorer = new ConfidenceScorer;

    $branch = Branch::factory()->make([
        'latitude' => 9.0192,
        'longitude' => 38.7525,
        'geofence_radius_meters' => 100,
    ]);

    expect($scorer->verifyGeofence(9.0192, 38.7525, $branch))->toBeTrue();
    expect($scorer->verifyGeofence(9.0192, 38.7526, $branch))->toBeTrue();
    expect($scorer->verifyGeofence(10.0, 39.0, $branch))->toBeFalse();
});

// ── Status Calculation ──

test('attendance status is present when within grace period', function () {
    $tenant = createTenant();
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'grace_minutes' => 15,
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
    ]);

    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    Carbon::setTestNow(Carbon::today()->setTime(8, 40));

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in", [
        'idempotency_key' => 'grace-test-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'present');

    Carbon::setTestNow();
});

test('attendance status is late when past grace period', function () {
    $tenant = createTenant();
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'grace_minutes' => 15,
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
    ]);

    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    Carbon::setTestNow(Carbon::today()->setTime(9, 0));

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in", [
        'idempotency_key' => 'late-test-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'late');

    Carbon::setTestNow();
});

// ── Worked Minutes & Overtime ──

test('worked minutes calculated correctly', function () {
    $tenant = createTenant();
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'break_minutes' => 60,
    ]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
        'shift_id' => $shift->id,
        'check_in' => Carbon::today()->setTime(8, 30),
        'check_out' => Carbon::today()->setTime(17, 30),
    ]);

    expect($record->workedMinutes())->toBe(480);
});

test('overtime minutes calculated for work beyond shift end', function () {
    $tenant = createTenant();
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'end_time' => '17:30',
        'crosses_midnight' => false,
    ]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
        'shift_id' => $shift->id,
        'check_in' => Carbon::today()->setTime(8, 30),
        'check_out' => Carbon::today()->setTime(19, 30),
    ]);

    expect($record->overtimeMinutes())->toBe(120);
});

// ── My Attendance (Employee View) ──

test('employee can view own attendance', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    AttendanceRecord::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/my");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('my attendance filters by date range', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => '2026-06-01',
    ]);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => '2026-06-15',
    ]);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => '2026-07-01',
    ]);

    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/my?date_from=2026-06-01&date_to=2026-06-30");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── Team Attendance (Supervisor View) ──

test('supervisor can view team attendance', function () {
    $tenant = createTenant();
    $dept = Department::factory()->create(['tenant_id' => $tenant->id]);
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $teamMember = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id]);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $teamMember->id,
        'date' => now()->format('Y-m-d'),
    ]);

    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/team");

    $response->assertOk();
});

test('employee cannot view team attendance', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/team")
        ->assertForbidden();
});

// ── All Attendance (HR View) ──

test('hr admin can view all attendance', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    AttendanceRecord::factory()->count(5)->create([
        'tenant_id' => $tenant->id,
        'employee_id' => Employee::factory()->create(['tenant_id' => $tenant->id])->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance");

    $response->assertOk()
        ->assertJsonCount(5, 'data');
});

test('employee cannot view all attendance', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance")
        ->assertForbidden();
});

test('all attendance filters by status', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $emp = Employee::factory()->create(['tenant_id' => $tenant->id]);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $emp->id,
        'status' => AttendanceStatus::LATE,
    ]);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $emp->id,
        'status' => AttendanceStatus::PRESENT,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance?filter[status]=late");

    $response->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── Today's Summary ──

test('hr admin can view today summary', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $emp = Employee::factory()->create(['tenant_id' => $tenant->id]);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $emp->id,
        'date' => now()->format('Y-m-d'),
        'status' => AttendanceStatus::PRESENT,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/today");

    $response->assertOk()
        ->assertJsonStructure(['date', 'total_employees', 'present', 'absent', 'late', 'early_leave', 'on_leave']);
});

// ── Show Single Record ──

test('can view single attendance record', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
    ]);

    test()->actingAs($user);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/{$record->public_id}");

    $response->assertOk()
        ->assertJsonPath('public_id', $record->public_id)
        ->assertJsonMissingPath('id');
});

// ── Tenant Isolation ──

test('attendance records are isolated per tenant', function () {
    $tenant1 = createTenant(['subdomain' => 'alpha']);
    $tenant2 = createTenant(['subdomain' => 'beta']);

    $emp1 = Employee::factory()->create(['tenant_id' => $tenant1->id]);
    $emp2 = Employee::factory()->create(['tenant_id' => $tenant2->id]);

    AttendanceRecord::factory()->count(3)->create(['tenant_id' => $tenant1->id, 'employee_id' => $emp1->id]);
    AttendanceRecord::factory()->count(2)->create(['tenant_id' => $tenant2->id, 'employee_id' => $emp2->id]);

    $user1 = createUser(['role' => UserRole::HR_ADMIN], $tenant1);
    test()->actingAs($user1);

    $response = test()->getJson("http://alpha.ethr.test/api/v1/attendance");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

// ── Requires Authentication ──

test('attendance requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->getJson("http://authtest.ethr.test/api/v1/attendance/my")
        ->assertUnauthorized();
});

// ── Audit Logging ──

test('manual attendance entry is audit logged', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $engine = app(AttendanceEngine::class);
    $result = $engine->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $tenant->id,
        source: AttendanceSource::MANUAL,
        type: 'check_in',
        idempotencyKey: 'manual-audit-001',
    ));

    $this->assertDatabaseHas('audit_log', [
        'action' => 'attendance.manual_entry',
        'auditable_type' => AttendanceRecord::class,
        'auditable_id' => $result->record->id,
    ]);
});

// ── Never Expose Numeric ID ──

test('attendance record never exposes numeric id', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-in", [
        'idempotency_key' => 'noid-001',
    ]);

    $response->assertStatus(201)
        ->assertJsonMissingPath('id')
        ->assertJsonMissingPath('tenant_id')
        ->assertJsonMissingPath('employee_id');
});
