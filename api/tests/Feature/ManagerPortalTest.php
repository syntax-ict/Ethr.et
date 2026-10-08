<?php

declare(strict_types=1);

use App\Enums\CorrectionStatus;
use App\Enums\LeaveStatus;
use App\Enums\ProfileUpdateStatus;
use App\Enums\UserRole;
use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ProfileUpdateRequest;

// ── Manager Dashboard ──

test('supervisor can access manager dashboard', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    test()->actingAs($user);

    Employee::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/manager");

    $response->assertOk()
        ->assertJsonStructure([
            'team_attendance',
            'pending_approvals',
            'team_on_leave',
            'team_size',
        ]);
    expect($response->json('team_size'))->toBe(3);
});

test('employee cannot access manager dashboard', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/manager")
        ->assertForbidden();
});

test('the dashboard counts exactly what the approvals queue lists', function () {
    // It counted pending leave from direct reports only: an HR admin with no
    // reports saw "All caught up!" while the queue had items, and pending
    // corrections and profile changes were never counted (audit N63).
    $tenant = createTenant();
    $hr = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::HR_ADMIN, 'employee_id' => $hr->id], $tenant));

    $someone = Employee::factory()->create(['tenant_id' => $tenant->id]);
    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $someone->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'status' => LeaveStatus::PENDING,
    ]);
    ProfileUpdateRequest::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $someone->id,
        'field_name' => 'phone',
        'old_value' => '+251911000000',
        'new_value' => '+251911111111',
        'status' => ProfileUpdateStatus::PENDING,
    ]);

    $queue = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/pending")->assertOk();
    $counts = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/manager")
        ->assertOk()
        ->json('pending_approvals');

    expect($queue->json('total'))->toBe(2)
        ->and($counts['total'])->toBe($queue->json('total'))
        ->and($counts['leave'])->toBe(1)
        ->and($counts['profile_update'])->toBe(1);
});

// ── Approval Center ──

test('supervisor can view pending approvals', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    test()->actingAs($user);

    $subordinate = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
    ]);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'leave_type_id' => $leaveType->id,
        'status' => LeaveStatus::PENDING,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/pending");

    $response->assertOk();
    expect($response->json('total'))->toBe(1);
    expect($response->json('items.0.type'))->toBe('leave');
});

test('pending approvals only shows team requests', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    test()->actingAs($user);

    $otherEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $leaveType->id,
        'status' => LeaveStatus::PENDING,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/pending");

    $response->assertOk();
    expect($response->json('total'))->toBe(0);
});

// ── Batch Approval ──

test('supervisor can batch approve leave requests', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    test()->actingAs($user);

    $subordinate = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
    ]);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'pending_days' => 3,
    ]);

    $lr1 = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'leave_type_id' => $leaveType->id,
        'status' => LeaveStatus::PENDING,
        'days' => 3,
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/batch", [
        'actions' => [
            ['type' => 'leave', 'public_id' => $lr1->public_id, 'action' => 'approve'],
        ],
    ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('approved');

    $lr1->refresh();
    expect($lr1->status)->toBe(LeaveStatus::APPROVED);
});

test('supervisor can batch reject leave requests', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    test()->actingAs($user);

    $subordinate = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
    ]);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'pending_days' => 2,
    ]);

    $lr1 = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'leave_type_id' => $leaveType->id,
        'status' => LeaveStatus::PENDING,
        'days' => 2,
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/batch", [
        'actions' => [
            ['type' => 'leave', 'public_id' => $lr1->public_id, 'action' => 'reject', 'reason' => 'Insufficient staffing'],
        ],
    ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('rejected');

    $lr1->refresh();
    expect($lr1->status)->toBe(LeaveStatus::REJECTED);
    expect($lr1->rejected_reason)->toBe('Insufficient staffing');
});

test('supervisor can batch approve attendance corrections', function () {
    // Regression test: processCorrectionAction() used to compare the
    // enum-cast `status` column against the raw string 'pending' and write
    // to reviewed_by/reviewed_at/review_notes columns that don't exist on
    // attendance_corrections — every call returned "Not found or not
    // pending" (or threw MassAssignmentException outside production),
    // and no test exercised this path.
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    test()->actingAs($user);

    $subordinate = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
    ]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
    ]);

    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'attendance_record_id' => $record->id,
        'status' => CorrectionStatus::PENDING,
        'proposed_check_in' => now()->setTime(8, 0),
        'proposed_check_out' => now()->setTime(17, 0),
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/batch", [
        'actions' => [
            ['type' => 'correction', 'public_id' => $correction->public_id, 'action' => 'approve'],
        ],
    ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('approved');

    $correction->refresh();
    expect($correction->status)->toBe(CorrectionStatus::APPROVED);
    expect($correction->approval_chain)->toHaveCount(1);
    expect($correction->approval_chain[0]['user_id'])->toBe($user->id);
    expect($correction->approval_chain[0]['action'])->toBe('approved');

    // Approving a correction must actually correct the attendance record,
    // matching AttendanceCorrectionController::approve()'s behavior.
    $record->refresh();
    expect($record->check_in->format('H:i'))->toBe('08:00');
    expect($record->check_out->format('H:i'))->toBe('17:00');
    expect($record->metadata['is_corrected'] ?? false)->toBeTrue();
});

test('supervisor can batch reject attendance corrections', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    test()->actingAs($user);

    $subordinate = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
    ]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
    ]);
    $originalCheckIn = $record->check_in;

    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'attendance_record_id' => $record->id,
        'status' => CorrectionStatus::PENDING,
    ]);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/batch", [
        'actions' => [
            ['type' => 'correction', 'public_id' => $correction->public_id, 'action' => 'reject', 'reason' => 'Insufficient evidence'],
        ],
    ]);

    $response->assertOk();
    expect($response->json('results.0.status'))->toBe('rejected');

    $correction->refresh();
    expect($correction->status)->toBe(CorrectionStatus::REJECTED);
    expect($correction->approval_chain[0]['reason'])->toBe('Insufficient evidence');

    // Rejection must not touch the attendance record.
    $record->refresh();
    expect($record->check_in->equalTo($originalCheckIn))->toBeTrue();
});

test('batch correction approval is audit logged', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    test()->actingAs($user);

    $subordinate = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
    ]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
    ]);

    $correction = AttendanceCorrection::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'attendance_record_id' => $record->id,
        'status' => CorrectionStatus::PENDING,
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/batch", [
        'actions' => [
            ['type' => 'correction', 'public_id' => $correction->public_id, 'action' => 'approve'],
        ],
    ])->assertOk();

    $this->assertDatabaseHas('audit_log', [
        'action' => 'correction.approved',
    ]);
});

test('employee cannot use approval center', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/pending")
        ->assertForbidden();
});

test('batch approval is audit logged', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    test()->actingAs($user);

    $subordinate = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
    ]);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'pending_days' => 1,
    ]);

    $lr = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'leave_type_id' => $leaveType->id,
        'status' => LeaveStatus::PENDING,
        'days' => 1,
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/approvals/batch", [
        'actions' => [
            ['type' => 'leave', 'public_id' => $lr->public_id, 'action' => 'approve'],
        ],
    ])->assertOk();

    $this->assertDatabaseHas('audit_log', [
        'action' => 'leave.approved',
    ]);
});

// ── Auth ──

test('approvals require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);
    test()->getJson('http://authtest.ethr.test/api/v1/approvals/pending')
        ->assertUnauthorized();
});
