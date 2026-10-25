<?php

declare(strict_types=1);

use App\Enums\AttendanceStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use Carbon\Carbon;
use Illuminate\Testing\TestResponse;

afterEach(fn () => Carbon::setTestNow());

/**
 * A shift that crosses midnight (22:00–06:00) is the normal case for guards,
 * factories and hospitals. Check-out looked for an open record dated *today*,
 * so the morning check-out could not find the record dated the night before
 * and answered "No open check-in found for today" — every night-shift record
 * stayed open. And early leave compared the check-out with today's 06:00, so
 * leaving at 23:30 was never flagged.
 */
function nightShiftEmployee(): array
{
    $tenant = createTenant();
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '22:00',
        'end_time' => '06:00',
        'crosses_midnight' => true,
        'grace_minutes' => 15,
        'early_departure_minutes' => 15,
    ]);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    ShiftAssignment::factory()->create([
        'tenant_id' => $tenant->id,
        'shift_id' => $shift->id,
        'assignable_type' => Employee::class,
        'assignable_id' => $employee->id,
    ]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    return [$tenant, $employee];
}

function nightShiftPunch(object $tenant, string $type, string $key): TestResponse
{
    return test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/{$type}", [
        'idempotency_key' => $key,
    ]);
}

test('a night-shift worker can check out the next morning', function () {
    [$tenant, $employee] = nightShiftEmployee();

    Carbon::setTestNow(Carbon::parse('2026-10-05 21:58', 'Africa/Addis_Ababa')->utc());
    nightShiftPunch($tenant, 'check-in', 'night-in-1')->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-10-06 06:05', 'Africa/Addis_Ababa')->utc());
    nightShiftPunch($tenant, 'check-out', 'night-out-1')->assertSuccessful();

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($record->date->format('Y-m-d'))->toBe('2026-10-05')
        ->and($record->check_out?->copy()->setTimezone('Africa/Addis_Ababa')->format('Y-m-d H:i'))->toBe('2026-10-06 06:05')
        ->and($record->status)->not->toBe(AttendanceStatus::EARLY_LEAVE);
});

test('leaving a night shift before midnight is early leave', function () {
    [$tenant, $employee] = nightShiftEmployee();

    Carbon::setTestNow(Carbon::parse('2026-10-05 21:58', 'Africa/Addis_Ababa')->utc());
    nightShiftPunch($tenant, 'check-in', 'night-in-2')->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-10-05 23:30', 'Africa/Addis_Ababa')->utc());
    nightShiftPunch($tenant, 'check-out', 'night-out-2')->assertSuccessful();

    expect(AttendanceRecord::where('employee_id', $employee->id)->sole()->status)
        ->toBe(AttendanceStatus::EARLY_LEAVE);
});

test('a day-shift check-out still only closes today\'s record', function () {
    [$tenant] = nightShiftEmployee();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    Carbon::setTestNow(Carbon::parse('2026-10-05 08:30', 'Africa/Addis_Ababa')->utc());
    nightShiftPunch($tenant, 'check-in', 'day-in')->assertSuccessful();

    // No shift crosses midnight for this employee, so a check-out the next
    // day must not close yesterday's forgotten record.
    Carbon::setTestNow(Carbon::parse('2026-10-06 17:30', 'Africa/Addis_Ababa')->utc());
    nightShiftPunch($tenant, 'check-out', 'day-out')->assertStatus(422);
});

/**
 * Someone due at 22:00 who arrives at 00:30 is late for last night's shift.
 * The punch was matched to tonight's shift, whose 22:00 start (on the punch's
 * own date) was still ahead, so it was stored `present` and dated the day
 * after the shift began.
 */
test('arriving after midnight for a night shift is late and dated the night it began', function () {
    [$tenant, $employee] = nightShiftEmployee();

    Carbon::setTestNow(Carbon::parse('2026-10-06 00:30', 'Africa/Addis_Ababa')->utc());
    nightShiftPunch($tenant, 'check-in', 'night-in-3')->assertSuccessful();

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($record->status)->toBe(AttendanceStatus::LATE)
        ->and($record->date->format('Y-m-d'))->toBe('2026-10-05');

    Carbon::setTestNow(Carbon::parse('2026-10-06 06:10', 'Africa/Addis_Ababa')->utc());
    nightShiftPunch($tenant, 'check-out', 'night-out-3')->assertSuccessful();

    expect($record->fresh()->status)->toBe(AttendanceStatus::LATE)
        ->and($record->fresh()->overtimeMinutes())->toBe(10);
});

test('arriving on time for tonight is not attributed to last night', function () {
    [$tenant, $employee] = nightShiftEmployee();

    Carbon::setTestNow(Carbon::parse('2026-10-06 22:05', 'Africa/Addis_Ababa')->utc());
    nightShiftPunch($tenant, 'check-in', 'night-in-4')->assertSuccessful();

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($record->status)->toBe(AttendanceStatus::PRESENT)
        ->and($record->date->format('Y-m-d'))->toBe('2026-10-06');
});

test('a shift saved without the midnight flag still crosses midnight', function () {
    $tenant = createTenant();
    $shift = Shift::factory()->create([
        'tenant_id' => $tenant->id,
        'start_time' => '22:00',
        'end_time' => '06:00',
        'crosses_midnight' => false,
    ]);

    expect($shift->fresh()->crosses_midnight)->toBeTrue();

    $shift->update(['start_time' => '08:00', 'end_time' => '17:00']);
    expect($shift->fresh()->crosses_midnight)->toBeFalse();
});
