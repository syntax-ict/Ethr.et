<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\KioskSession;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use Carbon\Carbon;

/**
 * What a day is, said the same way by every way of punching: a public holiday
 * and its name, the weekend, another rest day, approved leave.
 *
 * Mon 12 Oct 2026 is a working day, Wed 14 is this shift's day off, Sat 17 is
 * the weekend. The decision is ExpectedDays', the rule absence already follows.
 */
afterEach(function () {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Employee, 2: Branch} */
function dayContextSetup(string $workingDays = '1,2,3,4,5'): array
{
    $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa']);
    AttendanceSetting::create([...AttendanceSetting::defaults(), 'tenant_id' => $tenant->id]);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'end_time' => '17:30',
        'crosses_midnight' => false,
        'working_days' => $workingDays,
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'employee_code' => 'DAY-1']);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
        'effective_from' => '2026-10-01',
    ]);

    return [$tenant, $employee, $branch];
}

function dayUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function holidayOn(Tenant $tenant, string $date, ?int $branchId = null): Holiday
{
    return Holiday::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branchId,
        'date' => $date,
        'is_active' => true,
        'name' => 'Meskel',
        'name_am' => 'መስቀል',
    ]);
}

// ── GET /attendance/my-shift ────────────────────────────────────────────────

it('names the holiday and says nothing is expected', function () {
    Carbon::setTestNow('2026-10-12 04:00:00');
    [$tenant, $employee] = dayContextSetup();
    holidayOn($tenant, '2026-10-12');
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->getJson(dayUrl($tenant, 'attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('day.kind', 'holiday')
        ->assertJsonPath('day.weekend', false)
        ->assertJsonPath('day.holiday.name', 'Meskel')
        ->assertJsonPath('day.holiday.name_am', 'መስቀል')
        ->assertJsonPath('working_day', false);
});

it('calls Saturday the weekend', function () {
    Carbon::setTestNow('2026-10-17 04:00:00');
    [$tenant, $employee] = dayContextSetup();
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->getJson(dayUrl($tenant, 'attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('day.kind', 'rest_day')
        ->assertJsonPath('day.weekend', true)
        ->assertJsonPath('day.holiday', null)
        ->assertJsonPath('working_day', false);
});

it('calls a weekday off a rest day that is not the weekend', function () {
    Carbon::setTestNow('2026-10-14 04:00:00');
    [$tenant, $employee] = dayContextSetup('1,2,4,5');
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->getJson(dayUrl($tenant, 'attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('day.kind', 'rest_day')
        ->assertJsonPath('day.weekend', false);
});

it('says a working day is one', function () {
    Carbon::setTestNow('2026-10-12 04:00:00');
    [$tenant, $employee] = dayContextSetup();
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->getJson(dayUrl($tenant, 'attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('day.kind', 'workday')
        ->assertJsonPath('day.holiday', null)
        ->assertJsonPath('working_day', true);
});

it('says approved leave', function () {
    Carbon::setTestNow('2026-10-12 04:00:00');
    [$tenant, $employee] = dayContextSetup();
    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'start_date' => '2026-10-12',
        'end_date' => '2026-10-13',
        'status' => LeaveStatus::APPROVED,
    ]);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->getJson(dayUrl($tenant, 'attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('day.kind', 'on_leave')
        ->assertJsonPath('on_leave', true)
        ->assertJsonPath('working_day', false);
});

it('does not call another branch\'s holiday this branch\'s', function () {
    Carbon::setTestNow('2026-10-12 04:00:00');
    [$tenant, $employee] = dayContextSetup();
    holidayOn($tenant, '2026-10-12', Branch::factory()->create(['tenant_id' => $tenant->id])->id);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->getJson(dayUrl($tenant, 'attendance/my-shift'))
        ->assertOk()
        ->assertJsonPath('day.kind', 'workday');
});

// ── Every way of punching carries it ────────────────────────────────────────

it('answers a web check-in with the holiday', function () {
    Carbon::setTestNow('2026-10-12 05:40:00');
    [$tenant, $employee] = dayContextSetup();
    holidayOn($tenant, '2026-10-12');
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->postJson(dayUrl($tenant, 'attendance/check-in'), ['idempotency_key' => 'day-web-1'])
        ->assertCreated()
        ->assertJsonPath('day.kind', 'holiday')
        ->assertJsonPath('day.holiday.name', 'Meskel');
});

it('answers a phone check-in on the weekend', function () {
    Carbon::setTestNow('2026-10-17 05:40:00');
    [$tenant, $employee, $branch] = dayContextSetup();
    $branch->update(['latitude' => 9.0108, 'longitude' => 38.7613, 'geofence_radius_meters' => 150]);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->postJson(dayUrl($tenant, 'attendance/mobile/check-in'), [
        'idempotency_key' => 'day-mobile-1',
        'latitude' => 9.0108,
        'longitude' => 38.7613,
    ])
        ->assertCreated()
        ->assertJsonPath('day.kind', 'rest_day')
        ->assertJsonPath('day.weekend', true);
});

it('answers a kiosk punch with the holiday', function () {
    Carbon::setTestNow('2026-10-12 05:40:00');
    [$tenant, , $branch] = dayContextSetup();
    holidayOn($tenant, '2026-10-12');
    $session = KioskSession::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'status' => 'active']);
    app(CurrentTenant::class)->forget();

    test()->postJson(dayUrl($tenant, 'kiosk/check-in'), [
        'employee_code' => 'DAY-1',
        'type' => 'auto',
        'idempotency_key' => 'day-kiosk-1',
    ], ['X-Kiosk-Token' => $session->token])
        ->assertCreated()
        ->assertJsonPath('day.kind', 'holiday')
        ->assertJsonPath('day.holiday.name', 'Meskel')
        ->assertJsonPath('day.holiday.name_am', 'መስቀል');
});
