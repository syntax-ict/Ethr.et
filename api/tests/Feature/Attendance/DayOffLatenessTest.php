<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceEngine;
use Carbon\Carbon;

/*
 * Rule AR-MIN-06 (docs/ATTENDANCE-RULES.md): late and early minutes are zero
 * on a day that is not a working day for the person, a rest day by their shift
 * or a public holiday of their branch. Work on such a day is paid as rest-day
 * or holiday overtime, so it must not also be a lateness to deduct.
 *
 * Oct 2026, Addis Ababa (UTC+3): Mon 12, Tue 13 (a branch holiday in some
 * tests), Wed 14, Sat 17. The shift is 08:30-17:30, Monday to Friday, ten
 * minutes' grace.
 */
const DAY_OFF_ZONE = 'Africa/Addis_Ababa';

afterEach(function () {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Employee, 2: Branch, 3: Shift} */
function dayOffSetup(bool $lateness = false): array
{
    $tenant = createTenant(['timezone' => DAY_OFF_ZONE]);
    AttendanceSetting::create([
        ...AttendanceSetting::defaults(),
        'tenant_id' => $tenant->id,
        ...($lateness ? ['lateness_deduction_enabled' => true, 'lateness_deduction_basis' => 'Work rules, clause 12'] : []),
    ]);
    $branch = Branch::factory()->create([
        'tenant_id' => $tenant->id,
        'latitude' => 9.0108,
        'longitude' => 38.7613,
        'geofence_radius_meters' => 150,
    ]);
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '08:30',
        'end_time' => '17:30',
        'crosses_midnight' => false,
        'grace_minutes' => 10,
        'early_departure_minutes' => 15,
        'working_days' => '1,2,3,4,5',
    ]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
        'employee_code' => 'DOF-1',
        'hire_date' => '2024-01-01',
        'salary_cents' => 500_000,
    ]);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
        'effective_from' => '2026-10-01',
    ]);

    return [$tenant, $employee, $branch, $shift];
}

function dayOffUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/{$path}";
}

function dayOffAddis(string $day, string $time): Carbon
{
    return Carbon::parse("{$day} {$time}", DAY_OFF_ZONE)->utc();
}

function dayOffHoliday(Tenant $tenant, Branch $branch, string $day): void
{
    Holiday::factory()->create([
        'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
        'date' => $day, 'recurring' => false,
    ]);
}

function dayOffPunch(Tenant $tenant, string $punch, string $key)
{
    return test()->postJson(dayOffUrl($tenant, "attendance/mobile/{$punch}"), [
        'idempotency_key' => $key,
        'latitude' => 9.0108,
        'longitude' => 38.7613,
    ]);
}

/** A stored `late` record, as the engine wrote them before the fix. */
function dayOffLateRecord(Tenant $tenant, Employee $employee, Shift $shift, string $day, string $in = '09:00', string $out = '13:00'): AttendanceRecord
{
    return AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => $day,
        'status' => AttendanceStatus::LATE,
        'check_in' => dayOffAddis($day, $in),
        'check_out' => dayOffAddis($day, $out),
        'metadata' => null,
    ]);
}

/** @return array{late: int, early: int, missed: int} */
function dayOffMinutes(AttendanceRecord $record): array
{
    $engine = app(AttendanceEngine::class);
    $record = $record->fresh(['shift']);

    return [
        'late' => $engine->lateMinutes($record, DAY_OFF_ZONE),
        'early' => $engine->earlyMinutes($record, DAY_OFF_ZONE),
        'missed' => $engine->missedMinutes($record, DAY_OFF_ZONE),
    ];
}

it('rates a Saturday punch present, not late and not early', function () {
    Carbon::setTestNow(dayOffAddis('2026-10-17', '09:00'));
    [$tenant, $employee] = dayOffSetup();
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    dayOffPunch($tenant, 'check-in', 'dof-sat-in')
        ->assertCreated()
        ->assertJsonPath('status', 'present')
        ->assertJsonPath('outcome.code', 'checked_in')
        ->assertJsonPath('outcome.minutes', 0);

    Carbon::setTestNow(dayOffAddis('2026-10-17', '13:00'));
    dayOffPunch($tenant, 'check-out', 'dof-sat-out')
        ->assertOk()
        ->assertJsonPath('status', 'present')
        ->assertJsonPath('outcome.code', 'checked_out');

    $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
    expect($record->status)->toBe(AttendanceStatus::PRESENT)
        ->and(dayOffMinutes($record))->toBe(['late' => 0, 'early' => 0, 'missed' => 0]);
});

it('rates a branch-holiday punch present, not late and not early', function () {
    Carbon::setTestNow(dayOffAddis('2026-10-13', '09:00'));
    [$tenant, $employee, $branch] = dayOffSetup();
    dayOffHoliday($tenant, $branch, '2026-10-13');
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    dayOffPunch($tenant, 'check-in', 'dof-hol-in')
        ->assertCreated()
        ->assertJsonPath('status', 'present')
        ->assertJsonPath('outcome.code', 'checked_in');

    Carbon::setTestNow(dayOffAddis('2026-10-13', '13:00'));
    dayOffPunch($tenant, 'check-out', 'dof-hol-out')
        ->assertOk()
        ->assertJsonPath('status', 'present')
        ->assertJsonPath('outcome.code', 'checked_out');

    $record = AttendanceRecord::where('employee_id', $employee->id)->firstOrFail();
    expect($record->status)->toBe(AttendanceStatus::PRESENT)
        ->and(dayOffMinutes($record))->toBe(['late' => 0, 'early' => 0, 'missed' => 0]);
});

it('reads a record stored late on a Saturday before the fix as no lateness', function () {
    [$tenant, $employee, , $shift] = dayOffSetup();
    $record = dayOffLateRecord($tenant, $employee, $shift, '2026-10-17');

    expect($record->status)->toBe(AttendanceStatus::LATE)
        ->and(dayOffMinutes($record))->toBe(['late' => 0, 'early' => 0, 'missed' => 0]);
});

it('reads a record as no lateness when a holiday is added after the punch', function () {
    [$tenant, $employee, $branch, $shift] = dayOffSetup();
    $record = dayOffLateRecord($tenant, $employee, $shift, '2026-10-13');

    expect(dayOffMinutes($record)['late'])->toBe(30);

    dayOffHoliday($tenant, $branch, '2026-10-13');

    expect(dayOffMinutes($record))->toBe(['late' => 0, 'early' => 0, 'missed' => 0]);
});

it('does not list a day-off record as a late arrival, and still lists a weekday one', function () {
    Carbon::setTestNow(dayOffAddis('2026-10-30', '10:00'));
    [$tenant, $employee, , $shift] = dayOffSetup(lateness: true);
    dayOffLateRecord($tenant, $employee, $shift, '2026-10-17');
    dayOffLateRecord($tenant, $employee, $shift, '2026-10-14');
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $body = test()->getJson(dayOffUrl($tenant, 'payroll/reconciliation?period_start=2026-10-01&period_end=2026-10-31'))
        ->assertOk()
        ->json();

    $listed = collect($body['employees'])->flatMap(fn ($e) => $e['exceptions'])
        ->filter(fn ($e) => in_array($e['type'], ['late_arrival', 'early_leave'], true))
        ->keyBy('date');

    expect($listed->keys()->all())->toBe(['2026-10-14'])
        ->and($listed['2026-10-14']['type'])->toBe('late_arrival')
        ->and($listed['2026-10-14']['minutes'])->toBe(300);
});

it('refuses a lateness decision for a day-off record and accepts one for a weekday', function () {
    Carbon::setTestNow(dayOffAddis('2026-10-30', '10:00'));
    [$tenant, $employee, , $shift] = dayOffSetup(lateness: true);
    $saturday = dayOffLateRecord($tenant, $employee, $shift, '2026-10-17');
    $wednesday = dayOffLateRecord($tenant, $employee, $shift, '2026-10-14');
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson(dayOffUrl($tenant, 'attendance/corrections'), [
        'kind' => 'lateness',
        'attendance_record_public_id' => $saturday->public_id,
        'deduct_minutes' => 10,
        'reason' => 'Late without notice.',
    ])->assertStatus(422)
        ->assertJsonValidationErrors('attendance_record_public_id')
        ->assertJsonPath('errors.attendance_record_public_id.0', __('correction.lateness_not_late'));

    test()->postJson(dayOffUrl($tenant, 'attendance/corrections'), [
        'kind' => 'lateness',
        'attendance_record_public_id' => $wednesday->public_id,
        'deduct_minutes' => 10,
        'reason' => 'Late without notice.',
    ])->assertCreated();
});

it('still rates a weekday 08:46 check-in late by 16 minutes', function () {
    Carbon::setTestNow(dayOffAddis('2026-10-14', '08:46'));
    [$tenant, $employee] = dayOffSetup();
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    dayOffPunch($tenant, 'check-in', 'dof-wed-in')
        ->assertCreated()
        ->assertJsonPath('status', 'late')
        ->assertJsonPath('outcome.code', 'checked_in_late')
        ->assertJsonPath('outcome.minutes', 16);
});

// Pins what happens today, not what is right: how a day of approved leave is
// rated is undecided (AR-ABS-08), so it is deliberately left as it was.
it('pins current behaviour: a punch on a day of approved leave is rated against the shift (AR-ABS-08)', function () {
    Carbon::setTestNow(dayOffAddis('2026-10-14', '09:00'));
    [$tenant, $employee] = dayOffSetup();
    LeaveRequest::factory()->approved()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'leave_type_id' => LeaveType::factory()->create(['tenant_id' => $tenant->id])->id,
        'start_date' => '2026-10-14',
        'end_date' => '2026-10-14',
        'days' => 1,
    ]);
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    dayOffPunch($tenant, 'check-in', 'dof-leave-in')
        ->assertCreated()
        ->assertJsonPath('status', 'late')
        ->assertJsonPath('outcome.code', 'checked_in_late')
        ->assertJsonPath('outcome.minutes', 30);
});
