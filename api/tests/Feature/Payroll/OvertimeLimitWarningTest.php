<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\Employee;
use App\Models\Holiday;
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
 * by nothing. It is not a pay cap, since overtime worked is owed (Art. 68).
 * Days past it, days past the legal four hours, and weeks past the legal
 * twelve (Proclamation 1156/2019 Art. 67) are flagged on the run for review,
 * and every minute is still paid.
 */

/**
 * @param  list<string>  $dates  working days, each worked 08:30 to $checkOut
 */
function overtimeLimitRun(int $tenantId, int $userId, array $dates, string $checkOut, string $from = '2026-06-01', string $to = '2026-06-30'): PayrollRun
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

    return app(PayrollEngine::class)->process($tenantId, Carbon::parse($from), Carbon::parse($to), $userId)->run;
}

function overtimeLimitStep(PayrollRun $run): array
{
    $entry = PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();

    return collect($entry->calculation_log['steps'])->firstWhere('step', 'overtime');
}

test('days over the organisation\'s limit are flagged and every minute is still paid', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // Three days of 3 hours: past the default 2-hour limit, inside the legal 4.
    $run = overtimeLimitRun($tenant->id, $user->id, ['2026-06-01', '2026-06-02', '2026-06-03'], '20:30');
    $step = overtimeLimitStep($run);

    expect($step['overtime_minutes'])->toBe(540)
        ->and($step['overtime_amount_cents'])->toBe((new OvertimeCalculator)->calculate(500000, 22, 8, 540, 'normal'))
        ->and($step['limits']['daily_limit_minutes'])->toBe(120)
        ->and($step['limits']['days_over_daily_limit'])->toBe(['2026-06-01', '2026-06-02', '2026-06-03'])
        ->and($step['limits']['legal_daily_limit_minutes'])->toBe(240)
        ->and($step['limits']['days_over_legal_daily_limit'])->toBe([])
        ->and($step['limits']['legal_weekly_limit_minutes'])->toBe(720)
        ->and($step['limits']['weeks_over_legal_weekly_limit'])->toBe([]);

    $limits = app(RunRegisterService::class)->register($run)['overtime_limits'];
    expect($limits['count'])->toBe(1)
        ->and($limits['employees'][0]['days_over_daily_limit'])->toBe(3)
        ->and($limits['employees'][0]['days_over_legal_daily_limit'])->toBe(0)
        ->and($limits['employees'][0]['weeks_over_legal_weekly_limit'])->toBe(0);
});

test('a day past the legal four hours is flagged as such', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // 17:30 to 22:30 is five hours of overtime.
    $run = overtimeLimitRun($tenant->id, $user->id, ['2026-06-02'], '22:30');
    $step = overtimeLimitStep($run);

    expect($step['overtime_minutes'])->toBe(300)
        ->and($step['limits']['days_over_legal_daily_limit'])->toBe(['2026-06-02'])
        ->and($step['limits']['days_over_daily_limit'])->toBe(['2026-06-02']);
});

test('a week past the legal twelve hours is flagged even when no day passes four', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // Monday to Thursday, 3.5 hours a day: 14 hours in the week.
    $run = overtimeLimitRun($tenant->id, $user->id, ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04'], '21:00');
    $step = overtimeLimitStep($run);

    expect($step['overtime_minutes'])->toBe(840)
        ->and($step['limits']['days_over_legal_daily_limit'])->toBe([])
        ->and($step['limits']['weeks_over_legal_weekly_limit'])->toEqual([['week_start' => '2026-06-01', 'minutes' => 840]]);

    $limits = app(RunRegisterService::class)->register($run)['overtime_limits'];
    expect($limits['employees'][0]['weeks_over_legal_weekly_limit'])->toBe(1)
        ->and($limits['employees'][0]['longest_week_minutes'])->toBe(840)
        ->and($limits['legal_weekly_limit_minutes'])->toBe(720);
});

test('a week that runs into the next period is counted whole, and only the period is paid', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // Monday 29 June to Thursday 2 July 2026: two days in June, two in July.
    $run = overtimeLimitRun($tenant->id, $user->id, ['2026-06-29', '2026-06-30', '2026-07-01', '2026-07-02'], '21:00');
    $step = overtimeLimitStep($run);

    expect($step['overtime_minutes'])->toBe(420)
        ->and($step['overtime_amount_cents'])->toBe((new OvertimeCalculator)->calculate(500000, 22, 8, 420, 'normal'))
        ->and($step['limits']['weeks_over_legal_weekly_limit'])->toEqual([['week_start' => '2026-06-29', 'minutes' => 840]]);
});

test('public holiday work is paid at its own rate and not counted against the limits', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    Holiday::factory()->create(['tenant_id' => $tenant->id, 'date' => '2026-06-10', 'branch_id' => null, 'is_active' => true]);

    // A full shift on the holiday is all holiday-rate work, nine hours of it.
    $run = overtimeLimitRun($tenant->id, $user->id, ['2026-06-10'], '17:30');
    $step = overtimeLimitStep($run);

    expect($step['by_type']['holiday']['minutes'])->toBe(540)
        ->and($step['limits']['days_over_daily_limit'])->toBe([])
        ->and($step['limits']['days_over_legal_daily_limit'])->toBe([])
        ->and(app(RunRegisterService::class)->register($run)['overtime_limits']['count'])->toBe(0);
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

    $run = overtimeLimitRun($tenant->id, $user->id, ['2026-06-02'], '22:30');

    $this->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs/{$run->public_id}/register")
        ->assertOk()
        ->assertJsonPath('overtime_limits.count', 1)
        ->assertJsonPath('overtime_limits.legal_daily_limit_minutes', 240)
        ->assertJsonPath('overtime_limits.legal_weekly_limit_minutes', 720)
        ->assertJsonPath('overtime_limits.employees.0.days_over_daily_limit', 1)
        ->assertJsonPath('overtime_limits.employees.0.days_over_legal_daily_limit', 1)
        ->assertJsonPath('overtime_limits.employees.0.weeks_over_legal_weekly_limit', 0);
});
