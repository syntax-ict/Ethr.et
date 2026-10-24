<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Services\Report\ReportEngine;
use Carbon\Carbon;

/*
 * The absences report source and the manager dashboard's absent count, both
 * on ExpectedDays (blueprint RP6). Attendance rows exist only for days
 * someone punched, so neither could see an absence before.
 *
 * "Now" is Friday 2026-10-16 in Addis Ababa; Monday 2026-10-12 starts the week.
 */

beforeEach(function () {
    test()->travelTo(Carbon::parse('2026-10-16 10:00', 'Africa/Addis_Ababa'));
});

function arLeave(Tenant $tenant, Employee $employee, string $date): void
{
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'start_date' => $date,
        'end_date' => $date,
        'days' => 1,
    ]);
}

function arAttend(Tenant $tenant, Employee $employee, array $dates): void
{
    foreach ($dates as $date) {
        AttendanceRecord::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => $date]);
    }
}

test('the absences report lists missed workdays only, and never today', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $missing = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01', 'name' => 'Abebe Kebede']);
    $present = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'hire_date' => '2025-01-01',
        'termination_date' => '2026-10-01',
        'status' => EmployeeStatus::RESIGNED,
    ]);

    arAttend($tenant, $missing, ['2026-10-12']);
    arAttend($tenant, $present, ['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15']);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => null, 'date' => '2026-10-13', 'is_active' => true]);
    arLeave($tenant, $missing, '2026-10-14');

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", [
        'source' => 'absences',
        'filters' => ['from' => '2026-10-12', 'to' => '2026-10-16'],
    ])->assertOk();

    expect($response->json('data'))->toBe([[
        'employee_name' => 'Abebe Kebede',
        'employee_code' => $missing->employee_code,
        'department' => $missing->department?->name ?? '',
        'branch' => $missing->branch?->name ?? '',
        'date' => '2026-10-15',
    ]])
        ->and($response->json('truncated'))->toBeFalse();

});

test('an organisation that records no attendance gets no absence rows', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/reports/generate", ['source' => 'absences'])
        ->assertOk()
        ->assertJsonPath('total', 0);
});

test('the span is cut to the last 92 days before yesterday, newest first', function () {
    $tenant = createTenant();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'hire_date' => '2025-01-01']);
    arAttend($tenant, $employee, ['2026-10-15']);

    $rows = app(ReportEngine::class)->generate($tenant->id, [
        'source' => 'absences',
        'filters' => ['from' => '2026-01-01', 'to' => '2026-12-31'],
    ], $hr)['data'];

    $dates = array_column($rows, 'date');

    expect($dates[0])->toBe('2026-10-14')
        ->and(end($dates))->toBeGreaterThanOrEqual('2026-07-16')
        ->and(in_array('2026-10-15', $dates, true))->toBeFalse()
        ->and(in_array('2026-10-11', $dates, true))->toBeFalse();
});

test('a department-scoped viewer sees only their department\'s absences', function () {
    $tenant = createTenant();
    [$ours, $theirs] = Department::factory()->count(2)->create(['tenant_id' => $tenant->id])->all();
    $anchor = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $ours->id, 'hire_date' => '2025-01-01']);
    $viewer = createUser(['role' => UserRole::DEPT_ADMIN, 'employee_id' => $anchor->id], $tenant);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $ours->id, 'hire_date' => '2025-01-01']);
    $outsider = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $theirs->id, 'hire_date' => '2025-01-01']);
    arAttend($tenant, $anchor, ['2026-10-15']);

    $names = array_column(app(ReportEngine::class)->generate($tenant->id, [
        'source' => 'absences',
        'filters' => ['from' => '2026-10-15', 'to' => '2026-10-15'],
    ], $viewer)['data'], 'employee_name');

    expect($names)->toContain($colleague->name)
        ->not->toContain($outsider->name);
});

test('the manager dashboard counts only missed workdays as absent', function () {
    $tenant = createTenant();
    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    actingAsUser(['role' => UserRole::SUPERVISOR, 'employee_id' => $supervisor->id], $tenant);

    $present = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id, 'hire_date' => '2025-01-01']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id, 'hire_date' => '2025-01-01']);
    $onLeave = Employee::factory()->create(['tenant_id' => $tenant->id, 'supervisor_id' => $supervisor->id, 'hire_date' => '2025-01-01']);
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'supervisor_id' => $supervisor->id,
        'hire_date' => '2025-01-01',
        'termination_date' => '2026-09-30',
        'status' => EmployeeStatus::RESIGNED,
    ]);

    arAttend($tenant, $present, ['2026-10-16']);
    arLeave($tenant, $onLeave, '2026-10-16');

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/dashboard/manager")
        ->assertOk()
        ->assertJsonPath('team_attendance.present', 1)
        ->assertJsonPath('team_attendance.absent', 1);
});
