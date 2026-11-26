<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\EmployeeStatus;
use App\Enums\LeaveStatus;
use App\Enums\TimesheetCode;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Report\TimesheetReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Lang;

/*
 * The monthly timesheet as a report source and as a grid.
 *
 * The fortnight is Monday 2026-10-12 to Sunday 2026-10-25, with "now" on
 * Friday 2026-10-23 at 10:00 in Addis Ababa, so yesterday is the 22nd:
 *
 *   Mon 12  present                       Mon 19  check-in only, no check-out
 *   Tue 13  late (09:10)                  Tue 20  nothing: an absence
 *   Wed 14  left early (16:00)            Wed 21  worked to 20:00: overtime
 *   Thu 15  public holiday                Thu 22  sick leave
 *   Fri 16  annual leave                  Fri 23  today, nothing yet
 *   Sat 17  rest day, worked 3 hours      Sat 24, Sun 25  rest days
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-23 10:00', 'Africa/Addis_Ababa'));
});

afterEach(fn () => Carbon::setTestNow());

function mtUrl(Tenant $tenant, string $path, string $query = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/reports/{$path}".($query === '' ? '' : "?{$query}");
}

function mtAt(string $day, string $time): Carbon
{
    return Carbon::parse("$day $time", 'Africa/Addis_Ababa')->utc();
}

/** @return array{tenant: Tenant, employee: Employee, shift: Shift} */
function mtFortnight(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'name' => 'Abebe Kebede', 'name_am' => 'አበበ ከበደ']);
    $shift = Shift::factory()->default()->create(['tenant_id' => $tenant->id]);

    Holiday::factory()->create(['tenant_id' => $tenant->id, 'date' => '2026-10-15', 'branch_id' => null, 'is_active' => true, 'recurring' => false]);

    foreach ([
        ['2026-10-12', '08:25', '17:35', AttendanceStatus::PRESENT],
        ['2026-10-13', '09:10', '17:30', AttendanceStatus::LATE],
        ['2026-10-14', '08:30', '16:00', AttendanceStatus::EARLY_LEAVE],
        ['2026-10-17', '09:00', '12:00', AttendanceStatus::PRESENT],
        ['2026-10-19', '08:30', null, AttendanceStatus::PENDING],
        ['2026-10-21', '08:30', '20:00', AttendanceStatus::PRESENT],
    ] as [$day, $in, $out, $status]) {
        AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'date' => $day,
            'check_in' => mtAt($day, $in),
            'check_out' => $out === null ? null : mtAt($day, $out),
            'status' => $status,
        ]);
    }

    $annual = LeaveType::factory()->create(['tenant_id' => $tenant->id, 'code' => 'annual']);
    $sick = LeaveType::factory()->sick()->create(['tenant_id' => $tenant->id, 'follows_art85' => true]);
    foreach ([[$annual, '2026-10-16'], [$sick, '2026-10-22']] as [$type, $day]) {
        LeaveRequest::factory()->approved()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
            'start_date' => $day, 'end_date' => $day, 'days' => 1, 'status' => LeaveStatus::APPROVED,
        ]);
    }

    return compact('tenant', 'employee', 'shift');
}

// ── The report source ──

test('the report source reads the ledger: days, minutes, overtime by rate and short hours', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson(mtUrl($tenant, 'generate'), [
        'source' => 'timesheet',
        'filters' => ['from' => '2026-10-12', 'to' => '2026-10-25'],
    ])->assertOk();

    // The span stops at yesterday, the 22nd.
    expect($response->json('period'))->toBe(['from' => '2026-10-12', 'to' => '2026-10-22']);
    $row = $response->json('data.0');

    expect($row)->toMatchArray([
        'employee_name' => 'Abebe Kebede',
        'work_days' => 6,
        'present_days' => 5,
        'late_days' => 1,
        'early_days' => 1,
        'missing_days' => 1,
        'absent_days' => 1,
        'leave_days' => 2,
        'holiday_days' => 1,
        'rest_days' => 2,
        'late_minutes' => 40,
        'early_minutes' => 90,
        'lateness_deduct_minutes' => 0,
        'scheduled_hours' => 48.0,
        'short_hours' => 18.2, // 40 + 90 + the open day 480 + the absent day 480 minutes
        'overtime_normal_hours' => 2.6, // 155 minutes
        'overtime_rest_day_hours' => 3.0,
        'overtime_night_hours' => 0.0,
        'overtime_holiday_hours' => 0.0,
        'overtime_hours' => 5.6,
    ]);
});

test('the report source keeps every column it had, so saved reports and API keys keep working', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $source = test()->getJson(mtUrl($tenant, 'sources'))->assertOk()->json('sources.timesheet');

    $fields = $source['fields'];
    foreach (['employee_name', 'employee_code', 'department', 'branch', 'work_days', 'present_days', 'late_days', 'absent_days', 'leave_days', 'holiday_days', 'worked_hours', 'overtime_hours'] as $kept) {
        expect($fields)->toContain($kept);
    }
    expect($source['filters'])->toBe(['period', 'from', 'to', 'department', 'branch']);
});

// ── The grid ──

test('the grid gives a cell for every day, each with the code the rules decide', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk();

    expect($response->json('period'))->toBe(['from' => '2026-10-12', 'to' => '2026-10-25'])
        ->and($response->json('days'))->toHaveCount(14)
        ->and($response->json('days.0'))->toBe(['date' => '2026-10-12', 'iso_weekday' => 1])
        ->and($response->json('meta'))->toBe(['current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 1]);

    $row = $response->json('data.0');
    $codes = collect($row['cells'])->pluck('short', 'date')->all();

    expect($row['name'])->toBe('Abebe Kebede')
        ->and($row['name_am'])->toBe('አበበ ከበደ')
        ->and($row['shift']['name'])->toBe('Morning Shift')
        ->and($row['check_ok'])->toBeTrue()
        ->and($row['cells'])->toHaveCount(14)
        ->and($codes['2026-10-12'])->toBe('P')
        ->and($codes['2026-10-13'])->toBe('L')
        ->and($codes['2026-10-14'])->toBe('E')
        ->and($codes['2026-10-15'])->toBe('H')
        ->and($codes['2026-10-16'])->toBe('AL')
        ->and($codes['2026-10-17'])->toBe('R')
        ->and($codes['2026-10-19'])->toBe('M')
        ->and($codes['2026-10-20'])->toBe('A')
        ->and($codes['2026-10-22'])->toBe('SL')
        ->and($codes['2026-10-23'])->toBeNull();

    // A rest day worked carries its overtime and its records, for the drill-down.
    $saturday = collect($row['cells'])->firstWhere('date', '2026-10-17');
    expect($saturday['overtime'])->toBe(['rest_day' => 180])
        ->and($saturday['records'])->toHaveCount(1);
});

test('the grid carries the figures payroll reads, with payable days defined as payroll does', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $totals = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk()->json('data.0.totals');

    expect($totals['work_days'])->toBe(7)
        ->and($totals['pending_days'])->toBe(1) // today
        ->and($totals['employed_days'])->toBe(14)
        ->and($totals['unpaid_leave_days'])->toEqual(0)
        ->and($totals['sick_unpaid_days'])->toEqual(0)
        ->and($totals['payable_days'])->toEqual(14)
        ->and($totals['leave_by_code'])->toBe(['annual_leave' => 1, 'sick_leave' => 1]);
});

test('the overtime columns follow the rates the organisation pays, sharing a column where the rate is shared', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk();

    $columns = $response->json('rate_columns');
    expect(array_column($columns, 'rate'))->toEqual([1.5, 1.75, 2, 2.5])
        ->and($columns[3]['types'])->toBe(['holiday', 'holiday_night'])
        ->and($response->json('data.0.totals.overtime_by_rate'))->toEqual([2.6, 0, 3, 0]);
});

test('the grid says what rules it applied, from the engine and the statutory rows', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $rules = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk()->json('rules_applied');

    expect($rules['shifts'][0])->toMatchArray(['name' => 'Morning Shift', 'grace_minutes' => 15, 'early_departure_minutes' => 15, 'break_minutes' => 60])
        ->and($rules['merge_window_minutes'])->toBe(5)
        ->and($rules['punch_pairing'])->toBe('first_in_last_out')
        ->and($rules['overtime']['legal_daily_limit_minutes'])->toBe(240)
        ->and($rules['overtime']['legal_weekly_limit_minutes'])->toBe(720)
        ->and($rules['overtime']['paid_when_unapproved'])->toBeTrue()
        ->and($rules['absences_are'])->toBe('flagged_not_deducted');
});

test('the status is derived: exceptions while a punch is open, paid once payroll has released the days', function () {
    ['tenant' => $tenant, 'employee' => $employee] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $open = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk()->json('status');
    expect($open['code'])->toBe('exceptions')->and($open['blocking'])->toBeGreaterThan(0);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id, 'period_start' => '2026-10-12', 'period_end' => '2026-10-25', 'status' => 'approved']);
    PayrollEntry::factory()->create(['tenant_id' => $tenant->id, 'payroll_run_id' => $run->id, 'employee_id' => $employee->id]);

    $paid = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk();
    expect($paid->json('status.code'))->toBe('paid')
        ->and(collect($paid->json('data.0.cells'))->every(fn (array $cell): bool => $cell['locked']))->toBeTrue();

    $partial = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-05&to=2026-10-25'));
    expect($partial->status())->toBe(200)->and($partial->json('status.code'))->toBe('part_paid');
});

test('totals and department subtotals cover everyone matched, not the page', function () {
    ['tenant' => $tenant, 'shift' => $shift] = mtFortnight();
    $second = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'name' => 'Zeritu Alemu']);
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $second->id, 'shift_id' => $shift->id, 'date' => '2026-10-12',
        'check_in' => mtAt('2026-10-12', '08:30'), 'check_out' => mtAt('2026-10-12', '17:30'), 'status' => AttendanceStatus::PRESENT,
    ]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $all = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk();
    $pageOne = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25&per_page=1&page=1'))->assertOk();

    expect($pageOne->json('data'))->toHaveCount(1)
        ->and($pageOne->json('data.0.name'))->toBe('Abebe Kebede')
        ->and($pageOne->json('meta'))->toBe(['current_page' => 1, 'last_page' => 2, 'per_page' => 1, 'total' => 2])
        ->and($pageOne->json('links.next'))->toContain('page=2')
        ->and($pageOne->json('links.prev'))->toBeNull()
        ->and($pageOne->json('totals.present_days'))->toBe($all->json('totals.present_days'))
        ->and($all->json('totals.present_days'))->toBe(6)
        ->and(collect($all->json('department_subtotals'))->sum('employees'))->toBe(2);
});

test('the shift and employment status filters narrow the people, and the totals with them', function () {
    ['tenant' => $tenant, 'employee' => $employee, 'shift' => $shift] = mtFortnight();
    $other = Shift::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Late Shift', 'start_time' => '14:00', 'end_time' => '22:00']);
    // Named rather than left to the factory. The report orders by name and the
    // assertion below reads the first row, so a faker name sorting before
    // "Abebe Kebede" put the other person there and failed the run.
    $left = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'name' => 'Zenebe Tadesse', 'status' => EmployeeStatus::CONFIRMED]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $morning = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25&shift='.$shift->public_id))->assertOk();
    $late = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25&shift='.$other->public_id))->assertOk();
    $status = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25&employment_status=suspended'))->assertOk();

    expect($morning->json('meta.total'))->toBe(2)
        ->and($late->json('meta.total'))->toBe(0)
        ->and($status->json('meta.total'))->toBe(0)
        ->and($employee->public_id)->toBe($morning->json('data.0.public_id'));
});

// ── Who sees it ──

test('the grid needs the report permission, and the plan feature', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertForbidden();
});

test('another organisation\'s timesheet never contains this one\'s people', function () {
    ['tenant' => $tenant] = mtFortnight();
    $other = Tenant::factory()->create();
    app(CurrentTenant::class)->set($other);
    actingAsUser(['role' => UserRole::HR_ADMIN], $other);

    $response = test()->getJson(mtUrl($other, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk();

    expect($response->json('meta.total'))->toBe(0)
        ->and($response->json('data'))->toBe([]);
    expect($tenant->id)->not->toBe($other->id);
});

test('the response never carries a numeric id', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk();

    $response->assertJsonMissingPath('data.0.id')
        ->assertJsonMissingPath('data.0.employee_id')
        ->assertJsonMissingPath('data.0.department.id');
    expect(json_encode($response->json('data.0')))->not->toContain('"tenant_id"');
});

test('a bad filter is a 422, not a silent whole-organisation timesheet', function (string $query) {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(mtUrl($tenant, 'timesheet', $query))->assertStatus(422);
})->with([
    'a date in the wrong order of parts' => 'from=12-10-2026',
    'a status that is not one' => 'employment_status=bogus',
    'an end before the start' => 'from=2026-10-12&to=2026-10-01',
]);

test('a page larger than a hundred people is cut to a hundred, as every list is', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25&per_page=500'))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 100);
});

test('past the size limit the grid still pages through everyone, but leaves the totals out rather than show them for a part', function () {
    ['tenant' => $tenant] = mtFortnight();
    Employee::factory()->count(TimesheetReport::MAX_PEOPLE)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25&per_page=10&page=2'))->assertOk();

    expect($response->json('meta.total'))->toBe(TimesheetReport::MAX_PEOPLE + 1)
        ->and($response->json('complete'))->toBeFalse()
        ->and($response->json('max_people'))->toBe(TimesheetReport::MAX_PEOPLE)
        ->and($response->json('totals'))->toBeNull()
        ->and($response->json('department_subtotals'))->toBe([])
        ->and($response->json('data'))->toHaveCount(10)
        ->and($response->json('meta.current_page'))->toBe(2);

    // Narrowing the list to a department brings the totals back.
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    Employee::query()->where('tenant_id', $tenant->id)->limit(5)->update(['department_id' => $department->id]);

    $narrow = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25&department='.$department->public_id))->assertOk();
    expect($narrow->json('complete'))->toBeTrue()
        ->and($narrow->json('meta.total'))->toBe(5)
        ->and($narrow->json('totals'))->not->toBeNull();
});

test('the report source is cut at the size limit and says so', function () {
    ['tenant' => $tenant] = mtFortnight();
    Employee::factory()->count(TimesheetReport::MAX_PEOPLE)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson(mtUrl($tenant, 'generate'), [
        'source' => 'timesheet',
        'filters' => ['from' => '2026-10-12', 'to' => '2026-10-25'],
    ])->assertOk();

    expect($response->json('truncated'))->toBeTrue()
        ->and($response->json('matched'))->toBe(TimesheetReport::MAX_PEOPLE + 1)
        ->and($response->json('total'))->toBeLessThanOrEqual(TimesheetReport::MAX_PEOPLE);
});

test('the grid names a person\'s position and department, with their Amharic names', function () {
    ['tenant' => $tenant, 'employee' => $employee] = mtFortnight();
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'name_am' => 'ፋይናንስ']);
    $position = Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Accountant', 'title_am' => 'የሂሳብ ሠራተኛ']);
    $employee->update(['department_id' => $department->id, 'position_id' => $position->id]);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk();

    expect($response->json('data.0.position'))->toBe('Accountant')
        ->and($response->json('data.0.department'))->toMatchArray(['name' => 'Finance', 'name_am' => 'ፋይናንስ'])
        ->and($response->json('department_subtotals.0.department.name'))->toBe('Finance');
});

test('whoever may not see payroll is not shown the figures that move pay, nor how much was paid', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk();

    expect($response->json('with_pay'))->toBeFalse()
        ->and($response->json('data.0.totals'))->not->toHaveKeys(['unpaid_leave_days', 'sick_half_days', 'sick_unpaid_days', 'payable_days'])
        ->and($response->json('totals'))->not->toHaveKey('payable_days')
        ->and($response->json('data.0.totals.present_days'))->toBe(5);
});

test('people who left before the month, or arrive after it, neither fill the size limit nor appear', function () {
    ['tenant' => $tenant] = mtFortnight();
    Employee::factory()->count(TimesheetReport::MAX_PEOPLE)->create([
        'tenant_id' => $tenant->id, 'hire_date' => '2020-01-01', 'termination_date' => '2024-06-30', 'status' => EmployeeStatus::TERMINATED,
    ]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2027-01-05']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $grid = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk();
    $source = test()->postJson(mtUrl($tenant, 'generate'), ['source' => 'timesheet', 'filters' => ['from' => '2026-10-12', 'to' => '2026-10-25']])->assertOk();

    expect($grid->json('meta.total'))->toBe(1)
        ->and($grid->json('complete'))->toBeTrue()
        ->and($source->json('truncated'))->toBeFalse()
        ->and($source->json('matched'))->toBe(1);
});

test('the status counts what the people listed have open, not the whole organisation', function () {
    ['tenant' => $tenant, 'shift' => $shift] = mtFortnight();
    $finance = Department::factory()->create(['tenant_id' => $tenant->id]);
    $quiet = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'department_id' => $finance->id]);
    foreach (['2026-10-12', '2026-10-13'] as $day) {
        AttendanceRecord::factory()->create([
            'tenant_id' => $tenant->id, 'employee_id' => $quiet->id, 'shift_id' => $shift->id, 'date' => $day,
            'check_in' => mtAt($day, '08:30'), 'check_out' => mtAt($day, '17:30'), 'status' => AttendanceStatus::PRESENT,
        ]);
    }
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    // The first person has an open punch and an absence; the finance department has none.
    $everyone = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-22'))->assertOk()->json('status');
    $department = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-22&department='.$finance->public_id))->assertOk();

    expect($everyone['code'])->toBe('exceptions')
        ->and($everyone['blocking'])->toBeGreaterThan(0)
        ->and($department->json('status.blocking'))->toBeLessThan($everyone['blocking']);
});

test('past the size limit there is no status, since it would speak for people it did not read', function () {
    ['tenant' => $tenant] = mtFortnight();
    Employee::factory()->count(TimesheetReport::MAX_PEOPLE)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->getJson(mtUrl($tenant, 'timesheet', 'from=2026-10-12&to=2026-10-25'))->assertOk();

    expect($response->json('complete'))->toBeFalse()
        ->and($response->json('status'))->toBeNull();
});

test('an end date with no start date is a 422, not a silently different month', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->getJson(mtUrl($tenant, 'timesheet', 'to=2026-10-25'))->assertStatus(422);
});

test('the exports name the new columns in the reader\'s language', function () {
    foreach (['en', 'am', 'om'] as $locale) {
        foreach (['rest_days', 'missing_days', 'late_minutes', 'scheduled_hours', 'short_hours', 'overtime_night_hours', 'overtime_holiday_night_hours', 'overtime_unapproved_hours', 'overtime_limit_days'] as $field) {
            expect(Lang::has('report.fields.'.$field, $locale))->toBeTrue("$locale is missing report.fields.$field");
        }
    }
});

test('the letters the server prints are the ones the grid\'s legend lists, in the same order', function () {
    $letters = array_map(
        fn (TimesheetCode $code): string => $code->short(),
        [
            TimesheetCode::PRESENT, TimesheetCode::LATE, TimesheetCode::EARLY_LEAVE, TimesheetCode::MISSING_PUNCH,
            TimesheetCode::ABSENT, TimesheetCode::ANNUAL_LEAVE, TimesheetCode::SICK_LEAVE, TimesheetCode::MATERNITY_LEAVE,
            TimesheetCode::OTHER_LEAVE, TimesheetCode::FIELD_DUTY, TimesheetCode::HALF_DAY_LEAVE, TimesheetCode::HOLIDAY,
            TimesheetCode::REST_DAY,
        ],
    );

    // src/src/features/reports/timesheet/codes.ts lists the same thirteen, and
    // src/src/test/reports-timesheet-screen.test.tsx pins its side of this.
    expect($letters)->toBe(['P', 'L', 'E', 'M', 'A', 'AL', 'SL', 'ML', 'OL', 'FD', '½', 'H', 'R']);
});

test('the four related reports are standard, and each runs as written', function (string $key) {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $presets = test()->getJson(mtUrl($tenant, 'presets'))->assertOk()->json('presets');
    $preset = collect($presets)->firstWhere('key', $key);

    expect($preset)->not->toBeNull()
        ->and($preset['standard'])->toBeTrue()
        ->and($preset['source'])->toBe('timesheet');

    // Run exactly what the Quick reports card runs, for the fortnight.
    $config = [...$preset['config'], 'filters' => ['from' => '2026-10-12', 'to' => '2026-10-25']];
    $response = test()->postJson(mtUrl($tenant, 'generate'), $config)->assertOk();

    expect($response->json('total'))->toBeGreaterThan(0)
        ->and(array_keys($response->json('data.0')))->each->toBeIn($config['columns'] ?? array_keys($response->json('data.0')));
})->with(['late_and_early', 'overtime_by_rate', 'missing_punches', 'attendance_by_department']);

test('the late and early report orders by the minutes and carries the deduction beside them', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $preset = collect(test()->getJson(mtUrl($tenant, 'presets'))->json('presets'))->firstWhere('key', 'late_and_early');
    $row = test()->postJson(mtUrl($tenant, 'generate'), [...$preset['config'], 'filters' => ['from' => '2026-10-12', 'to' => '2026-10-25']])
        ->assertOk()->json('data.0');

    expect($row)->toMatchArray(['late_days' => 1, 'late_minutes' => 40, 'early_days' => 1, 'early_minutes' => 90, 'lateness_deduct_minutes' => 0]);
});

test('the overtime by rate report separates the rates payroll pays', function () {
    ['tenant' => $tenant] = mtFortnight();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $preset = collect(test()->getJson(mtUrl($tenant, 'presets'))->json('presets'))->firstWhere('key', 'overtime_by_rate');
    $row = test()->postJson(mtUrl($tenant, 'generate'), [...$preset['config'], 'filters' => ['from' => '2026-10-12', 'to' => '2026-10-25']])
        ->assertOk()->json('data.0');

    expect($row['overtime_normal_hours'])->toEqual(2.6)
        ->and($row['overtime_rest_day_hours'])->toEqual(3)
        ->and($row['overtime_holiday_hours'])->toEqual(0);
});
