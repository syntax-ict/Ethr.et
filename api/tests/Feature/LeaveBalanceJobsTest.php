<?php

declare(strict_types=1);

use App\Jobs\AccrueLeaveBalancesJob;
use App\Jobs\CarryForwardLeaveBalancesJob;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\CurrentTenant;
use App\Services\Leave\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;

/**
 * A queued job carries no request context, so the ambient tenant is whatever
 * the job itself resolves. These tests clear it first to prove the jobs and the
 * service stand on their own rather than inheriting it from the test harness.
 */
function forgetAmbientTenant(): void
{
    app(CurrentTenant::class)->forget();
}

// ── Monthly accrual job ───────────────────────────────────────────────────────

test('the accrual job tops up balances without an ambient tenant', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 1));

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    $leaveType = LeaveType::factory()->monthly()->create([
        'tenant_id' => $tenant->id,
        'default_days' => 24,
    ]);

    forgetAmbientTenant();

    (new AccrueLeaveBalancesJob($tenant->id))
        ->handle(app(LeaveBalanceService::class), app(CurrentTenant::class));

    $balance = LeaveBalance::withoutGlobalScope('tenant')
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->first();

    // Six months into the year: 24 * 6/12.
    expect((float) $balance->entitled_days)->toBe(12.0);
});

test('the accrual job is safe to re-run in the same month', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 1));

    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    $leaveType = LeaveType::factory()->monthly()->create([
        'tenant_id' => $tenant->id,
        'default_days' => 24,
    ]);

    forgetAmbientTenant();

    foreach (range(1, 3) as $ignored) {
        (new AccrueLeaveBalancesJob($tenant->id))
            ->handle(app(LeaveBalanceService::class), app(CurrentTenant::class));
    }

    $balance = LeaveBalance::withoutGlobalScope('tenant')
        ->where('employee_id', $employee->id)
        ->where('leave_type_id', $leaveType->id)
        ->where('year', 2026)
        ->first();

    expect((float) $balance->entitled_days)->toBe(12.0);
});

test('the accrual job only touches its own tenant', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 1));

    $other = createTenant();
    $otherEmployee = Employee::factory()->create(['tenant_id' => $other->id, 'gender' => 'male']);
    $otherType = LeaveType::factory()->monthly()->create([
        'tenant_id' => $other->id,
        'default_days' => 24,
    ]);

    $tenant = createTenant();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    LeaveType::factory()->monthly()->create(['tenant_id' => $tenant->id, 'default_days' => 24]);

    forgetAmbientTenant();

    (new AccrueLeaveBalancesJob($tenant->id))
        ->handle(app(LeaveBalanceService::class), app(CurrentTenant::class));

    $otherBalance = LeaveBalance::withoutGlobalScope('tenant')
        ->where('employee_id', $otherEmployee->id)
        ->where('leave_type_id', $otherType->id)
        ->first();

    expect($otherBalance)->toBeNull();
});

test('a missing tenant makes the accrual job a no-op', function () {
    forgetAmbientTenant();

    (new AccrueLeaveBalancesJob(999_999))
        ->handle(app(LeaveBalanceService::class), app(CurrentTenant::class));

    expect(LeaveBalance::withoutGlobalScope('tenant')->count())->toBe(0);
});

// ── Carry-forward job ─────────────────────────────────────────────────────────

test('the carry-forward job rolls unused days into the next year', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    $leaveType = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'carry_forward' => true,
        'max_carry_days' => 10,
        'is_active' => true,
    ]);

    LeaveBalance::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => 2025,
        'entitled_days' => 20,
        'used_days' => 6,
        'carried_days' => 0,
        'pending_days' => 0,
    ]);

    forgetAmbientTenant();

    (new CarryForwardLeaveBalancesJob($tenant->id, 2025, 2026))
        ->handle(app(LeaveBalanceService::class), app(CurrentTenant::class));

    $carried = LeaveBalance::withoutGlobalScope('tenant')
        ->where('employee_id', $employee->id)
        ->where('year', 2026)
        ->first();

    // 14 days remained but the type caps carry-over at 10.
    expect((float) $carried->carried_days)->toBe(10.0);
});

test('the carry-forward job is safe to re-run', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    $leaveType = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'carry_forward' => true,
        'max_carry_days' => 10,
        'is_active' => true,
    ]);

    LeaveBalance::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => 2025,
        'entitled_days' => 20,
        'used_days' => 18,
        'carried_days' => 0,
        'pending_days' => 0,
    ]);

    forgetAmbientTenant();

    foreach (range(1, 3) as $ignored) {
        (new CarryForwardLeaveBalancesJob($tenant->id, 2025, 2026))
            ->handle(app(LeaveBalanceService::class), app(CurrentTenant::class));
    }

    $carried = LeaveBalance::withoutGlobalScope('tenant')
        ->where('employee_id', $employee->id)
        ->where('year', 2026)
        ->first();

    // Assigned, not accumulated — three runs still yield the 2 unused days.
    expect((float) $carried->carried_days)->toBe(2.0);
});

test('the carry-forward job only touches its own tenant', function () {
    $other = createTenant();
    $otherEmployee = Employee::factory()->create(['tenant_id' => $other->id]);
    $otherType = LeaveType::factory()->create([
        'tenant_id' => $other->id,
        'carry_forward' => true,
        'max_carry_days' => 10,
        'is_active' => true,
    ]);
    LeaveBalance::create([
        'tenant_id' => $other->id,
        'employee_id' => $otherEmployee->id,
        'leave_type_id' => $otherType->id,
        'year' => 2025,
        'entitled_days' => 20,
        'used_days' => 0,
        'carried_days' => 0,
        'pending_days' => 0,
    ]);

    $tenant = createTenant();

    forgetAmbientTenant();

    (new CarryForwardLeaveBalancesJob($tenant->id, 2025, 2026))
        ->handle(app(LeaveBalanceService::class), app(CurrentTenant::class));

    $leaked = LeaveBalance::withoutGlobalScope('tenant')
        ->where('employee_id', $otherEmployee->id)
        ->where('year', 2026)
        ->first();

    expect($leaked)->toBeNull();
});

test('carry-forward skips a balance whose employee was deleted', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $leaveType = LeaveType::factory()->create([
        'tenant_id' => $tenant->id,
        'carry_forward' => true,
        'max_carry_days' => 10,
        'is_active' => true,
    ]);

    LeaveBalance::create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => $leaveType->id,
        'year' => 2025,
        'entitled_days' => 20,
        'used_days' => 0,
        'carried_days' => 0,
        'pending_days' => 0,
    ]);

    $employee->forceDelete();

    forgetAmbientTenant();

    // Must not throw — the balance is simply skipped.
    (new CarryForwardLeaveBalancesJob($tenant->id, 2025, 2026))
        ->handle(app(LeaveBalanceService::class), app(CurrentTenant::class));

    expect(
        LeaveBalance::withoutGlobalScope('tenant')->where('year', 2026)->count()
    )->toBe(0);
});

// ── Schedule registration ─────────────────────────────────────────────────────

test('both leave balance jobs are registered on the scheduler', function () {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event) => $event->description)
        ->filter()
        ->values()
        ->all();

    expect($events)->toContain('accrue-leave-balances');
    expect($events)->toContain('carry-forward-leave-balances');
});
