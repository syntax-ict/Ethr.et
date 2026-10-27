<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeCostSharing;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRule;
use App\Models\PensionRule;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Payroll\PayrollEngine;
use Carbon\Carbon;

/**
 * What the engine pays today in the cases the payroll upgrade will touch,
 * pinned before anything changes (docs/payroll/PAY-CHANGES.md).
 *
 * Unlike PayrollGoldenPayslipsTest these figures were obtained by running
 * the engine, deliberately: they are not a claim that the pay is right, only
 * a record of what it was. Each case names the upgrade that is meant to
 * change it. A case that moves in any other change is a regression; a case
 * that moves in its own upgrade is flipped in that commit, with the reason.
 *
 * The inputs are chosen to be awkward — odd salaries, a hire on the 13th,
 * half days — because float rounding only shows on awkward fractions.
 */
function characterise(Tenant $tenant, int $userId, string $from, string $to): PayrollEntry
{
    $run = app(PayrollEngine::class)->process($tenant->id, Carbon::parse($from), Carbon::parse($to), $userId)->run;

    return PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();
}

/** @return array<string, int> */
function figures(PayrollEntry $entry): array
{
    return [
        'basic' => $entry->basic_salary_cents,
        'gross' => $entry->gross_cents,
        'tax' => $entry->income_tax_cents,
        'employee_pension' => $entry->employee_pension_cents,
        'employer_pension' => $entry->employer_pension_cents,
        'other' => $entry->other_deductions_cents,
        'net' => $entry->net_cents,
    ];
}

function characterEmployee(Tenant $tenant, int $basic, string $hired = '2020-01-01'): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id, 'salary_cents' => $basic, 'hire_date' => $hired, 'status' => 'confirmed',
    ]);
}

function workedLate(Tenant $tenant, Employee $employee, string $date, string $checkOut): void
{
    AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => Shift::factory()->create(['tenant_id' => $tenant->id])->id, // 08:30–17:30
        'date' => $date,
        'check_in' => Carbon::parse("{$date} 08:30", 'Africa/Addis_Ababa')->utc(),
        'check_out' => Carbon::parse("{$date} {$checkOut}", 'Africa/Addis_Ababa')->utc(),
    ]);
}

beforeEach(function () {
    $this->tenant = createTenant();
    $this->user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $this->tenant);
});

it('C1: a hire on the 13th who worked two hours late (upgrade 5 changes the overtime base)', function () {
    $employee = characterEmployee($this->tenant, 777777, '2026-06-13');
    workedLate($this->tenant, $employee, '2026-06-15', '19:30');

    expect(figures(characterise($this->tenant, $this->user->id, '2026-06-01', '2026-06-30')))->toBe([
        'basic' => 466666, 'gross' => 474621, 'tax' => 44924, 'employee_pension' => 32667,
        'employer_pension' => 51333, 'other' => 0, 'net' => 397030,
    ]);
});

it('C2: a day and a half of unpaid leave and ninety minutes late (upgrade 5 changes the overtime base)', function () {
    $employee = characterEmployee($this->tenant, 912345);
    $unpaid = LeaveType::factory()->create([
        'tenant_id' => $this->tenant->id, 'code' => 'unpaid', 'name' => 'Unpaid Leave', 'is_paid' => false, 'default_days' => 0,
    ]);
    LeaveRequest::factory()->create([
        'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $unpaid->id,
        'start_date' => '2026-06-08', 'end_date' => '2026-06-09', 'days' => 1.5, 'status' => LeaveStatus::APPROVED,
    ]);
    workedLate($this->tenant, $employee, '2026-06-15', '19:00');

    expect(figures(characterise($this->tenant, $this->user->id, '2026-06-01', '2026-06-30')))->toBe([
        'basic' => 850140, 'gross' => 861008, 'tax' => 130252, 'employee_pension' => 59510,
        'employer_pension' => 93515, 'other' => 0, 'net' => 671246,
    ]);
});

it('C3: Pagume paid by the day, hired part way through the month (upgrade 7 splits salary by days)', function () {
    $this->tenant->update(['settings' => ['pagumen_proration_strategy' => 'daily_rate']]);
    characterEmployee($this->tenant, 654321, '2026-09-08');

    expect(figures(characterise($this->tenant, $this->user->id, '2026-09-01', '2026-09-30')))->toBe([
        'basic' => 500501, 'gross' => 500501, 'tax' => 50100, 'employee_pension' => 35035,
        'employer_pension' => 55055, 'other' => 0, 'net' => 415366,
    ]);
});

it('C4: a final loan instalment and cost sharing on an odd gross (unchanged: cost sharing keeps truncating)', function () {
    $employee = characterEmployee($this->tenant, 1234567);
    EmployeeLoan::factory()->create([
        'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'amount_cents' => 240000,
        'remaining_cents' => 15000, 'monthly_deduction_cents' => 20000, 'status' => 'active',
    ]);
    EmployeeCostSharing::factory()->create([
        'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'deduction_rate_percent' => 7.5,
        'total_obligation_cents' => 5000000, 'outstanding_cents' => 5000000,
    ]);

    expect(figures(characterise($this->tenant, $this->user->id, '2026-06-01', '2026-06-30')))->toBe([
        'basic' => 1234567, 'gross' => 1234567, 'tax' => 235370, 'employee_pension' => 86420,
        'employer_pension' => 135802, 'other' => 107592, 'net' => 805185,
    ]);
});

it('C5: deductions larger than the pay leave a negative net (upgrade 10 adds net protection)', function () {
    $employee = characterEmployee($this->tenant, 300000);
    EmployeeLoan::factory()->create([
        'tenant_id' => $this->tenant->id, 'employee_id' => $employee->id, 'amount_cents' => 600000,
        'remaining_cents' => 600000, 'monthly_deduction_cents' => 500000, 'status' => 'active',
    ]);

    expect(figures(characterise($this->tenant, $this->user->id, '2026-06-01', '2026-06-30')))->toBe([
        'basic' => 300000, 'gross' => 300000, 'tax' => 15000, 'employee_pension' => 21000,
        'employer_pension' => 33000, 'other' => 500000, 'net' => -236000,
    ]);
});

it('C6: a regular-salary pension base counts every allowance (upgrade 8 counts only pensionable ones)', function () {
    PensionRule::create([
        'scheme' => 'private', 'employee_rate' => 7, 'employer_rate' => 11, 'base' => 'regular_salary',
        'legal_reference' => 'Characterisation', 'effective_from' => '2020-01-01',
    ]);
    characterEmployee($this->tenant, 512345);
    PayrollRule::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Transport allowance', 'type' => 'fixed', 'category' => 'allowance',
        'formula' => ['amount_cents' => 60000], 'is_taxable' => false, 'is_active' => true, 'sort_order' => 10,
    ]);
    PayrollRule::query()->create([
        'tenant_id' => $this->tenant->id, 'name' => 'Position allowance', 'type' => 'percentage', 'category' => 'allowance',
        'formula' => ['percent' => 12.5], 'is_taxable' => true, 'is_active' => true, 'sort_order' => 20,
    ]);

    expect(figures(characterise($this->tenant, $this->user->id, '2026-06-01', '2026-06-30')))->toBe([
        'basic' => 512345, 'gross' => 636388, 'tax' => 65278, 'employee_pension' => 44547,
        'employer_pension' => 70003, 'other' => 0, 'net' => 526563,
    ]);
});

it('records the rounding rule and the whole tax ladder it applied (log 1.4)', function () {
    characterEmployee($this->tenant, 512345);
    $log = characterise($this->tenant, $this->user->id, '2026-06-01', '2026-06-30')->calculation_log;
    $tax = collect($log['steps'])->firstWhere('step', 'income_tax');

    expect(version_compare($log['version'], '1.4', '>='))->toBeTrue()
        ->and($log['rounding'])->toBe('integer_half_up')
        ->and($tax['rule']['brackets'])->toHaveCount(6)
        ->and($tax['rule']['brackets'][1])->toMatchArray(['min' => 200001, 'max' => 400000, 'deduction' => 30000])
        ->and((float) $tax['rule']['brackets'][1]['rate'])->toBe(15.0);
});
