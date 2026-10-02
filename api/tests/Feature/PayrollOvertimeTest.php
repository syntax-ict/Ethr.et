<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\PayrollEntry;
use App\Models\Shift;
use App\Services\Payroll\OvertimeCalculator;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

// One employee, one attendance record with overtime, processed over June 2026.
// Basic 500,000 cents; no hire/pagumen proration in a normal Gregorian month,
// so the OT amount is the only variable under test.
function runOvertimePayroll(int $tenantId, int $userId, string $checkOut, bool $holiday): PayrollEntry
{
    $employee = Employee::factory()->create([
        'tenant_id' => $tenantId,
        'salary_cents' => 500000,
        'hire_date' => '2020-01-01',
    ]);

    $shift = Shift::factory()->create(['tenant_id' => $tenantId]); // 08:30–17:30

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenantId,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => '2026-06-15',
        'check_in' => Carbon::parse('2026-06-15 08:30'),
        'check_out' => Carbon::parse($checkOut),
    ]);

    if ($holiday) {
        Holiday::factory()->create([
            'tenant_id' => $tenantId,
            'date' => '2026-06-15',
            'branch_id' => null,
            'is_active' => true,
        ]);
    }

    $run = app(PayrollEngine::class)->process(
        $tenantId,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $userId,
    )->run;

    return PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
}

function otStep(PayrollEntry $entry): array
{
    return collect($entry->calculation_log['steps'])->firstWhere('step', 'overtime');
}

test('daytime overtime on an ordinary day is paid at the normal rate', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $entry = runOvertimePayroll($tenant->id, $user->id, '2026-06-15 19:30', holiday: false);
    $expected = (new OvertimeCalculator)->calculate(500000, 22, 8, 120, 'normal');

    $step = otStep($entry);
    expect($step['by_type']['normal']['minutes'])->toBe(120);
    expect($step['by_type']['normal']['amount_cents'])->toBe($expected);
    expect($step['overtime_amount_cents'])->toBe($expected);
    expect($entry->gross_cents)->toBe(500000 + $expected);
});

// Art. 68(1)(d): work on a public holiday is paid at 2.5x — all of it, from
// check-in. Until 2026-10-01 this paid only the 120 minutes past the shift's
// end, at 2.0x: 8.3% of what the law requires for this day.
test('a day worked on a public holiday is paid at 2.5x from check-in', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $entry = runOvertimePayroll($tenant->id, $user->id, '2026-06-15 19:30', holiday: true);
    $expectedHoliday = (new OvertimeCalculator)->calculate(500000, 22, 8, 660, 'holiday');
    $expectedNormal = (new OvertimeCalculator)->calculate(500000, 22, 8, 660, 'normal');

    $step = otStep($entry);
    expect($step['by_type']['holiday']['minutes'])->toBe(660);
    expect((float) $step['by_type']['holiday']['rate'])->toBe(2.5);
    expect($step['by_type']['holiday']['amount_cents'])->toBe($expectedHoliday);
    expect($step['by_type']['normal']['minutes'])->toBe(0);
    expect($step['overtime_amount_cents'])->toBe($expectedHoliday);
    // Holiday OT pays strictly more than the same minutes at the normal rate.
    expect($expectedHoliday)->toBeGreaterThan($expectedNormal);
});

test('overtime running into the night splits normal and night pay', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // 17:30 -> 23:30: 270 day min + 90 night min.
    $entry = runOvertimePayroll($tenant->id, $user->id, '2026-06-15 23:30', holiday: false);

    $normal = (new OvertimeCalculator)->calculate(500000, 22, 8, 270, 'normal');
    $night = (new OvertimeCalculator)->calculate(500000, 22, 8, 90, 'night');

    $step = otStep($entry);
    expect($step['by_type']['normal']['minutes'])->toBe(270);
    expect($step['by_type']['night']['minutes'])->toBe(90);
    expect($step['overtime_minutes'])->toBe(360);
    expect($step['overtime_amount_cents'])->toBe($normal + $night);
});

/**
 * Art. 68(1)(c): work on a weekly rest day is paid at 2x for the whole span.
 * A rest day is a day the record's shift does not work (working_days, ISO
 * 1-7); with no shift it is Sunday. Until 2026-10-01 rest days did not exist
 * in payroll: a Sunday worked under a Monday-Friday shift paid only time past
 * 17:30, at the weekday rate.
 */
function runDayPayroll(int $tenantId, int $userId, string $date, string $in, string $out, ?string $workingDays): PayrollEntry
{
    $employee = Employee::factory()->create([
        'tenant_id' => $tenantId,
        'salary_cents' => 500000,
        'hire_date' => '2020-01-01',
    ]);

    $shiftId = $workingDays === null
        ? null
        : Shift::factory()->create(['tenant_id' => $tenantId, 'working_days' => $workingDays])->id;

    AttendanceRecord::factory()->create([
        'tenant_id' => $tenantId,
        'employee_id' => $employee->id,
        'shift_id' => $shiftId,
        'date' => $date,
        'check_in' => Carbon::parse("{$date} {$in}"),
        'check_out' => Carbon::parse("{$date} {$out}"),
    ]);

    $run = app(PayrollEngine::class)->process(
        $tenantId,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $userId,
    )->run;

    return PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
}

test('a Sunday worked under a Monday-Friday shift is paid at 2x from check-in', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // 2026-06-14 is a Sunday.
    $entry = runDayPayroll($tenant->id, $user->id, '2026-06-14', '09:00', '13:00', '1,2,3,4,5');

    $step = otStep($entry);
    expect($step['by_type']['rest_day']['minutes'])->toBe(240)
        ->and((float) $step['by_type']['rest_day']['rate'])->toBe(2.0)
        ->and($step['overtime_amount_cents'])->toBe((new OvertimeCalculator)->calculate(500000, 22, 8, 240, 'rest_day'));
});

test('a Saturday is an ordinary day for a six-day shift', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // 2026-06-13 is a Saturday, a working day here; 08:30-17:30 is no overtime.
    $entry = runDayPayroll($tenant->id, $user->id, '2026-06-13', '08:30', '17:30', '1,2,3,4,5,6');

    expect(otStep($entry)['overtime_minutes'])->toBe(0);
});

test('with no shift, Sunday is the weekly rest day', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $entry = runDayPayroll($tenant->id, $user->id, '2026-06-14', '10:00', '12:00', null);

    expect(otStep($entry)['by_type']['rest_day']['minutes'])->toBe(120);
});
