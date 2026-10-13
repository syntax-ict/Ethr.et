<?php

declare(strict_types=1);

use App\Enums\LeaveStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollEntry;
use App\Models\PayrollRule;
use App\Models\PayrollRun;
use App\Services\Accounting\AccountingExportService;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\RunRegisterService;
use Carbon\Carbon;

/**
 * Worked payslips, asserted to the cent (docs/payroll/ACCOUNTANT-SIGN-OFF.md).
 *
 * Every expected figure below is written out by hand from the statutory rules,
 * not obtained by running the engine: a golden test that asks the code for its
 * own answer proves nothing. The period is October 2026 (after Proclamation
 * 1395/2025 took effect on 7 July 2025), every employee was hired before it, so
 * no proration applies and no Pagume day falls in it.
 *
 * Income tax, Proclamation 1395/2025, monthly employment income:
 *   0 – 2,000 ETB 0% · 2,000.01 – 4,000 15% · 4,000.01 – 7,000 20%
 *   7,000.01 – 10,000 25% · 10,000.01 – 14,000 30% · over 14,000 35%
 * Pension: employee 7%, employer 11% of basic salary.
 */
function goldenEmployee(int $tenantId, string $code, int $basicCents): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenantId,
        'employee_code' => $code,
        'salary_cents' => $basicCents,
        'hire_date' => '2025-01-01',
        'status' => 'confirmed',
    ]);
}

function goldenRun(int $tenantId, int $userId): PayrollRun
{
    return app(PayrollEngine::class)->process(
        $tenantId, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), $userId,
    )->run;
}

it('pays one employee in every tax band exactly as the law computes it', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    $cases = [
        // code => [basic, income tax, employee pension, employer pension, net]
        // 1,800: below 2,000 — no tax. Pension 7% = 126, 11% = 198. Net 1,800 − 126.
        'G1' => [180000, 0, 12600, 19800, 167400],
        // 3,500: 15% of the 1,500 over 2,000 = 225. Pension 245 / 385.
        'G2' => [350000, 22500, 24500, 38500, 303000],
        // 6,000: 300 (band 2 full) + 20% of 2,000 = 700. Pension 420 / 660.
        'G3' => [600000, 70000, 42000, 66000, 488000],
        // 9,000: 300 + 600 (band 3 full) + 25% of 2,000 = 1,400. Pension 630 / 990.
        'G4' => [900000, 140000, 63000, 99000, 697000],
        // 12,000: 300 + 600 + 750 (band 4 full) + 30% of 2,000 = 2,250. Pension 840 / 1,320.
        'G5' => [1200000, 225000, 84000, 132000, 891000],
        // 25,000: 300 + 600 + 750 + 1,200 (band 5 full) + 35% of 11,000 = 6,700. Pension 1,750 / 2,750.
        'G6' => [2500000, 670000, 175000, 275000, 1655000],
    ];

    foreach ($cases as $code => [$basic]) {
        goldenEmployee($tenant->id, $code, $basic);
    }

    $run = goldenRun($tenant->id, $user->id);

    foreach ($cases as $code => [$basic, $tax, $employeePension, $employerPension, $net]) {
        $entry = PayrollEntry::query()
            ->where('payroll_run_id', $run->id)
            ->whereHas('employee', fn ($q) => $q->where('employee_code', $code))
            ->sole();

        expect([
            'basic' => $entry->basic_salary_cents,
            'gross' => $entry->gross_cents,
            'tax' => $entry->income_tax_cents,
            'employee_pension' => $entry->employee_pension_cents,
            'employer_pension' => $entry->employer_pension_cents,
            'net' => $entry->net_cents,
        ])->toBe([
            'basic' => $basic,
            'gross' => $basic,
            'tax' => $tax,
            'employee_pension' => $employeePension,
            'employer_pension' => $employerPension,
            'net' => $net,
        ], "payslip {$code}");
    }
});

it('pays unpaid leave and allowances as the worked example shows', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);

    // Basic 8,800 ETB; two days of approved unpaid leave; a non-taxable
    // transport allowance of 600 ETB and a taxable position allowance of 10%.
    $employee = goldenEmployee($tenant->id, 'G7', 880000);

    PayrollRule::query()->create([
        'tenant_id' => $tenant->id, 'name' => 'Transport allowance', 'type' => 'fixed', 'category' => 'allowance',
        'formula' => ['amount_cents' => 60000], 'is_taxable' => false, 'is_active' => true, 'sort_order' => 10,
    ]);
    PayrollRule::query()->create([
        'tenant_id' => $tenant->id, 'name' => 'Position allowance', 'type' => 'percentage', 'category' => 'allowance',
        'formula' => ['percent' => 10], 'is_taxable' => true, 'is_active' => true, 'sort_order' => 20,
    ]);

    $unpaid = LeaveType::factory()->create([
        'tenant_id' => $tenant->id, 'code' => 'unpaid', 'name' => 'Unpaid Leave', 'is_paid' => false, 'default_days' => 0,
    ]);
    LeaveRequest::factory()->create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'leave_type_id' => $unpaid->id,
        'start_date' => '2026-10-05', 'end_date' => '2026-10-06', 'days' => 2,
        'status' => LeaveStatus::APPROVED,
    ]);

    $run = goldenRun($tenant->id, $user->id);
    $entry = PayrollEntry::query()->where('payroll_run_id', $run->id)->sole();

    // Daily rate 8,800 / 22 = 400; two days = 800; basic after leave 8,000.
    // Allowances: transport 600 (not taxable) + position 10% of 8,000 = 800.
    // Gross 8,000 + 600 + 800 = 9,400. Taxable 9,400 − 600 = 8,800.
    // Tax: 300 + 600 + 25% of 1,800 = 1,350. Pension on 8,000: 560 / 880.
    // Net 9,400 − 1,350 − 560 = 7,490.
    expect([
        'basic' => $entry->basic_salary_cents,
        'gross' => $entry->gross_cents,
        'tax' => $entry->income_tax_cents,
        'employee_pension' => $entry->employee_pension_cents,
        'employer_pension' => $entry->employer_pension_cents,
        'net' => $entry->net_cents,
    ])->toBe([
        'basic' => 800000,
        'gross' => 940000,
        'tax' => 135000,
        'employee_pension' => 56000,
        'employer_pension' => 88000,
        'net' => 749000,
    ]);
});

it('produces a register whose totals tie to the run and to the accounting journal', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    foreach (['R1' => 350000, 'R2' => 900000, 'R3' => 2500000] as $code => $basic) {
        goldenEmployee($tenant->id, $code, $basic);
    }
    $run = goldenRun($tenant->id, $user->id);

    $register = app(RunRegisterService::class)->register($run);
    $totals = $register['totals'];

    // Hand totals: gross 37,500; tax 225 + 1,400 + 6,700 = 8,325;
    // pension 7% 2,625, 11% 4,125; net 37,500 − 8,325 − 2,625 = 26,550.
    expect($totals)->toMatchArray([
        'gross_cents' => 3750000,
        'income_tax_cents' => 832500,
        'employee_pension_cents' => 262500,
        'employer_pension_cents' => 412500,
        'net_cents' => 2655000,
    ]);

    $run->refresh();
    expect($totals['gross_cents'])->toBe($run->gross_total_cents);
    expect($totals['net_cents'])->toBe($run->net_total_cents);

    $journal = collect(app(AccountingExportService::class)->journalEntries($run)['entries']);
    $credit = fn (string $code): int => (int) $journal->firstWhere('account_code', $code)['credit_cents'];

    expect($credit('2100'))->toBe($totals['income_tax_cents']);
    expect($credit('2200'))->toBe($totals['employee_pension_cents']);
    expect($credit('2201'))->toBe($totals['employer_pension_cents']);
    expect($credit('2300'))->toBe($totals['net_cents']);
});

it('serves the register as JSON and as a formula-safe CSV to finance, not to employees', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = goldenEmployee($tenant->id, 'C1', 350000);
    $employee->update(['name' => '=HYPERLINK("x")']);
    $run = goldenRun($tenant->id, $user->id);

    $this->getJson("/api/v1/payroll/runs/{$run->public_id}/register")
        ->assertOk()
        ->assertJsonPath('totals.income_tax_cents', 22500)
        ->assertJsonPath('rows.0.employee_code', 'C1');

    $csv = (string) $this->get("/api/v1/payroll/runs/{$run->public_id}/register-csv")->assertOk()->getContent();

    expect($csv)->toContain('Income Tax (ETB)')
        ->toContain('225.00')
        ->toContain("'=HYPERLINK")
        ->toContain('TOTAL');

    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
    $this->getJson("/api/v1/payroll/runs/{$run->public_id}/register")->assertForbidden();
});
