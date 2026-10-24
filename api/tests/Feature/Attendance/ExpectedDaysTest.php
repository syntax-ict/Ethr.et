<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\ExpectedDay;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Services\Attendance\ExpectedDays;
use Carbon\Carbon;

/*
 * Which days count as absences. Before ExpectedDays, three views each decided
 * for themselves, and every one counted approved leave, public holidays and
 * the organisation's rest days as absent; `GET /attendance/today` also counted
 * everyone who had left. These pin the one rule now shared.
 *
 * The week used throughout: Monday 2026-10-12 to Sunday 2026-10-18, with
 * "now" on Friday 2026-10-16 in Addis Ababa.
 */

beforeEach(function () {
    test()->travelTo(Carbon::parse('2026-10-16 10:00', 'Africa/Addis_Ababa'));
});

function edWeek(Employee $employee): array
{
    $days = app(ExpectedDays::class)->forEmployee($employee, Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'));

    return array_map(fn (ExpectedDay $kind): string => $kind->value, $days);
}

function edLeaveType(Tenant $tenant): int
{
    return LeaveType::query()->where('tenant_id', $tenant->id)->value('id')
        ?? LeaveType::factory()->create(['tenant_id' => $tenant->id])->id;
}

function edUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

// ── The rule ──

test('with no shift, the organisation\'s working week decides rest days', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);

    expect(edWeek($employee))->toBe([
        '2026-10-12' => 'workday',
        '2026-10-13' => 'workday',
        '2026-10-14' => 'workday',
        '2026-10-15' => 'workday',
        '2026-10-16' => 'workday',
        '2026-10-17' => 'rest_day',
        '2026-10-18' => 'rest_day',
    ]);

    // A six-day week makes Saturday a workday. Carbon's isWeekend() never could.
    $tenant->update(['settings' => [...(array) $tenant->settings, 'working_days' => [1, 2, 3, 4, 5, 6]]]);

    expect(edWeek($employee->fresh())['2026-10-17'])->toBe('workday');
});

test('the person\'s shift outranks the organisation\'s working week', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id, 'working_days' => '2,3,4,5,6']);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
        'effective_from' => '2026-10-01',
    ]);

    $week = edWeek($employee);

    expect($week['2026-10-12'])->toBe('rest_day')
        ->and($week['2026-10-17'])->toBe('workday')
        ->and($week['2026-10-18'])->toBe('rest_day');
});

test('a holiday is not a workday, and a branch holiday only for that branch', function () {
    $tenant = createTenant();
    $north = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $south = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $inNorth = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $north->id, 'hire_date' => '2025-01-01']);
    $inSouth = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $south->id, 'hire_date' => '2025-01-01']);

    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null, 'date' => '2026-10-13', 'is_active' => true]);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $north->id, 'date' => '2026-10-14', 'is_active' => true]);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null, 'date' => '2026-10-15', 'is_active' => false]);

    expect(edWeek($inNorth))->toMatchArray(['2026-10-13' => 'holiday', '2026-10-14' => 'holiday', '2026-10-15' => 'workday'])
        ->and(edWeek($inSouth))->toMatchArray(['2026-10-13' => 'holiday', '2026-10-14' => 'workday', '2026-10-15' => 'workday']);
});

test('approved leave covers its workdays, and a weekend inside it stays a rest day', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);

    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => edLeaveType($tenant),
        'start_date' => '2026-10-15',
        'end_date' => '2026-10-19',
        'days' => 3,
    ]);
    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => edLeaveType($tenant),
        'start_date' => '2026-10-12',
        'end_date' => '2026-10-12',
        'days' => 1,
    ]);

    expect(edWeek($employee))->toMatchArray([
        '2026-10-12' => 'workday',
        '2026-10-15' => 'on_leave',
        '2026-10-16' => 'on_leave',
        '2026-10-17' => 'rest_day',
    ]);
});

test('days outside employment are not expected, and nor is a leaver with no date on file', function () {
    $tenant = createTenant();
    $joined = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2026-10-14']);
    $left = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'hire_date' => '2025-01-01',
        'termination_date' => '2026-10-13',
        'status' => EmployeeStatus::RESIGNED,
    ]);
    $undated = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'status' => EmployeeStatus::TERMINATED]);
    $suspended = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'status' => EmployeeStatus::SUSPENDED]);

    expect(edWeek($joined))->toMatchArray(['2026-10-13' => 'not_employed', '2026-10-14' => 'workday'])
        ->and(edWeek($left))->toMatchArray(['2026-10-13' => 'workday', '2026-10-14' => 'not_employed'])
        ->and(array_unique(edWeek($undated)))->toBe(['2026-10-12' => 'not_employed'])
        ->and(array_unique(edWeek($suspended)))->toBe(['2026-10-12' => 'not_employed']);
});

test('the whole-organisation answer for a day agrees with each person\'s own', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    $sixDay = Shift::factory()->create(['tenant_id' => $tenant->id, 'working_days' => '1,2,3,4,5,6']);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $sixDay->id,
        'assignable_type' => Department::class,
        'assignable_id' => $department->id,
        'effective_from' => '2026-10-01',
    ]);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'date' => '2026-10-17', 'is_active' => true]);

    $people = collect([
        Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']),
        Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'department_id' => $department->id]),
        Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'department_id' => $department->id, 'branch_id' => $branch->id]),
    ]);

    $saturday = app(ExpectedDays::class)->onDate($people, Carbon::parse('2026-10-17'));

    expect(array_values(array_map(fn (ExpectedDay $kind): string => $kind->value, $saturday)))->toBe(['rest_day', 'workday', 'holiday']);

    foreach ($people as $person) {
        expect($saturday[$person->id]->value)->toBe(edWeek($person)['2026-10-17']);
    }
});

// ── The views ──

test('today\'s absent count leaves out leavers, people on leave and holidays', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    $present = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    $absent = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    $onLeave = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    $offForHoliday = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'branch_id' => $branch->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'status' => EmployeeStatus::RESIGNED]);

    AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $present->id, 'date' => '2026-10-16']);
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $onLeave->id,
        'leave_type_id' => edLeaveType($tenant),
        'start_date' => '2026-10-16',
        'end_date' => '2026-10-16',
        'days' => 1,
    ]);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'date' => '2026-10-16', 'is_active' => true]);

    test()->actingAs($hr)->getJson(edUrl($tenant, '/attendance/today'))
        ->assertOk()
        ->assertJsonPath('date', '2026-10-16')
        ->assertJsonPath('total_employees', 4)
        ->assertJsonPath('absent', 1)
        ->assertJsonPath('on_leave', 1);

});

test('nobody is absent on a rest day', function () {
    test()->travelTo(Carbon::parse('2026-10-17 10:00', 'Africa/Addis_Ababa'));
    $tenant = createTenant();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);

    test()->actingAs($hr)->getJson(edUrl($tenant, '/attendance/today'))
        ->assertOk()
        ->assertJsonPath('date', '2026-10-17')
        ->assertJsonPath('absent', 0);
});

test('today is the organisation\'s date, not UTC\'s', function () {
    // 01:00 in Addis Ababa is still 22:00 the day before in UTC.
    test()->travelTo(Carbon::parse('2026-10-16 01:00', 'Africa/Addis_Ababa'));
    $tenant = createTenant();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->actingAs($hr)->getJson(edUrl($tenant, '/attendance/today'))
        ->assertOk()
        ->assertJsonPath('date', '2026-10-16');
});

test('the timeline says why a day without attendance is not an absence', function () {
    $tenant = createTenant();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);

    AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => '2026-10-12']);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null, 'date' => '2026-10-13', 'is_active' => true]);
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => edLeaveType($tenant),
        'start_date' => '2026-10-14',
        'end_date' => '2026-10-14',
        'days' => 1,
    ]);

    $response = test()->actingAs($hr)
        ->getJson(edUrl($tenant, "/employees/{$employee->public_id}/attendance/timeline?from=2026-10-12&to=2026-10-20"))
        ->assertOk();

    $statuses = collect($response->json('days'))->pluck('status', 'date')->all();

    expect($statuses)->toMatchArray([
        '2026-10-13' => 'holiday',
        '2026-10-14' => 'on_leave',
        '2026-10-15' => 'absent',
        '2026-10-16' => 'absent',
        '2026-10-17' => 'weekend',
        '2026-10-18' => 'weekend',
        '2026-10-19' => 'upcoming',
        '2026-10-20' => 'upcoming',
    ])
        ->and($response->json('totals.absent'))->toBe(2)
        ->and($response->json('totals.on_leave'))->toBe(1);
});

test('the team view drops leavers and shows a holiday as a holiday', function () {
    $tenant = createTenant();
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $absent = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id, 'hire_date' => '2025-01-01']);
    $holiday = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id, 'hire_date' => '2025-01-01', 'branch_id' => $branch->id]);
    $leaver = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
        'hire_date' => '2025-01-01',
        'termination_date' => '2026-09-30',
        'status' => EmployeeStatus::RESIGNED,
    ]);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'date' => '2026-10-16', 'is_active' => true]);

    $response = test()->actingAs($user)->getJson(edUrl($tenant, '/team/attendance/today'))->assertOk();
    $statuses = collect($response->json('employees'))->pluck('status', 'public_id')->all();

    // The team list has no order, and MySQL 8 returns it differently from
    // SQLite and MariaDB, so compare the pairs rather than the sequence.
    expect($statuses)->toHaveCount(2)
        ->toMatchArray([$absent->public_id => 'absent', $holiday->public_id => 'holiday'])
        ->and($statuses)->not->toHaveKey($leaver->public_id)
        ->and($response->json('summary.absent'))->toBe(1);
});

test('the team summary leaves out holidays, rest days and days ahead', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = createUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);
    $member = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id, 'hire_date' => '2025-01-01']);
    AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $member->id, 'date' => '2026-10-12']);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null, 'date' => '2026-10-14', 'is_active' => true]);

    $data = collect(test()->actingAs($user)->getJson(edUrl($tenant, '/team/attendance/summary?period=weekly'))->assertOk()->json('data'))
        ->keyBy('date');

    expect($data->keys()->all())->toBe(['2026-10-12', '2026-10-13', '2026-10-15', '2026-10-16'])
        ->and($data['2026-10-12'])->toMatchArray(['present' => 1, 'absent' => 0, 'rate' => 100])
        ->and($data['2026-10-13'])->toMatchArray(['present' => 0, 'absent' => 1, 'rate' => 0]);
});
