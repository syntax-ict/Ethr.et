<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use App\Services\Leave\LeaveDayCalculator;
use Carbon\Carbon;

// ── Leave Type CRUD ──

test('hr admin can create a leave type', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave-types", [
        'name' => 'Annual Leave',
        'code' => 'annual',
        'default_days' => 20,
        'accrual_type' => 'annual',
        'min_notice_days' => 3,
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('name', 'Annual Leave')
        ->assertJsonPath('code', 'annual')
        ->assertJsonMissingPath('id');

    expect($response->json('default_days'))->toBeNumeric();
    expect((float) $response->json('default_days'))->toBe(20.0);
});

test('employee cannot create leave type', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave-types", [
        'name' => 'Test',
        'code' => 'test',
        'default_days' => 5,
        'accrual_type' => 'annual',
    ])->assertForbidden();
});

test('employee can list leave types', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    LeaveType::factory()->count(3)->sequence(
        ['code' => 'annual'],
        ['code' => 'sick'],
        ['code' => 'personal'],
    )->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave-types");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

test('hr admin can update leave type', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual_upd']);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave-types/{$leaveType->public_id}", [
        'default_days' => 25,
    ]);

    $response->assertOk();
    expect((float) $response->json('default_days'))->toBe(25.0);
});

test('hr admin can delete leave type', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'del_test']);

    test()->deleteJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave-types/{$leaveType->public_id}")
        ->assertNoContent();
});

test('leave type creation is audit logged', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave-types", [
        'name' => 'Audit Type',
        'code' => 'audit_type',
        'default_days' => 5,
        'accrual_type' => 'annual',
    ])->assertStatus(201);

    $this->assertDatabaseHas('audit_log', [
        'action' => 'leave_type.created',
    ]);
});

// ── Leave Request Submission ──

test('employee can submit leave request', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'code' => 'annual_req',
        'default_days' => 20,
        'min_notice_days' => 0,
    ]);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
    ]);

    $start = now()->addDays(1)->startOfDay();
    while ($start->isWeekend()) {
        $start->addDay();
    }
    $end = $start->copy()->addDays(4);
    while ($end->isWeekend()) {
        $end->addDay();
    }

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
        'leave_type_public_id' => $leaveType->public_id,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
        'reason' => 'Family vacation',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('status', 'pending')
        ->assertJsonMissingPath('id');
});

test('leave request checks insufficient balance', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'code' => 'low_bal',
        'default_days' => 2,
        'min_notice_days' => 0,
    ]);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 2,
    ]);

    $start = now()->addDay()->startOfDay();
    while ($start->isWeekend()) {
        $start->addDay();
    }
    $end = $start->copy()->addWeeks(2);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
        'leave_type_public_id' => $leaveType->public_id,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
    ])->assertStatus(422)
        ->assertJsonPath('title', 'Insufficient Leave Balance');
});

test('leave request detects overlap', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'code' => 'overlap_test',
        'default_days' => 30,
        'min_notice_days' => 0,
    ]);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 30,
    ]);

    $start = now()->addDays(1)->startOfDay();
    while ($start->isWeekend()) {
        $start->addDay();
    }
    $end = $start->copy()->addDays(2);

    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'start_date' => $start,
        'end_date' => $end,
        'days' => 3,
        'status' => LeaveStatus::PENDING,
    ]);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
        'leave_type_public_id' => $leaveType->public_id,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
    ])->assertStatus(422)
        ->assertJsonPath('title', 'Overlapping Leave Request');
});

test('maternity leave restricted to female employees', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'gender' => 'male',
    ]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->maternity()->create([
        'tenant_id' => $tenant->id,
        'code' => 'maternity_test',
        'min_notice_days' => 0,
    ]);

    $start = now()->addDay();
    while ($start->isWeekend()) {
        $start->addDay();
    }

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
        'leave_type_public_id' => $leaveType->public_id,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $start->copy()->addDays(5)->format('Y-m-d'),
    ])->assertStatus(422)
        ->assertJsonPath('title', 'Leave Type Restricted');
});

test('maternity leave spanning 120 calendar days across four months is approved and deducted correctly', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'gender' => 'female',
    ]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    $leaveType = LeaveType::factory()->maternity()->create([
        'tenant_id' => $tenant->id,
        'code' => 'maternity_test',
        'min_notice_days' => 0,
    ]);

    // 120 consecutive calendar days starting a month out (must be in the
    // future for min_notice_days), guaranteed to span multiple months.
    $start = now()->addDays(35)->startOfDay();
    $end = $start->copy()->addDays(119);
    expect((int) $start->diffInDays($end) + 1)->toBe(120);
    expect($start->month)->not->toBe($end->month);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => $start->year,
        'entitled_days' => 120,
        'used_days' => 0,
    ]);

    test()->actingAs($user);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
        'leave_type_public_id' => $leaveType->public_id,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
    ]);

    $response->assertStatus(201);

    // LeaveDayCalculator counts working days (excludes weekends/holidays), so
    // a 120-calendar-day span pins to fewer than 120 "days" here — this is
    // the system's current, uniformly-applied behavior for every leave type.
    // Ethiopian law (Labour Proclamation 1156/2019 Art. 88) grants maternity
    // leave as 120 *consecutive* days, which reads as calendar days, not
    // working days — worth verifying against the actual proclamation text
    // and reconsidering whether maternity/one-time leave types should count
    // calendar days instead. Not changed here; this test pins current
    // behavior so a future change is a deliberate, visible diff.
    $days = (float) $response->json('days');
    expect($days)->toBeGreaterThan(0)->toBeLessThan(120);

    $leaveRequest = LeaveRequest::where('employee_id', $employee->id)->firstOrFail();

    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}/approve")
        ->assertOk();

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->first();

    expect((float) $balance->used_days)->toBe($days);
});

test('min notice period enforced', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'code' => 'notice_test',
        'min_notice_days' => 7,
    ]);

    $start = now()->addDays(2);
    while ($start->isWeekend()) {
        $start->addDay();
    }

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
        'leave_type_public_id' => $leaveType->public_id,
        'start_date' => $start->format('Y-m-d'),
        'end_date' => $start->copy()->addDays(2)->format('Y-m-d'),
    ])->assertStatus(422)
        ->assertJsonPath('title', 'Insufficient Notice');
});

// ── Holiday-Aware Day Calculation ──

test('leave day calculator skips weekends', function () {
    $calculator = app(LeaveDayCalculator::class);
    $tenant = createTenant();

    $monday = Carbon::parse('next monday');
    $friday = $monday->copy()->addDays(4);

    $days = $calculator->calculateDays($monday, $friday, $tenant->id);

    expect($days)->toBe(5.0);
});

test('leave day calculator skips holidays', function () {
    $tenant = createTenant();
    $calculator = app(LeaveDayCalculator::class);

    $monday = Carbon::parse('next monday');

    Holiday::factory()->create([
        'tenant_id' => $tenant->id,
        'date' => $monday->copy()->addDay()->format('Y-m-d'),
        'is_active' => true,
    ]);

    $friday = $monday->copy()->addDays(4);
    $days = $calculator->calculateDays($monday, $friday, $tenant->id);

    expect($days)->toBe(4.0);
});

// ── Approval Workflow ──

test('supervisor can approve leave request', function () {
    $tenant = createTenant();
    $supervisorEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $supervisor = actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisorEmployee->id], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisorEmployee->id]);
    $leaveType = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'code' => 'approve_test',
    ]);

    $balance = LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'pending_days' => 3,
    ]);

    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'days' => 3,
        'status' => LeaveStatus::PENDING,
    ]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}/approve");

    $response->assertOk()
        ->assertJsonPath('status', 'approved');

    $balance->refresh();
    expect((float) $balance->used_days)->toBe(3.0);
    expect((float) $balance->pending_days)->toBe(0.0);
});

test('employee cannot approve leave request', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'no_approve']);

    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}/approve")
        ->assertForbidden();
});

test('cannot approve non-pending leave request', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'already_appr']);

    $leaveRequest = LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}/approve")
        ->assertStatus(422);
});

// ── Rejection ──

test('supervisor can reject leave with reason', function () {
    $tenant = createTenant();
    $supervisorEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisorEmployee->id], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisorEmployee->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'reject_test']);

    $balance = LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'pending_days' => 3,
    ]);

    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'days' => 3,
        'status' => LeaveStatus::PENDING,
    ]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}/reject", [
        'reason' => 'Team is understaffed that week',
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('rejected_reason', 'Team is understaffed that week');

    $balance->refresh();
    expect((float) $balance->pending_days)->toBe(0.0);
});

test('rejection requires reason', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'rej_reason']);

    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}/reject", [])
        ->assertStatus(422);
});

// ── Cancellation ──

test('employee can cancel own pending request', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'cancel_test']);

    $balance = LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'pending_days' => 2,
    ]);

    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'days' => 2,
        'status' => LeaveStatus::PENDING,
    ]);

    $response = test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}/cancel");

    $response->assertOk()
        ->assertJsonPath('status', 'cancelled');

    $balance->refresh();
    expect((float) $balance->pending_days)->toBe(0.0);
});

test('employee cannot cancel another employees request', function () {
    $tenant = createTenant();
    $employee1 = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $employee2 = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee1->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'other_cancel']);

    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee2->id,
        'leave_type_id' => $leaveType->id,
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}/cancel")
        ->assertStatus(403);
});

// ── Balance ──

test('employee can view own balance', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'bal_view']);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'used_days' => 5,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/balance");

    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect((float) $data[0]['remaining_days'])->toBe(15.0);
});

test('hr admin can view employee balance', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'hr_bal']);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/balance/{$employee->public_id}");

    $response->assertOk();
});

// ── Balance Service ──

test('balance service calculates remaining correctly', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'calc_test']);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'used_days' => 5,
        'carried_days' => 3,
        'pending_days' => 2,
    ]);

    $service = app(LeaveBalanceService::class);
    $remaining = $service->calculateBalance($employee, $leaveType, now()->year);

    expect($remaining)->toBe(16.0);
});

test('monthly accrual adds correct amount', function () {
    Carbon::setTestNow(Carbon::create(2026, 1, 15));

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);

    $leaveType = LeaveType::factory()->monthly()->create([
        'tenant_id' => $tenant->id,
        'code' => 'monthly_accrual',
        'default_days' => 12,
    ]);

    $service = app(LeaveBalanceService::class);
    $accrued = $service->accrueMonthly($tenant->id);

    expect($accrued)->toBe(1);

    $balance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->first();
    expect((float) $balance->entitled_days)->toBe(1.0);
});

test('carry forward respects max carry days', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $leaveType = LeaveType::factory()->withCarryForward(5.0)->create([
        'tenant_id' => $tenant->id,
        'code' => 'carry_fwd',
    ]);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => 2025,
        'entitled_days' => 20,
        'used_days' => 12,
    ]);

    $service = app(LeaveBalanceService::class);
    $carried = $service->carryForward($tenant->id, 2025, 2026);

    expect($carried)->toBe(1);

    $newBalance = LeaveBalance::where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->first();

    expect((float) $newBalance->carried_days)->toBe(5.0);
});

// ── My Leaves ──

test('employee can view own leave requests', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'my_leaves']);

    LeaveRequest::factory()->count(3)->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/my");

    $response->assertOk()
        ->assertJsonCount(3, 'data');
});

// ── Team Leaves ──

test('supervisor can view team leave requests', function () {
    $tenant = createTenant();
    $supervisorEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $supervisor = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisorEmployee->id], $tenant);
    test()->actingAs($supervisor);

    $subordinate = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisorEmployee->id,
    ]);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'team_view']);

    LeaveRequest::factory()->count(2)->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $subordinate->id,
        'leave_type_id' => $leaveType->id,
    ]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/team");

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

test('employee cannot view team leaves', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/team")
        ->assertForbidden();
});

// ── Audit Logging ──

test('leave approval is audit logged', function () {
    $tenant = createTenant();
    $supervisorEmployee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisorEmployee->id], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisorEmployee->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'audit_appr']);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => now()->year,
        'entitled_days' => 20,
        'pending_days' => 3,
    ]);

    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'days' => 3,
    ]);

    test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}/approve")
        ->assertOk();

    $this->assertDatabaseHas('audit_log', [
        'action' => 'leave.approved',
    ]);
});

// ── Tenant Isolation ──

test('leave types are isolated per tenant', function () {
    $tenant1 = createTenant(['subdomain' => 'alpha']);
    $tenant2 = createTenant(['subdomain' => 'beta']);

    LeaveType::factory()->create(['tenant_id' => $tenant1->id, 'code' => 'iso_a']);
    LeaveType::factory()->create(['tenant_id' => $tenant1->id, 'code' => 'iso_b']);
    LeaveType::factory()->create(['tenant_id' => $tenant2->id, 'code' => 'iso_c']);

    $employee = Employee::factory()->create(['tenant_id' => $tenant1->id]);
    $user1 = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant1);
    test()->actingAs($user1);

    $response = test()->getJson('http://alpha.ethr.test/api/v1/leave-types');

    $response->assertOk()
        ->assertJsonCount(2, 'data');
});

// ── Authentication ──

test('leave request requires authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->postJson('http://authtest.ethr.test/api/v1/leave/request', [])
        ->assertUnauthorized();
});

test('leave types require authentication', function () {
    $tenant = createTenant(['subdomain' => 'authtest']);

    test()->getJson('http://authtest.ethr.test/api/v1/leave-types')
        ->assertUnauthorized();
});

// ── Leave Request detail (show) ──

test('employee can view their own leave request', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}")
        ->assertOk()
        ->assertJsonPath('public_id', $leaveRequest->public_id)
        ->assertJsonMissingPath('id');
});

test('employee cannot view another employees leave request', function () {
    $tenant = createTenant();
    $mine = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $other = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $mine->id], $tenant);
    test()->actingAs($user);

    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $other->id,
        'leave_type_id' => $leaveType->id,
    ]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}")
        ->assertStatus(403);
});

test('hr admin can view any leave request', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create(['tenant_id' => $tenant->id]);
    $leaveRequest = LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
    ]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/{$leaveRequest->public_id}")
        ->assertOk()
        ->assertJsonPath('public_id', $leaveRequest->public_id);
});
