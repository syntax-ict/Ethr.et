<?php

declare(strict_types=1);

/*
 * The worked example of the payroll case format (spec 1.8): the format's own
 * proof, so the harness has one case to run before the real files arrive.
 * Data only.
 */

return [
    [
        'id' => 'GP-NET-001',
        'rule' => ['PYR-SAL-01', 'PYR-PRO-01', 'PYR-PAG-02', 'PYR-RATE-01', 'PYR-LEAVE-01', 'PYR-LEAVE-02', 'PYR-ALW-02', 'PYR-ALW-03', 'PYR-ALW-04', 'PYR-GROSS-01', 'PYR-GROSS-02', 'PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-02', 'PYR-PEN-03', 'PYR-PEN-05', 'PYR-NET-01'],
        'source' => '07 G7',
        'period' => ['start' => '2026-10-01', 'end' => '2026-10-31', 'pagume_days' => 0, 'label' => 'October 2026'],
        'org' => ['allowances' => [
            'transport' => ['type' => 'fixed', 'amount_cents' => 60000, 'taxable' => false],
            'position' => ['type' => 'percentage', 'percent_x100' => 1000, 'taxable' => true],
        ]],
        'employees' => ['G7' => [
            'status' => 'confirmed', 'hire_date' => '2025-01-01', 'termination_date' => null, 'salary_cents' => 880000,
            'inputs' => ['unpaid_leave_thousandths' => 2000],
        ]],
        'expect' => ['G7' => [
            'in_run' => 1, 'salary_changed_in_period' => 0, 'monthly_basic' => 880000, 'days_in_period' => 31, 'days_employed' => 31,
            'period_basic' => 880000, 'prorated_basic' => 880000, 'daily_rate' => 40000, 'unpaid_leave_deduction' => 80000,
            'basic_after_unpaid_leave' => 800000, 'sick_deduction' => 0, 'basic_after_sick_leave' => 800000, 'lateness_step_logged' => 0,
            'basic' => 800000, 'overtime' => 0, 'allowance.transport' => 60000, 'allowance.position' => 80000,
            'allowances_total' => 140000, 'allowances_taxable' => 80000, 'allowances_non_taxable' => 60000,
            'gross' => 940000, 'taxable' => 880000, 'income_tax' => 135000, 'pension_exempt' => 0, 'pensionable' => 800000,
            'employee_pension' => 56000, 'employer_pension' => 88000, 'loans' => 0, 'cost_sharing' => 0,
            'other_deductions' => 0, 'total_deductions' => 191000, 'net' => 749000,
        ]],
        'working' => ['G7' => [
            'in_run = confirmed, salary 880,000 > 0 = 1 (PYR-SAL-04, PYR-SAL-05)',
            'salary_changed_in_period = no history rows = 0 (PYR-SAL-03)',
            'monthly_basic = no history rows, so the salary on the record = 880,000 (PYR-SAL-01)',
            'days_in_period = 2026-10-01 to 2026-10-31 inclusive = 31 (PYR-PER-01)',
            'days_employed = hired 2025-01-01, before the period; no termination = 31 (PYR-PRO-01)',
            'period_basic = no Pagume day, full_month = monthly basic = 880,000 (PYR-PAG-02)',
            'prorated_basic = 880,000 x 31 / 31 = 880,000 (PYR-PRO-02)',
            'daily_rate = 880,000 / 22 = 40,000 exactly (PYR-RATE-01)',
            'unpaid_leave_deduction = 40,000 x 2,000 / 1,000 = 80,000; not above 880,000 (PYR-LEAVE-02, PYR-LEAVE-03)',
            'basic_after_unpaid_leave = 880,000 - 80,000 = 800,000',
            'sick_deduction = no sick days = 0 (PYR-SICK-02)',
            'basic_after_sick_leave = 800,000 - 0 = 800,000',
            'lateness_step_logged = deductions switched off = 0 (PYR-LATE-01)',
            'basic = 800,000 (PYR-GROSS-01)',
            'overtime = no minutes = 0 (PYR-OT-01)',
            'allowance.transport = fixed 60,000, employed 31 of 31 days, not reduced by unpaid leave = 60,000 (PYR-ALW-03)',
            'allowance.position = 800,000 x 1,000 / 10,000 = 80,000 (PYR-ALW-02)',
            'allowances_total = 60,000 + 80,000 = 140,000',
            'allowances_taxable = position 80,000 (PYR-ALW-04)',
            'allowances_non_taxable = transport 60,000 (PYR-ALW-04)',
            'gross = 800,000 + 0 + 140,000 = 940,000 (PYR-GROSS-01)',
            'taxable = 940,000 - 60,000 = 880,000 (PYR-GROSS-02)',
            'income_tax = 880,000 is in 700,001 to 1,000,000: 880,000 x 25 / 100 = 220,000; less 85,000 = 135,000 (PYR-TAX-01, PYR-TAX-03)',
            'pension_exempt = Ethiopian = 0',
            'pensionable = basic = 800,000 (PYR-PEN-03)',
            'employee_pension = 800,000 x 7 / 100 = 56,000 (PYR-PEN-02, PYR-PEN-05)',
            'employer_pension = 800,000 x 11 / 100 = 88,000 (PYR-PEN-02, PYR-PEN-05)',
            'loans = none = 0', 'cost_sharing = none = 0', 'other_deductions = 0 + 0 = 0 (PYR-NET-01)',
            'total_deductions = 135,000 + 56,000 + 0 + 0 = 191,000 (PYR-NET-01)',
            'net = 940,000 - 191,000 = 749,000 (PYR-NET-01)',
        ]],
    ],
];
