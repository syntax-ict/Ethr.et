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

    Carbon::setTestNow(Carbon::parse('2026-10-05 21:58'));
    nightShiftPunch($tenant, 'check-in', 'night-in-1')->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-10-06 06:05'));
    nightShiftPunch($tenant, 'check-out', 'night-out-1')->assertSuccessful();

    $record = AttendanceRecord::where('employee_id', $employee->id)->sole();
    expect($record->date->format('Y-m-d'))->toBe('2026-10-05')
        ->and($record->check_out?->format('Y-m-d H:i'))->toBe('2026-10-06 06:05')
        ->and($record->status)->not->toBe(AttendanceStatus::EARLY_LEAVE);
});

test('leaving a night shift before midnight is early leave', function () {
    [$tenant, $employee] = nightShiftEmployee();

    Carbon::setTestNow(Carbon::parse('2026-10-05 21:58'));
    nightShiftPunch($tenant, 'check-in', 'night-in-2')->assertSuccessful();

    Carbon::setTestNow(Carbon::parse('2026-10-05 23:30'));
    nightShiftPunch($tenant, 'check-out', 'night-out-2')->assertSuccessful();

    expect(AttendanceRecord::where('employee_id', $employee->id)->sole()->status)
        ->toBe(AttendanceStatus::EARLY_LEAVE);
});

test('a day-shift check-out still only closes today\'s record', function () {
    [$tenant] = nightShiftEmployee();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    Carbon::setTestNow(Carbon::parse('2026-10-05 08:30'));
    nightShiftPunch($tenant, 'check-in', 'day-in')->assertSuccessful();

    // No shift crosses midnight for this employee, so a check-out the next
    // day must not close yesterday's forgotten record.
    Carbon::setTestNow(Carbon::parse('2026-10-06 17:30'));
    nightShiftPunch($tenant, 'check-out', 'day-out')->assertStatus(422);
});
