<?php

declare(strict_types=1);

use App\Enums\AttendanceSource;
use App\Enums\AttendanceStatus;
use App\Exceptions\NoOpenCheckIn;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Services\Attendance\AttendanceEngine;
use App\Services\Attendance\AttendanceInput;
use App\Services\Payroll\OvertimeClassifier;
use Carbon\Carbon;

/**
 * Shift times are wall-clock times in the tenant's zone, and the engine reads
 * a punch in that zone before comparing with them.
 *
 * Production, 2026-10-12 (docs/audit/QA-ATTENDANCE-PRODUCTION-2026-10-12.md,
 * A1): on a 17:10 shift in Addis Ababa, a punch at 18:10 Addis — 15:10 UTC —
 * was stored `present`. The engine compared the UTC clock time with the shift's
 * start, read the punch as two hours early, and every report, payroll rule and
 * dashboard built on `status` inherited it. These tests replay that day.
 */
afterEach(function () {
    Carbon::setTestNow();
});

/** QA Audit's shift: 17:10–23:30 Addis time, 10 minutes' grace, 15 for early departure. */
function addisShiftEmployee(): array
{
    $tenant = createTenant();
    expect($tenant->timezone)->toBe('Africa/Addis_Ababa');

    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '17:10',
        'end_time' => '23:30',
        'crosses_midnight' => false,
        'grace_minutes' => 10,
        'early_departure_minutes' => 15,
        'break_minutes' => 0,
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
        'effective_from' => '2026-10-01',
    ]);

    return [$tenant, $employee];
}

function punch(Employee $employee, string $type, string $utc): AttendanceRecord
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

    return app(AttendanceEngine::class)->record(new AttendanceInput(
        employeeId: $employee->id,
        tenantId: $employee->tenant_id,
        source: AttendanceSource::KIOSK,
        type: $type,
        idempotencyKey: $type.'-'.$utc,
    ))->record->fresh();
}

it('marks a punch late by the tenant\'s clock, not UTC', function () {
    [, $employee] = addisShiftEmployee();

    // 18:10 Addis: an hour after the 17:10 start, 50 minutes past the grace.
    $record = punch($employee, 'check_in', '2026-10-12 15:10:00');

    expect($record->status)->toBe(AttendanceStatus::LATE)
        ->and($record->check_in->toIso8601String())->toBe('2026-10-12T15:10:00+00:00')
        ->and($record->date->format('Y-m-d'))->toBe('2026-10-12');
});

it('keeps a punch inside the grace period present', function () {
    [, $employee] = addisShiftEmployee();

    // 17:15 Addis — five minutes after the start, inside the grace. On the UTC
    // clock this read as 14:15 and was "present" for the wrong reason; the
    // case pins that the fix did not simply flip everything to late.
    $record = punch($employee, 'check_in', '2026-10-12 14:15:00');

    expect($record->status)->toBe(AttendanceStatus::PRESENT);
});

it('dates a punch by the tenant\'s day', function () {
    [, $employee] = addisShiftEmployee();

    // 01:00 Addis on 1 August is 22:00 UTC on 31 July.
    $record = punch($employee, 'check_in', '2026-07-31 22:00:00');

    expect($record->date->format('Y-m-d'))->toBe('2026-08-01');
});

it('reads an early departure against the tenant\'s shift end', function () {
    [, $employee] = addisShiftEmployee();

    punch($employee, 'check_in', '2026-10-12 14:12:00');           // 17:12 Addis
    $left = punch($employee, 'check_out', '2026-10-12 19:00:00');  // 22:00 Addis

    expect($left->status)->toBe(AttendanceStatus::EARLY_LEAVE);
});

it('does not call a full shift an early departure', function () {
    [, $employee] = addisShiftEmployee();

    punch($employee, 'check_in', '2026-10-12 14:12:00');           // 17:12 Addis
    $left = punch($employee, 'check_out', '2026-10-12 20:20:00');  // 23:20 Addis, after 23:15

    expect($left->status)->toBe(AttendanceStatus::PRESENT);
});

it('counts overtime from the tenant\'s shift end, and as night hours by the tenant\'s clock', function () {
    [, $employee] = addisShiftEmployee();

    punch($employee, 'check_in', '2026-10-12 14:12:00');            // 17:12 Addis
    $left = punch($employee, 'check_out', '2026-10-12 21:00:00');   // 00:00 Addis

    // 23:30 → 00:00 Addis: 30 minutes, all of them inside the 22:00–06:00
    // night window. On the UTC clock the shift "ended" at 23:30 UTC — after
    // the check-out — so there was no overtime at all.
    expect($left->overtimeMinutes())->toBe(30);

    $buckets = app(OvertimeClassifier::class)->classify($left->load('shift'), isHoliday: false, isRestDay: false, nightWindow: ['start_hour' => 22, 'end_hour' => 6]);
    expect($buckets['night'])->toBe(30)
        ->and($buckets['normal'])->toBe(0);
});

it('does not let an after-midnight check-out close a check-in left open that morning', function () {
    [, $employee] = addisShiftEmployee();

    punch($employee, 'check_in', '2026-10-12 06:00:00');   // 09:00 Addis, never closed

    // 00:30 Addis the next day, 15½ hours later: a missing punch, not this one.
    expect(fn () => punch($employee, 'check_out', '2026-10-12 21:30:00'))
        ->toThrow(NoOpenCheckIn::class);
});
