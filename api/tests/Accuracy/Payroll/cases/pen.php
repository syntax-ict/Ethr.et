<?php

// H2 theme PEN. DATA ONLY apart from closures that only copy typed figures into arrays and strings.

$pEmp = fn (int $b, array $more = []): array => $more + ['status' => 'confirmed', 'hire_date' => '2020-01-01', 'termination_date' => null, 'salary_cents' => $b];

$pStd = fn (int $b): array => [
    'in_run' => 1, 'monthly_basic' => $b, 'days_in_period' => 31, 'days_employed' => 31, 'period_basic' => $b,
    'prorated_basic' => $b, 'lateness_step_logged' => 0, 'basic' => $b, 'gross' => $b, 'taxable' => $b, 'pensionable' => $b,
];

$pWk = fn (string $b): array => [
    'in_run = confirmed, salary ' . $b . ' > 0 = 1 (PYR-SAL-05, PYR-SAL-07)',
    'monthly_basic = no history rows, so the salary on the record = ' . $b . ' (PYR-SAL-01)',
    'days_in_period = 2026-10-01 to 2026-10-31 inclusive = 31 (PYR-PER-01)',
    'days_employed = hired 2020-01-01, before the period; no termination = 31 (PYR-PRO-01)',
    'period_basic = full_month, no Pagume day = monthly basic = ' . $b . ' (PYR-PAG-02)',
    'prorated_basic = ' . $b . ' x 31 / 31 = ' . $b . ' (PYR-PRO-02)',
    'lateness_step_logged = deductions switched off = 0 (PYR-LATE-01)',
    'basic = ' . $b . ', no leave and no lateness (PYR-GROSS-01)',
    'gross = ' . $b . ' + 0 + 0 = ' . $b . ' (PYR-GROSS-01)',
    'taxable = ' . $b . ' - 0 = ' . $b . ' (PYR-GROSS-02)',
    'pensionable = basic = ' . $b . ' (PYR-PEN-03, PYR-PEN-15)',
];

$oct = ['start' => '2026-10-01', 'end' => '2026-10-31', 'pagume_days' => 0, 'label' => 'October 2026'];

return [
    [
        'id' => 'GP-PEN-001',
        'rule' => ['PYR-PEN-01', 'PYR-PEN-02', 'PYR-PEN-05', 'PYR-TAX-01', 'PYR-TAX-03'],
        'source' => '07 section 1.2',
        'period' => $oct,
        'org' => ['organization_type' => 'private'],
        'employees' => ['P1' => $pEmp(1234567)],
        'expect' => [
            'P1' => $pStd(1234567) + [
                'taxable' => 1234567, 'income_tax' => 235370, 'pension_exempt' => 0, 'employee_pension' => 86420,
                'employer_pension' => 135802, 'total_deductions' => 321790, 'net' => 912777,
            ],
        ],
        'working' => [
            'P1' => array_merge($pWk('1,234,567'), [
                'income_tax = 1,234,567 is in 1,000,001 to 1,400,000: 1,234,567 x 30 / 100 = 370,370.1, rounded 370,370; less 135,000 = 235,370 (PYR-TAX-01, PYR-TAX-03)',
                'pension_exempt = Ethiopian = 0 (PYR-PEN-15)',
                'employee_pension = private scheme 7%: 1,234,567 x 7 / 100 = 86,419.69, rounded 86,420 (PYR-PEN-01, PYR-PEN-02, PYR-PEN-05)',
                'employer_pension = private scheme 11%: 1,234,567 x 11 / 100 = 135,802.37, rounded 135,802 (PYR-PEN-02, PYR-PEN-05)',
                'total_deductions = 235,370 + 86,420 + 0 + 0 = 321,790 (PYR-NET-01)',
                'net = 1,234,567 - 321,790 = 912,777 (PYR-NET-01)',
            ]),
        ],
    ],
    [
        'id' => 'GP-PEN-002',
        'rule' => ['PYR-PEN-01', 'PYR-PEN-02', 'PYR-PEN-05', 'PYR-TAX-01', 'PYR-TAX-03'],
        'source' => '07 section 1.2',
        'period' => $oct,
        'org' => ['organization_type' => 'government'],
        'employees' => ['P1' => $pEmp(1234567)],
        'expect' => [
            'P1' => $pStd(1234567) + [
                'income_tax' => 235370, 'pension_exempt' => 0, 'employee_pension' => 86420,
                'employer_pension' => 135802, 'total_deductions' => 321790, 'net' => 912777,
            ],
        ],
        'working' => [
            'P1' => array_merge($pWk('1,234,567'), [
                'income_tax = 1,234,567 is in 1,000,001 to 1,400,000: 1,234,567 x 30 / 100 = 370,370.1, rounded 370,370; less 135,000 = 235,370 (PYR-TAX-01, PYR-TAX-03)',
                'pension_exempt = Ethiopian = 0 (PYR-PEN-15)',
                'employee_pension = government kind takes the public scheme, 7%: 1,234,567 x 7 / 100 = 86,419.69, rounded 86,420 (PYR-PEN-01, PYR-PEN-02, PYR-PEN-05)',
                'employer_pension = public scheme 11%: 1,234,567 x 11 / 100 = 135,802.37, rounded 135,802 (PYR-PEN-02, PYR-PEN-05)',
                'total_deductions = 235,370 + 86,420 + 0 + 0 = 321,790 (PYR-NET-01)',
                'net = 1,234,567 - 321,790 = 912,777 (PYR-NET-01)',
            ]),
        ],
    ],
    [
        'id' => 'GP-PEN-003',
        'rule' => ['PYR-PEN-01', 'PYR-PEN-02', 'PYR-PEN-05', 'PYR-TAX-01', 'PYR-TAX-03'],
        'source' => '07 section 1.2',
        'period' => $oct,
        'org' => ['organization_type' => 'defence_police'],
        'employees' => ['P1' => $pEmp(1234567)],
        'expect' => [
            'P1' => $pStd(1234567) + [
                'income_tax' => 235370, 'pension_exempt' => 0, 'employee_pension' => 86420,
                'employer_pension' => 407407, 'total_deductions' => 321790, 'net' => 912777,
            ],
        ],
        'working' => [
            'P1' => array_merge($pWk('1,234,567'), [
                'income_tax = 1,234,567 is in 1,000,001 to 1,400,000: 1,234,567 x 30 / 100 = 370,370.1, rounded 370,370; less 135,000 = 235,370 (PYR-TAX-01, PYR-TAX-03)',
                'pension_exempt = Ethiopian = 0 (PYR-PEN-15)',
                'employee_pension = defence_police takes public_military, employee 7%: 1,234,567 x 7 / 100 = 86,419.69, rounded 86,420 (PYR-PEN-01, PYR-PEN-02, PYR-PEN-05)',
                'employer_pension = public_military employer 33%: 1,234,567 x 33 / 100 = 407,407.11, rounded 407,407 (PYR-PEN-02, PYR-PEN-05)',
                'total_deductions = 235,370 + 86,420 + 0 + 0 = 321,790; the employer share is not taken from the employee (PYR-NET-01, PYR-PEN-12)',
                'net = 1,234,567 - 321,790 = 912,777 (PYR-NET-01)',
            ]),
        ],
    ],
    [
        'id' => 'GP-PEN-004',
        'rule' => ['PYR-PEN-07', 'PYR-PEN-08', 'PYR-PEN-15', 'PYR-PEN-02', 'PYR-PEN-05', 'PYR-TAX-01', 'PYR-TAX-03'],
        'source' => '07 PAY-018',
        'period' => $oct,
        'employees' => [
            'F1' => $pEmp(1000000, ['nationality' => 'foreign']),
            'O1' => $pEmp(1000000, ['nationality' => 'ethiopian_origin']),
            'E1' => $pEmp(1000000, ['nationality' => 'ethiopian']),
        ],
        'expect' => [
            'F1' => $pStd(1000000) + [
                'income_tax' => 165000, 'pension_exempt' => 1, 'employee_pension' => 0, 'employer_pension' => 0,
                'total_deductions' => 165000, 'net' => 835000,
            ],
            'O1' => $pStd(1000000) + [
                'income_tax' => 165000, 'pension_exempt' => 1, 'employee_pension' => 0, 'employer_pension' => 0,
                'total_deductions' => 165000, 'net' => 835000,
            ],
            'E1' => $pStd(1000000) + [
                'income_tax' => 165000, 'pension_exempt' => 0, 'employee_pension' => 70000, 'employer_pension' => 110000,
                'total_deductions' => 235000, 'net' => 765000,
            ],
        ],
        'working' => [
            'F1' => array_merge($pWk('1,000,000'), [
                'income_tax = 1,000,000 is in 700,001 to 1,000,000: 1,000,000 x 25 / 100 = 250,000; less 85,000 = 165,000 (PYR-TAX-01, PYR-TAX-03)',
                'pension_exempt = nationality foreign is exempt = 1 (PYR-PEN-07, PYR-PEN-15)',
                'employee_pension = exempt = 0 (PYR-PEN-07)',
                'employer_pension = exempt = 0 (PYR-PEN-07)',
                'total_deductions = 165,000 + 0 + 0 + 0 = 165,000 (PYR-NET-01)',
                'net = 1,000,000 - 165,000 = 835,000 (PYR-NET-01)',
            ]),
            'O1' => array_merge($pWk('1,000,000'), [
                'income_tax = 1,000,000 is in 700,001 to 1,000,000: 1,000,000 x 25 / 100 = 250,000; less 85,000 = 165,000 (PYR-TAX-01, PYR-TAX-03)',
                'pension_exempt = nationality ethiopian_origin is exempt until P8 is settled = 1 (PYR-PEN-08, PYR-PEN-15)',
                'employee_pension = exempt = 0 (PYR-PEN-08)',
                'employer_pension = exempt = 0 (PYR-PEN-08)',
                'total_deductions = 165,000 + 0 + 0 + 0 = 165,000 (PYR-NET-01)',
                'net = 1,000,000 - 165,000 = 835,000 (PYR-NET-01)',
            ]),
            'E1' => array_merge($pWk('1,000,000'), [
                'income_tax = 1,000,000 is in 700,001 to 1,000,000: 1,000,000 x 25 / 100 = 250,000; less 85,000 = 165,000 (PYR-TAX-01, PYR-TAX-03)',
                'pension_exempt = Ethiopian = 0 (PYR-PEN-15)',
                'employee_pension = 1,000,000 x 7 / 100 = 70,000 (PYR-PEN-02, PYR-PEN-05)',
                'employer_pension = 1,000,000 x 11 / 100 = 110,000 (PYR-PEN-02, PYR-PEN-05)',
                'total_deductions = 165,000 + 70,000 + 0 + 0 = 235,000 (PYR-NET-01)',
                'net = 1,000,000 - 235,000 = 765,000 (PYR-NET-01)',
            ]),
        ],
    ],
    [
        'id' => 'GP-PEN-005',
        'rule' => ['PYR-PEN-09', 'PYR-PEN-02', 'PYR-PEN-05', 'PYR-TAX-01', 'PYR-TAX-03'],
        'source' => 'register',
        'period' => $oct,
        'employees' => [
            'C1' => $pEmp(1000000, ['contracts' => [['type' => 'casual', 'start' => '2020-01-01', 'end' => null, 'ended_at' => null, 'salary_cents' => 0]]]),
            'C2' => $pEmp(1000000, ['contracts' => [['type' => 'consultancy', 'start' => '2020-01-01', 'end' => null, 'ended_at' => null, 'salary_cents' => 0]]]),
        ],
        'expect' => [
            'C1' => $pStd(1000000) + [
                'income_tax' => 165000, 'pension_exempt' => 0, 'employee_pension' => 70000, 'employer_pension' => 110000,
                'total_deductions' => 235000, 'net' => 765000,
            ],
            'C2' => $pStd(1000000) + [
                'income_tax' => 165000, 'pension_exempt' => 0, 'employee_pension' => 70000, 'employer_pension' => 110000,
                'total_deductions' => 235000, 'net' => 765000,
            ],
        ],
        'working' => [
            'C1' => array_merge($pWk('1,000,000'), [
                'income_tax = 1,000,000 is in 700,001 to 1,000,000: 1,000,000 x 25 / 100 = 250,000; less 85,000 = 165,000 (PYR-TAX-01, PYR-TAX-03)',
                'pension_exempt = Ethiopian; a casual contract is still covered = 0 (PYR-PEN-09, PYR-PEN-15)',
                'employee_pension = 1,000,000 x 7 / 100 = 70,000 (PYR-PEN-02, PYR-PEN-05, PYR-PEN-09)',
                'employer_pension = 1,000,000 x 11 / 100 = 110,000 (PYR-PEN-02, PYR-PEN-05, PYR-PEN-09)',
                'total_deductions = 165,000 + 70,000 + 0 + 0 = 235,000 (PYR-NET-01)',
                'net = 1,000,000 - 235,000 = 765,000 (PYR-NET-01)',
            ]),
            'C2' => array_merge($pWk('1,000,000'), [
                'income_tax = 1,000,000 is in 700,001 to 1,000,000: 1,000,000 x 25 / 100 = 250,000; less 85,000 = 165,000 (PYR-TAX-01, PYR-TAX-03)',
                'pension_exempt = Ethiopian; a consultancy contract is still covered = 0 (PYR-PEN-09, PYR-PEN-15)',
                'employee_pension = 1,000,000 x 7 / 100 = 70,000 (PYR-PEN-02, PYR-PEN-05, PYR-PEN-09)',
                'employer_pension = 1,000,000 x 11 / 100 = 110,000 (PYR-PEN-02, PYR-PEN-05, PYR-PEN-09)',
                'total_deductions = 165,000 + 70,000 + 0 + 0 = 235,000 (PYR-NET-01)',
                'net = 1,000,000 - 235,000 = 765,000 (PYR-NET-01)',
            ]),
        ],
    ],
    [
        'id' => 'GP-PEN-006',
        'rule' => ['PYR-PEN-10', 'PYR-PRO-01', 'PYR-PRO-02', 'PYR-PEN-03', 'PYR-PEN-05', 'PYR-TAX-01', 'PYR-TAX-03'],
        'source' => 'register',
        'period' => $oct,
        'employees' => [
            'H1' => $pEmp(1550000, ['hire_date' => '2026-10-22']),
        ],
        'expect' => [
            'H1' => [
                'in_run' => 1, 'monthly_basic' => 1550000, 'days_in_period' => 31, 'days_employed' => 10, 'period_basic' => 1550000,
                'prorated_basic' => 500000, 'lateness_step_logged' => 0, 'basic' => 500000, 'gross' => 500000, 'taxable' => 500000,
                'income_tax' => 50000, 'pension_exempt' => 0, 'pensionable' => 500000, 'employee_pension' => 35000,
                'employer_pension' => 55000, 'total_deductions' => 85000, 'net' => 415000,
            ],
        ],
        'working' => [
            'H1' => [
                'in_run = confirmed, salary 1,550,000 > 0 = 1 (PYR-SAL-05, PYR-SAL-07)',
                'monthly_basic = no history rows, so the salary on the record = 1,550,000 (PYR-SAL-01)',
                'days_in_period = 2026-10-01 to 2026-10-31 inclusive = 31 (PYR-PER-01)',
                'days_employed = hired 2026-10-22 to 2026-10-31 inclusive = 10 (PYR-PRO-01)',
                'period_basic = full_month, no Pagume day = monthly basic = 1,550,000 (PYR-PAG-02)',
                'prorated_basic = 1,550,000 x 10 / 31 = 500,000 exactly (PYR-PRO-02)',
                'lateness_step_logged = deductions switched off = 0 (PYR-LATE-01)',
                'basic = 500,000, no leave and no lateness (PYR-GROSS-01)',
                'gross = 500,000 + 0 + 0 = 500,000 (PYR-GROSS-01)',
                'taxable = 500,000 - 0 = 500,000 (PYR-GROSS-02)',
                'income_tax = 500,000 is in 400,001 to 700,000: 500,000 x 20 / 100 = 100,000; less 50,000 = 50,000 (PYR-TAX-01, PYR-TAX-03)',
                'pension_exempt = Ethiopian = 0; the 45-day rule is not applied (PYR-PEN-10, PYR-PEN-15)',
                'pensionable = basic after proration = 500,000 (PYR-PEN-03)',
                'employee_pension = 500,000 x 7 / 100 = 35,000 (PYR-PEN-02, PYR-PEN-05, PYR-PEN-10)',
                'employer_pension = 500,000 x 11 / 100 = 55,000 (PYR-PEN-02, PYR-PEN-05, PYR-PEN-10)',
                'total_deductions = 50,000 + 35,000 + 0 + 0 = 85,000 (PYR-NET-01)',
                'net = 500,000 - 85,000 = 415,000 (PYR-NET-01)',
            ],
        ],
    ],
    [
        'id' => 'GP-PEN-007',
        'rule' => ['PYR-PEN-11', 'PYR-PEN-01', 'PYR-PEN-02', 'PYR-PEN-05', 'PYR-TAX-01', 'PYR-TAX-03'],
        'source' => 'register',
        'undecided' => true,
        'period' => $oct,
        'org' => ['organization_type' => 'international_organization'],
        'employees' => ['P1' => $pEmp(1000000)],
        'expect' => [
            'P1' => $pStd(1000000) + [
                'income_tax' => 165000, 'pension_exempt' => 0, 'employee_pension' => 70000, 'employer_pension' => 110000,
                'total_deductions' => 235000, 'net' => 765000,
            ],
        ],
        'working' => [
            'P1' => array_merge($pWk('1,000,000'), [
                'income_tax = 1,000,000 is in 700,001 to 1,000,000: 1,000,000 x 25 / 100 = 250,000; less 85,000 = 165,000 (PYR-TAX-01, PYR-TAX-03)',
                'pension_exempt = baseline: international_organization resolves to the private scheme through its parent, Ethiopian = 0 (PYR-PEN-01, PYR-PEN-11, PYR-PEN-15)',
                'undecided: PYR-PEN-11: pension_exempt 1',
                'employee_pension = baseline private 7%: 1,000,000 x 7 / 100 = 70,000 (PYR-PEN-02, PYR-PEN-05)',
                'undecided: PYR-PEN-11: employee_pension 0',
                'employer_pension = baseline private 11%: 1,000,000 x 11 / 100 = 110,000 (PYR-PEN-02, PYR-PEN-05)',
                'undecided: PYR-PEN-11: employer_pension 0',
                'total_deductions = 165,000 + 70,000 + 0 + 0 = 235,000 (PYR-NET-01)',
                'undecided: PYR-PEN-11: total_deductions 165,000',
                'net = 1,000,000 - 235,000 = 765,000 (PYR-NET-01)',
                'undecided: PYR-PEN-11: net 835,000',
            ]),
        ],
    ],
];
