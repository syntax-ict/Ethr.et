<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Shift;
use App\Services\Payroll\OvertimeCalculator;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\RunRegisterService;
use Carbon\Carbon;

/**
 * Overtime past the limits (blueprint AT10, S8). The organisation's daily
 * limit was stored and labelled "Maximum overtime counted per day" but read
 * by nothing. It is not a pay cap, since overtime worked is owed (Art. 68):
 * days past it, and a month past the 20-hour legal limit (Art. 67(2)), are
 * flagged on the run for review, and every minute is still paid.
 */

/**
 * @param  list<string>  $dates  June 2026 working days
 */
function overtimeLimitRun(int $tenantId, int $userId, array $dates, string $checkOut): PayrollRun
{
    $employee = Employee::factory()->create([
        'tenant_id' => $tenantId,
        'salary_cents' => 500000,
        'hire_date' => '2020-01-01',
    ]);
    $shift = Shift::factory()->create(['tenant_id' => $tenantId]); // 08:30-17:30

    foreach ($dates as $date) {
        AttendanceRecord::factory()->create([
            'tenant_id' => $tenantId,
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'date' => $date,
            'check_in' => Carbon::parse("{$date} 08:30", 'Africa/Addis_Ababa')->utc(),
            'check_out' => Carbon::parse("{$date} {$checkOut}", 'Africa/Addis_Ababa')->utc(),
        ]);
    }

    return app(PayrollEngine::class)->process(
        $tenantId,
        Carbon::parse('2026-06-01'),
        Carbon::parse('2026-06-30'),
        $userId,
    )->run;
}

function overtimeLimitStep(PayrollRun $run): array
{
    $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();

    return collect($entry->calculation_log['steps'])->firstWhere('step', 'overtime');
}

test('days over the daily limit are flagged and every minute is still paid', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // Three days of 3 hours' overtime against the default 2-hour limit.
    $run = overtimeLimitRun($tenant->id, $user->id, ['2026-06-01', '2026-06-02', '2026-06-03'], '20:30');
    $step = overtimeLimitStep($run);

    expect($step['overtime_minutes'])->toBe(540)
        ->and($step['overtime_amount_cents'])->toBe((new OvertimeCalculator)->calculate(500000, 22, 8, 540, 'normal'))
        ->and($step['limits']['daily_limit_minutes'])->toBe(120)
        ->and($step['limits']['days_over_daily_limit'])->toBe(['2026-06-01', '2026-06-02', '2026-06-03'])
        ->and($step['limits']['over_monthly_limit'])->toBeFalse();

    $limits = app(RunRegisterService::class)->register($run)['overtime_limits'];
    expect($limits['count'])->toBe(1)
        ->and($limits['daily_limit_minutes'])->toBe(120)
        ->and($limits['employees'][0]['days_over_daily_limit'])->toBe(3)
        ->and($limits['employees'][0]['overtime_minutes'])->toBe(540);
});

test('a month past twenty hours of overtime is flagged even when no single day is', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // Eleven days of exactly 2 hours: within the daily limit, 22 hours in all.
    $days = ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05',
        '2026-06-08', '2026-06-09', '2026-06-10', '2026-06-11', '2026-06-12', '2026-06-15'];
    $run = overtimeLimitRun($tenant->id, $user->id, $days, '19:30');
    $step = overtimeLimitStep($run);

    expect($step['overtime_minutes'])->toBe(1320)
        ->and($step['limits']['days_over_daily_limit'])->toBe([])
        ->and($step['limits']['over_monthly_limit'])->toBeTrue();

    $limits = app(RunRegisterService::class)->register($run)['overtime_limits'];
    expect($limits['count'])->toBe(1)
        ->and($limits['employees'][0]['over_monthly_limit'])->toBeTrue()
        ->and($limits['employees'][0]['days_over_daily_limit'])->toBe(0);
});

test('the organisation\'s own daily limit is the one applied', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    AttendanceSetting::create(['tenant_id' => $tenant->id, ...AttendanceSetting::defaults(), 'ot_daily_cap_minutes' => 240]);

    $run = overtimeLimitRun($tenant->id, $user->id, ['2026-06-01', '2026-06-02'], '20:30');
    $step = overtimeLimitStep($run);

    expect($step['limits']['daily_limit_minutes'])->toBe(240)
        ->and($step['limits']['days_over_daily_limit'])->toBe([])
        ->and(app(RunRegisterService::class)->register($run)['overtime_limits']['count'])->toBe(0);
});

test('the run register reports who went over, for the run page', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $run = overtimeLimitRun($tenant->id, $user->id, ['2026-06-01'], '20:30');

    $this->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/register")
        ->assertOk()
        ->assertJsonPath('overtime_limits.count', 1)
        ->assertJsonPath('overtime_limits.employees.0.days_over_daily_limit', 1)
        ->assertJsonPath('overtime_limits.employees.0.over_monthly_limit', false);
});
