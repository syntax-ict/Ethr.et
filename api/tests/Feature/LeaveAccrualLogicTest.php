<?php

declare(strict_types=1);

use App\Enums\AccrualType;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\Leave\LeaveBalanceService;
use Carbon\Carbon;

// ── Monthly accrual: no rounding loss over 12 months ──

test('monthly accrual of 16 days yields exactly 16.0 after 12 months', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    $leaveType = LeaveType::factory()->monthly()->create([
        'tenant_id' => $tenant->id,
        'default_days' => 16,
    ]);

    $service = new LeaveBalanceService;

    for ($month = 1; $month <= 12; $month++) {
        Carbon::setTestNow(Carbon::create(2026, $month, 1));
        $service->accrueMonthly($tenant->id);
    }

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->first();

    expect((float) $balance->entitled_days)->toBe(16.0);
});

test('monthly accrual of 20 days yields exactly 20.0 after 12 months', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    $leaveType = LeaveType::factory()->monthly()->create([
        'tenant_id' => $tenant->id,
        'default_days' => 20,
    ]);

    $service = new LeaveBalanceService;

    for ($month = 1; $month <= 12; $month++) {
        Carbon::setTestNow(Carbon::create(2026, $month, 1));
        $service->accrueMonthly($tenant->id);
    }

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->first();

    expect((float) $balance->entitled_days)->toBe(20.0);
});

// ── Monthly seeding: balance starts at zero ──

test('monthly accrual type seeds entitled_days at zero not full annual', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->monthly()->create([
        'tenant_id' => $tenant->id,
        'default_days' => 20,
    ]);

    $service = new LeaveBalanceService;
    $balance = $service->getOrCreateBalance($employee, $leaveType, 2026);

    expect((float) $balance->entitled_days)->toBe(0.0);
});

test('annual accrual type seeds entitled_days at full annual amount', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'default_days' => 20,
        'accrual_type' => AccrualType::ANNUAL,
    ]);

    $service = new LeaveBalanceService;
    $balance = $service->getOrCreateBalance($employee, $leaveType, 2026);

    expect((float) $balance->entitled_days)->toBe(20.0);
});

// ── Cumulative target: running twice in same month is idempotent ──

test('running accrueMonthly twice in same month does not double-accrue', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    $leaveType = LeaveType::factory()->monthly()->create([
        'tenant_id' => $tenant->id,
        'default_days' => 12,
    ]);

    Carbon::setTestNow(Carbon::create(2026, 3, 1));
    $service = new LeaveBalanceService;

    $service->accrueMonthly($tenant->id);
    $service->accrueMonthly($tenant->id);

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->first();

    // 12 * 3/12 = 3.0
    expect((float) $balance->entitled_days)->toBe(3.0);
});

// ── After one month, monthly type has only one month's worth ──

test('after one month accrual on 20-day type balance is not 21.7', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    $leaveType = LeaveType::factory()->monthly()->create([
        'tenant_id' => $tenant->id,
        'default_days' => 20,
    ]);

    Carbon::setTestNow(Carbon::create(2026, 1, 1));
    $service = new LeaveBalanceService;
    $service->accrueMonthly($tenant->id);

    $balance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->first();

    // 20 * 1/12 = 1.7 (rounded to 1 decimal), NOT 21.7
    expect((float) $balance->entitled_days)->toBe(1.7);
});

// ── Gender restriction respected ──

test('accrueMonthly skips employees of wrong gender', function () {
    $tenant = createTenant();
    $maleEmployee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    $leaveType = LeaveType::factory()->monthly()->create([
        'tenant_id' => $tenant->id,
        'default_days' => 10,
        'gender_restriction' => 'female',
    ]);

    Carbon::setTestNow(Carbon::create(2026, 1, 1));
    $service = new LeaveBalanceService;
    $count = $service->accrueMonthly($tenant->id);

    expect($count)->toBe(0);
    expect(LeaveBalance::where('employee_id', $maleEmployee->id)->count())->toBe(0);
});

// ── Carry-forward respects max_carry_days ──

test('carry forward caps at max_carry_days', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->withCarryForward(5.0)->create([
        'tenant_id' => $tenant->id,
        'default_days' => 20,
    ]);

    LeaveBalance::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => 2025,
        'entitled_days' => 20,
        'used_days' => 8,
    ]);

    $service = new LeaveBalanceService;
    $service->carryForward($tenant->id, 2025, 2026);

    $newBalance = LeaveBalance::query()
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->first();

    // Remaining was 12, cap is 5
    expect((float) $newBalance->carried_days)->toBe(5.0);
});
