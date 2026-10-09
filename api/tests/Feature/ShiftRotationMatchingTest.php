<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\ProcessPayrollJob;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftRotation;
use App\Models\ShiftRotationStep;
use App\Models\Tenant;
use App\Services\Attendance\ShiftMatcher;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * Attendance ignored rotation assignments: the matcher's query required a
 * loadable `shift`, so an employee on a rotation fell through to a
 * department, branch or default shift — or to none — and was never measured
 * against the rotation's day. Payroll's rest-day rule read the shift's
 * `working_days`, which a rotation overrides.
 */

/**
 * A three-day rotation: Morning, Night, rest.
 *
 * @return array{0: ShiftRotation, 1: Shift, 2: Shift}
 */
function threeDayRotation(Tenant $tenant): array
{
    $morning = Shift::factory()->create([
        'tenant_id' => $tenant->id, 'name' => 'Morning',
        // Mon-Fri, so a weekend day on the rotation proves the rotation decides.
        'working_days' => '1,2,3,4,5',
    ]);
    $night = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Night', 'working_days' => '1,2,3,4,5']);
    $rotation = ShiftRotation::factory()->create(['tenant_id' => $tenant->id, 'cycle_days' => 3, 'is_active' => true]);
    ShiftRotationStep::factory()->create([
        'tenant_id' => $tenant->id, 'shift_rotation_id' => $rotation->id, 'day_offset' => 0, 'shift_id' => $morning->id,
    ]);
    ShiftRotationStep::factory()->create([
        'tenant_id' => $tenant->id, 'shift_rotation_id' => $rotation->id, 'day_offset' => 1, 'shift_id' => $night->id,
    ]);

    return [$rotation, $morning, $night];
}

function assignRotation(Tenant $tenant, ShiftRotation $rotation, string $type, int $id, string $anchor): void
{
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => null,
        'shift_rotation_id' => $rotation->id,
        'assignable_type' => $type,
        'assignable_id' => $id,
        'effective_from' => $anchor,
        'effective_to' => null,
        'anchor_date' => $anchor,
    ]);
}

test('an employee on a rotation is matched to the rotation day, cycling', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    [$rotation] = threeDayRotation($tenant);
    assignRotation($tenant, $rotation, Employee::class, $employee->id, '2026-09-05');

    $matcher = app(ShiftMatcher::class);

    expect($matcher->match($employee, Carbon::parse('2026-09-05 08:00'))?->name)->toBe('Morning')
        ->and($matcher->match($employee, Carbon::parse('2026-09-06 08:00'))?->name)->toBe('Night')
        ->and($matcher->match($employee, Carbon::parse('2026-09-08 08:00'))?->name)->toBe('Morning');
});

test('a rotation rest day is the answer, not a fall-through to the default shift', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    Shift::factory()->default()->create(['tenant_id' => $tenant->id, 'name' => 'Default']);
    [$rotation] = threeDayRotation($tenant);
    assignRotation($tenant, $rotation, Employee::class, $employee->id, '2026-09-05');

    expect(app(ShiftMatcher::class)->match($employee, Carbon::parse('2026-09-07 08:00')))->toBeNull();
});

test('a department rotation applies when the employee has no assignment of their own', function () {
    $tenant = createTenant();
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $department->id]);
    [$rotation] = threeDayRotation($tenant);
    assignRotation($tenant, $rotation, Department::class, $department->id, '2026-09-05');

    expect(app(ShiftMatcher::class)->match($employee, Carbon::parse('2026-09-06 08:00'))?->name)->toBe('Night');
});

test("an employee's own fixed shift still outranks a department rotation", function () {
    $tenant = createTenant();
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $department->id]);
    [$rotation] = threeDayRotation($tenant);
    assignRotation($tenant, $rotation, Department::class, $department->id, '2026-09-05');
    $own = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Own']);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id, 'shift_id' => $own->id,
        'assignable_type' => Employee::class, 'assignable_id' => $employee->id,
        'effective_from' => '2026-09-01', 'effective_to' => null,
    ]);

    expect(app(ShiftMatcher::class)->match($employee, Carbon::parse('2026-09-06 08:00'))?->name)->toBe('Own');
});

test('an inactive rotation is not matched', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    [$rotation] = threeDayRotation($tenant);
    $rotation->update(['is_active' => false]);
    assignRotation($tenant, $rotation, Employee::class, $employee->id, '2026-09-05');

    expect(app(ShiftMatcher::class)->match($employee, Carbon::parse('2026-09-05 08:00')))->toBeNull();
});

test("payroll's rest days follow the rotation, not the shift's working days", function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    [$rotation] = threeDayRotation($tenant);
    // 2026-09-05 is a Saturday: Morning's working_days say rest, the rotation says work.
    assignRotation($tenant, $rotation, Employee::class, $employee->id, '2026-09-05');

    $restDays = app(ShiftMatcher::class)->rotationRestDays(
        $employee,
        Carbon::parse('2026-09-04'),
        Carbon::parse('2026-09-08'),
    );

    expect($restDays)->toBe([
        '2026-09-05' => false,
        '2026-09-06' => false,
        '2026-09-07' => true,
        '2026-09-08' => false,
    ]);
});

test('an employee on a fixed shift has no rotation rest days', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id, 'shift_id' => $shift->id,
        'assignable_type' => Employee::class, 'assignable_id' => $employee->id,
        'effective_from' => '2026-09-01', 'effective_to' => null,
    ]);

    expect(app(ShiftMatcher::class)->rotationRestDays($employee, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30')))
        ->toBe([]);
});

/**
 * Payroll through the queued job, the way production runs it: the job had no
 * tenant context, so even a matcher that understood rotations would have
 * found no assignment there and fallen back to the shift's working days.
 */
function rotationPayrollEntry(Tenant $tenant, int $userId, string $date, string $in, string $out): PayrollEntry
{
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => 500000, 'hire_date' => '2020-01-01',
    ]);
    [$rotation, $morning] = threeDayRotation($tenant);
    // Anchored 2026-06-06 (a Saturday): Morning, Night, rest, repeating.
    assignRotation($tenant, $rotation, Employee::class, $employee->id, '2026-06-06');

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => app(ShiftMatcher::class)->match($employee, Carbon::parse("{$date} {$in}"))?->id,
        'date' => $date,
        'check_in' => Carbon::parse("{$date} {$in}"),
        'check_out' => Carbon::parse("{$date} {$out}"),
    ]);

    Queue::fake();
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'idempotency_key' => 'rotation-'.$date,
    ])->assertSuccessful();
    $run = PayrollRun::where('tenant_id', $tenant->id)->firstOrFail();

    // The worker resolves no tenant.
    app(CurrentTenant::class)->forget();
    (new ProcessPayrollJob($run->id, $run->tenant_id))->handle(app(PayrollEngine::class));

    // Read back under the tenant, so the assertion is about what payroll
    // computed rather than about whether the job left a tenant behind.
    app(CurrentTenant::class)->set($tenant);

    return PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
}

function rotationOvertime(PayrollEntry $entry): array
{
    return collect($entry->calculation_log['steps'])->firstWhere('step', 'overtime');
}

test('a Saturday the rotation schedules is an ordinary working day in payroll', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // 2026-06-06: offset 0, Morning (Mon-Fri on its own), worked 08:30-17:30.
    $entry = rotationPayrollEntry($tenant, $user->id, '2026-06-06', '08:30', '17:30');

    expect(rotationOvertime($entry)['overtime_minutes'])->toBe(0);
});

test("a day worked on the rotation's rest day is paid as rest-day overtime", function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // 2026-06-08 is a Monday — a working day for every shift here — but it
    // is offset 2 of the cycle anchored 06-06: the rotation's rest day.
    $entry = rotationPayrollEntry($tenant, $user->id, '2026-06-08', '09:00', '13:00');

    expect(rotationOvertime($entry)['by_type']['rest_day']['minutes'])->toBe(240);
});

test('the queued job pays a fixed shift\'s rest day too', function () {
    // Found with the above: in the worker, the engine's `with('shift')` ran
    // through the tenant scope with no tenant resolved, so every record's
    // shift loaded as null and the rest-day rule fell back to "is it Sunday".
    // A Saturday under a Monday-Friday shift was paid as an ordinary day.
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => 500000, 'hire_date' => '2020-01-01',
    ]);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'working_days' => '1,2,3,4,5']);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => '2026-06-13',
        'check_in' => Carbon::parse('2026-06-13 09:00'),
        'check_out' => Carbon::parse('2026-06-13 13:00'),
    ]);

    Queue::fake();
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'idempotency_key' => 'fixed-rest-day',
    ])->assertSuccessful();
    $run = PayrollRun::where('tenant_id', $tenant->id)->firstOrFail();

    app(CurrentTenant::class)->forget();
    (new ProcessPayrollJob($run->id, $run->tenant_id))->handle(app(PayrollEngine::class));
    app(CurrentTenant::class)->set($tenant);

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();

    expect(rotationOvertime($entry)['by_type']['rest_day']['minutes'])->toBe(240);
});

test('the queued job deducts an active loan', function () {
    // The same missing tenant, and the costliest symptom: LoanService and
    // CostSharingService query through the tenant scope, so in the worker
    // every employee's loan and cost-sharing deduction came back zero.
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => 500000, 'hire_date' => '2020-01-01',
    ]);
    EmployeeLoan::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'monthly_deduction_cents' => 25000,
        'remaining_cents' => 300000,
        'status' => 'active',
    ]);

    Queue::fake();
    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
        'idempotency_key' => 'loan-in-worker',
    ])->assertSuccessful();
    $run = PayrollRun::where('tenant_id', $tenant->id)->firstOrFail();

    app(CurrentTenant::class)->forget();
    (new ProcessPayrollJob($run->id, $run->tenant_id))->handle(app(PayrollEngine::class));
    app(CurrentTenant::class)->set($tenant);

    $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
    $step = collect($entry->calculation_log['steps'])->firstWhere('step', 'loan_deductions');

    expect($step['loan_deduction_cents'])->toBe(25000);
});
