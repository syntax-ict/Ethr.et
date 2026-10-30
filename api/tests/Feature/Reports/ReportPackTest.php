<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\Report\ReportEngine;
use Carbon\Carbon;

/*
 * The monthly timesheet, the unit filters, the payroll breakdown, readable
 * files and the presets chosen by organisation type (2026-10-29).
 *
 * "Now" is Friday 2026-10-16 in Addis Ababa; Monday 2026-10-12 starts the week.
 */

beforeEach(function () {
    test()->travelTo(Carbon::parse('2026-10-16 10:00', 'Africa/Addis_Ababa'));
});

function rpUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/reports/{$path}";
}

function rpAttend(Tenant $tenant, Employee $employee, string $date, AttendanceStatus $status = AttendanceStatus::PRESENT): void
{
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'date' => $date,
        'check_in' => Carbon::parse("{$date} 08:00", 'Africa/Addis_Ababa'),
        'check_out' => Carbon::parse("{$date} 17:00", 'Africa/Addis_Ababa'),
        'status' => $status,
    ]);
}

// ── Timesheet ──

test('the timesheet counts each employee\'s month by the same rule as absences', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $abebe = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'name' => 'Abebe Kebede']);
    rpAttend($tenant, $abebe, '2026-10-12');
    rpAttend($tenant, $abebe, '2026-10-13', AttendanceStatus::LATE);
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $abebe->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'start_date' => '2026-10-14',
        'end_date' => '2026-10-14',
        'days' => 1,
    ]);

    $response = test()->postJson(rpUrl($tenant, 'generate'), [
        'source' => 'timesheet',
        'filters' => ['month' => '2026-10'],
    ])->assertOk();

    $row = collect($response->json('data'))->firstWhere('employee_name', 'Abebe Kebede');
    // October 1-15 (today is not over): 11 workdays Monday to Friday, one of
    // them on leave, so 10 expected, 2 attended, 8 absent.
    expect($row['work_days'])->toBe(10)
        ->and($row['present_days'])->toBe(2)
        ->and($row['late_days'])->toBe(1)
        ->and($row['leave_days'])->toBe(1)
        ->and($row['absent_days'])->toBe(8)
        ->and((float) $row['worked_hours'])->toBeGreaterThan(0.0);
});

test('a timesheet for a month that has not started yet is empty', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);

    test()->postJson(rpUrl($tenant, 'generate'), ['source' => 'timesheet', 'filters' => ['month' => '2026-11']])
        ->assertOk()
        ->assertJsonPath('total', 0);
});

test('grouping the timesheet sums days and hours per group', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Operations']);
    foreach (['A', 'B'] as $letter) {
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'department_id' => $department->id, 'name' => "Worker {$letter}"]);
        rpAttend($tenant, $employee, '2026-10-12');
    }

    $response = test()->postJson(rpUrl($tenant, 'generate'), [
        'source' => 'timesheet',
        'filters' => ['month' => '2026-10'],
        'group_by' => 'department',
    ])->assertOk();

    expect($response->json('summary.group_sums.Operations.present_days'))->toBe(2)
        ->and($response->json('summary.group_sums.Operations.absent_days'))->toBe(20);
});

// ── Attendance ──

test('the attendance report states worked minutes, the department, and filters by it', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $ops = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Operations']);
    $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
    $inOps = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $ops->id]);
    $inFinance = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $finance->id]);
    rpAttend($tenant, $inOps, '2026-10-12');
    rpAttend($tenant, $inFinance, '2026-10-12', AttendanceStatus::LATE);

    $response = test()->postJson(rpUrl($tenant, 'generate'), [
        'source' => 'attendance',
        'filters' => ['department' => $ops->public_id],
    ])->assertOk();

    // Nine hours on the clock: it read a `worked_minutes` attribute nothing
    // provides, so every row said 0.
    expect($response->json('total'))->toBe(1)
        ->and($response->json('data.0.department'))->toBe('Operations')
        ->and($response->json('data.0.worked_minutes'))->toBe(540);

    test()->postJson(rpUrl($tenant, 'generate'), ['source' => 'attendance', 'filters' => ['status' => 'late']])
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.department', 'Finance');
});

// ── Payroll ──

test('the payroll report leaves out voided runs and filters by period and cost centre', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $project = CostCenter::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Project Alpha']);
    $funded = Employee::factory()->create(['tenant_id' => $tenant->id, 'cost_center_id' => $project->id]);
    $other = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $september = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_label' => 'September 2026', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'status' => 'approved']);
    $voided = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_label' => 'October 2026', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'voided']);
    $october = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_label' => 'October 2026', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'approved']);
    foreach ([$september, $voided, $october] as $run) {
        foreach ([$funded, $other] as $employee) {
            PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id, 'gross_cents' => 1_000_000]);
        }
    }

    $all = test()->postJson(rpUrl($tenant, 'generate'), ['source' => 'payroll', 'group_by' => 'period'])->assertOk();
    // Two employees in each of two live runs; the voided October run counted
    // each October payslip twice.
    expect($all->json('total'))->toBe(4)
        ->and($all->json('summary.group_sums.October 2026.gross_cents'))->toBe(2_000_000);

    $filtered = test()->postJson(rpUrl($tenant, 'generate'), [
        'source' => 'payroll',
        'filters' => ['from' => '2026-10-01', 'to' => '2026-10-31', 'cost_center' => $project->public_id],
    ])->assertOk();

    expect($filtered->json('total'))->toBe(1)
        ->and($filtered->json('data.0.cost_center'))->toBe('Project Alpha')
        ->and($filtered->json('data.0.period'))->toBe('October 2026')
        ->and($filtered->json('data.0'))->toHaveKeys(['overtime_cents', 'allowances_cents', 'employer_pension_cents', 'loan_cents', 'cost_sharing_cents']);
});

// ── Files ──

test('report files carry headings and birr, not API keys and cents', function () {
    $engine = app(ReportEngine::class);

    expect($engine->presentable([['employee_name' => 'Abebe', 'salary_cents' => 1_250_000, 'present_days' => 3]]))
        ->toBe([['Employee' => 'Abebe', 'Salary (ETB)' => '12,500.00', 'Present' => 3]]);

    expect($engine->toCsv([['name' => 'Abebe', 'net_cents' => 990_050]]))
        ->toBe("Name,Net pay (ETB)\r\nAbebe,\"9,900.50\"");
});

// ── Presets ──

test('a government organisation is recommended staffing reports, and everyone gets the standard ones', function () {
    $tenant = createTenant(['type' => 'government']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson(rpUrl($tenant, 'presets'))->assertOk();
    $presets = collect($response->json('presets'))->keyBy('key');

    expect($response->json('organisation.type'))->toBe('government')
        ->and($presets['staffing_by_grade']['recommended'])->toBeTrue()
        ->and($presets['monthly_timesheet']['standard'])->toBeTrue()
        ->and($presets['overtime_by_department']['recommended'])->toBeFalse()
        // Recommended first.
        ->and($response->json('presets.0.recommended'))->toBeTrue()
        // HR were denied payroll.viewAll (audit N95), so no payroll preset.
        ->and($presets->has('payroll_by_period'))->toBeFalse();
});

test('a three-shift workforce is recommended overtime by department', function () {
    $tenant = createTenant([
        'type' => 'general',
        'settings' => ['configuration_profile' => ['workforce_profile' => 'rotating_three_shift']],
    ]);
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $presets = collect(test()->getJson(rpUrl($tenant, 'presets'))->assertOk()->json('presets'))->keyBy('key');

    expect($presets['overtime_by_department']['recommended'])->toBeTrue()
        ->and($presets['staffing_by_grade']['recommended'])->toBeFalse()
        ->and($presets->has('payroll_by_period'))->toBeTrue();
});

test('every preset runs', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $grade = Grade::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'grade_id' => $grade->id]);
    rpAttend($tenant, $employee, '2026-10-12', AttendanceStatus::LATE);

    foreach (test()->getJson(rpUrl($tenant, 'presets'))->json('presets') as $preset) {
        test()->postJson(rpUrl($tenant, 'generate'), $preset['config'])->assertOk();
    }
});

// ── Live review, 2026-10-30 ──

test('a day with two late punches is one late day, never more than the days present', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'name' => 'Abenet']);
    rpAttend($tenant, $employee, '2026-10-12', AttendanceStatus::LATE);
    rpAttend($tenant, $employee, '2026-10-12', AttendanceStatus::LATE);
    rpAttend($tenant, $employee, '2026-10-13', AttendanceStatus::LATE);

    $row = test()->postJson(rpUrl($tenant, 'generate'), ['source' => 'timesheet', 'filters' => ['month' => '2026-10']])
        ->assertOk()->json('data.0');

    expect($row['present_days'])->toBe(2)->and($row['late_days'])->toBe(2);
});

test('the timesheet takes an Ethiopian month as a date range', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    // Meskerem 2019 runs 11 September to 10 October 2026.
    rpAttend($tenant, $employee, '2026-09-11');
    rpAttend($tenant, $employee, '2026-10-09');
    rpAttend($tenant, $employee, '2026-10-12');

    $row = test()->postJson(rpUrl($tenant, 'generate'), [
        'source' => 'timesheet',
        'filters' => ['from' => '2026-09-11', 'to' => '2026-10-10'],
    ])->assertOk()->json('data.0');

    // 11 September to 10 October: 21 weekdays, two of them attended.
    expect($row['present_days'])->toBe(2)->and($row['work_days'])->toBe(21);
});

test('a payroll month finds the run meant for it in either calendar', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $october = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_label' => 'October 2026', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'status' => 'approved']);
    $november = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_label' => 'November 2026', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'status' => 'approved']);
    foreach ([$october, $november] as $run) {
        PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id]);
    }

    // Tikimt 2019 is 11 October to 9 November: the October run's midpoint
    // (16 October) is inside it, the November run's (15 November) is not.
    // By period start it found November.
    test()->postJson(rpUrl($tenant, 'generate'), [
        'source' => 'payroll',
        'filters' => ['from' => '2026-10-11', 'to' => '2026-11-09'],
    ])->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.period', 'October 2026');
});
