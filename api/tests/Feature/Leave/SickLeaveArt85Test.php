<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\Tenant;
use App\Services\Leave\LeaveDayCalculator;
use App\Services\Leave\SickLeaveTiers;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

/*
 * A-Z review E-08 (2026-11-06). Proclamation 1156/2019 Art. 85: sick leave
 * of up to six months within twelve, counted from the first day of
 * sickness; the first month at full pay, the next two at half pay, the last
 * three unpaid. It was one paid bucket of 30 days. One month is
 * `payroll.working_days_per_month` (22) of the person's working days, so a
 * month of sick leave costs exactly a month's basic.
 */

const ART85_MONTH = 22;

function art85Setup(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => 500000, 'hire_date' => '2020-01-01', 'status' => 'confirmed',
    ]);
    $sick = LeaveType::factory()->create([
        'tenant_id' => $tenant->id, 'code' => 'sick', 'is_paid' => true, 'default_days' => 30, 'follows_art85' => true,
    ]);

    return [$tenant, $employee, $sick];
}

function art85Dates(Employee $employee, string $from, string $to): array
{
    return app(LeaveDayCalculator::class)->chargedDates(Carbon::parse($from), Carbon::parse($to), $employee->tenant_id, $employee->branch_id, $employee);
}

function art85Leave(Tenant $tenant, Employee $employee, LeaveType $type, string $from, string $to, LeaveStatus $status = LeaveStatus::APPROVED): LeaveRequest
{
    return LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id,
        'start_date' => $from, 'end_date' => $to, 'days' => count(art85Dates($employee, $from, $to)), 'status' => $status,
    ]);
}

function art85June(Tenant $tenant): PayrollEntry
{
    $finance = createUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $run = app(PayrollEngine::class)->process($tenant->id, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'), $finance->id)->run;

    return PayrollEntry::where('payroll_run_id', $run->id)->firstOrFail();
}

function art85Step(PayrollEntry $entry): array
{
    return collect($entry->calculation_log['steps'])->firstWhere('step', 'sick_leave');
}

it('pays the first month of sick leave in full', function () {
    [$tenant, $employee, $sick] = art85Setup();
    art85Leave($tenant, $employee, $sick, '2026-06-08', '2026-06-12');

    $entry = art85June($tenant);

    expect(art85Step($entry)['deduction_cents'])->toBe(0)
        ->and($entry->basic_salary_cents)->toBe(500000);
});

it('pays the second and third months at half pay', function () {
    [$tenant, $employee, $sick] = art85Setup();
    // More than a month, less than three, before June.
    art85Leave($tenant, $employee, $sick, '2026-04-01', '2026-05-29');
    $prior = count(art85Dates($employee, '2026-04-01', '2026-05-29'));
    expect($prior)->toBeGreaterThan(ART85_MONTH)->toBeLessThanOrEqual(3 * ART85_MONTH - 10);
    art85Leave($tenant, $employee, $sick, '2026-06-08', '2026-06-19');
    $june = count(art85Dates($employee, '2026-06-08', '2026-06-19'));

    $step = art85Step(art85June($tenant));

    expect($step['half_pay_days'])->toEqual((float) $june)
        ->and($step['unpaid_days'])->toEqual(0.0)
        // Half of the daily rate, 22,727, for each day.
        ->and($step['deduction_cents'])->toBe((int) round(22727 * $june / 2));
});

it('leaves the fourth to sixth months unpaid', function () {
    [$tenant, $employee, $sick] = art85Setup();
    art85Leave($tenant, $employee, $sick, '2026-01-05', '2026-05-29');
    $prior = count(art85Dates($employee, '2026-01-05', '2026-05-29'));
    expect($prior)->toBeGreaterThan(3 * ART85_MONTH);
    art85Leave($tenant, $employee, $sick, '2026-06-08', '2026-06-12');
    $june = count(art85Dates($employee, '2026-06-08', '2026-06-12'));

    $step = art85Step(art85June($tenant));

    expect($step['unpaid_days'])->toEqual((float) $june)
        ->and($step['half_pay_days'])->toEqual(0.0)
        ->and($step['deduction_cents'])->toBe(22727 * $june);
});

it('starts a new twelve months, at full pay, after the last period ends', function () {
    [$tenant, $employee, $sick] = art85Setup();
    art85Leave($tenant, $employee, $sick, '2025-01-06', '2025-04-30');
    $later = art85Leave($tenant, $employee, $sick, '2026-03-02', '2026-03-06');

    $tiers = app(SickLeaveTiers::class)->tiersOf($employee, ART85_MONTH);

    foreach (art85Dates($employee, '2026-03-02', '2026-03-06') as $date) {
        expect($tiers[$date])->toBe('full');
    }
    expect($later->fresh()->status)->toBe(LeaveStatus::APPROVED);
});

it('refuses sick leave beyond six months in the period', function () {
    [$tenant, $employee, $sick] = art85Setup();
    // Nearly six months from July, so January's request goes past them.
    art85Leave($tenant, $employee, $sick, '2026-07-06', '2026-12-31');
    $person = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->actingAs($person)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
            'leave_type_public_id' => $sick->public_id,
            'start_date' => '2027-01-04',
            'end_date' => '2027-01-29',
            'reason' => 'Still unwell',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('detail', __('leave.sick_beyond_six_months'));
});

it('accepts sick leave past the old thirty-day bucket while within six months', function () {
    [$tenant, $employee, $sick] = art85Setup();
    art85Leave($tenant, $employee, $sick, '2026-04-01', '2026-05-29');
    $person = createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant);

    test()->actingAs($person)
        ->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/leave/request", [
            'leave_type_public_id' => $sick->public_id,
            'start_date' => '2026-12-07',
            'end_date' => '2026-12-11',
            'reason' => 'Flu',
        ])
        ->assertCreated();
});
