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

test('daytime overtime on a public holiday is paid at 2.0x', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $entry = runOvertimePayroll($tenant->id, $user->id, '2026-06-15 19:30', holiday: true);
    $expectedHoliday = (new OvertimeCalculator)->calculate(500000, 22, 8, 120, 'holiday');
    $expectedNormal = (new OvertimeCalculator)->calculate(500000, 22, 8, 120, 'normal');

    $step = otStep($entry);
    expect($step['by_type']['holiday']['minutes'])->toBe(120);
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
