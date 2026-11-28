<?php

// H2 theme OT. DATA ONLY apart from closures that only copy typed figures into arrays and strings.

$oEmp = fn (int $b, array $minutes, array $more = []): array => $more + [
    'status' => 'confirmed', 'hire_date' => '2020-01-01', 'termination_date' => null, 'salary_cents' => $b,
    'inputs' => ['overtime_minutes' => $minutes],
];

$oStd = fn (int $b): array => [
    'in_run' => 1, 'monthly_basic' => $b, 'days_in_period' => 31, 'days_employed' => 31, 'period_basic' => $b,
    'prorated_basic' => $b, 'lateness_step_logged' => 0, 'basic' => $b, 'pension_exempt' => 0, 'pensionable' => $b,
];

$oWk = fn (string $b): array => [
    'in_run = confirmed, salary ' . $b . ' > 0 = 1 (PYR-SAL-05, PYR-SAL-07)',
    'monthly_basic = no history rows, so the salary on the record = ' . $b . ' (PYR-SAL-01)',
    'days_in_period = 2026-10-01 to 2026-10-31 inclusive = 31 (PYR-PER-01)',
    'days_employed = hired 2020-01-01, before the period; no termination = 31 (PYR-PRO-01)',
    'period_basic = full_month, no Pagume day = monthly basic = ' . $b . ' (PYR-PAG-02)',
    'prorated_basic = ' . $b . ' x 31 / 31 = ' . $b . ' (PYR-PRO-02)',
    'lateness_step_logged = deductions switched off = 0 (PYR-LATE-01)',
    'basic = ' . $b . ', no leave and no lateness (PYR-GROSS-01)',
    'pension_exempt = Ethiopian = 0 (PYR-PEN-15)',
    'pensionable = basic = ' . $b . ' (PYR-PEN-03)',
];

$oct = ['start' => '2026-10-01', 'end' => '2026-10-31', 'pagume_days' => 0, 'label' => 'October 2026'];

return [
    [
        'id' => 'GP-OT-001',
        'rule' => ['PYR-OT-01', 'PYR-OT-02', 'PYR-GROSS-01', 'PYR-GROSS-02', 'PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-05', 'PYR-NET-01'],
        'source' => '07 G8',
        'period' => $oct,
        'employees' => [
            'O1' => $oEmp(880000, ['normal' => 120, 'night' => 60, 'rest_day' => 90, 'holiday' => 120, 'holiday_night' => 30]),
        ],
        'expect' => [
            'O1' => $oStd(880000) + [
                'overtime_normal' => 15000, 'overtime_night' => 8750, 'overtime_rest_day' => 15000, 'overtime_holiday' => 25000,
                'overtime_holiday_night' => 6250, 'overtime' => 70000, 'gross' => 950000, 'taxable' => 950000, 'income_tax' => 152500,
                'employee_pension' => 61600, 'employer_pension' => 96800, 'total_deductions' => 214100, 'net' => 735900,
            ],
        ],
        'working' => [
            'O1' => array_merge($oWk('880,000'), [
                'overtime_normal = 880,000 x 120 x 1.5 / (22 x 8 x 60) = 158,400,000 / 10,560 = 15,000 (PYR-OT-01, PYR-OT-02 floor 1.5)',
                'overtime_night = 880,000 x 60 x 1.75 / 10,560 = 92,400,000 / 10,560 = 8,750 (PYR-OT-01, PYR-OT-02 floor 1.75)',
                'overtime_rest_day = 880,000 x 90 x 2.0 / 10,560 = 158,400,000 / 10,560 = 15,000 (PYR-OT-01, PYR-OT-02 floor 2.0)',
                'overtime_holiday = 880,000 x 120 x 2.5 / 10,560 = 264,000,000 / 10,560 = 25,000 (PYR-OT-01, PYR-OT-02 floor 2.5)',
                'overtime_holiday_night = 880,000 x 30 x 2.5 / 10,560 = 66,000,000 / 10,560 = 6,250 (PYR-OT-01, PYR-OT-02 floor 2.5)',
                'overtime = 15,000 + 8,750 + 15,000 + 25,000 + 6,250 = 70,000 (PYR-OT-01)',
                'gross = 880,000 + 70,000 + 0 = 950,000 (PYR-GROSS-01)',
                'taxable = 950,000 - 0 = 950,000; overtime is taxable (PYR-GROSS-02)',
                'income_tax = 950,000 is in 700,001 to 1,000,000: 950,000 x 25 / 100 = 237,500; less 85,000 = 152,500 (PYR-TAX-01, PYR-TAX-03)',
                'employee_pension = on basic only: 880,000 x 7 / 100 = 61,600 (PYR-PEN-04, PYR-PEN-05)',
                'employer_pension = 880,000 x 11 / 100 = 96,800 (PYR-PEN-04, PYR-PEN-05)',
                'total_deductions = 152,500 + 61,600 + 0 + 0 = 214,100 (PYR-NET-01)',
                'net = 950,000 - 214,100 = 735,900 (PYR-NET-01)',
            ]),
        ],
    ],
    [
        'id' => 'GP-OT-002',
        'rule' => ['PYR-OT-01', 'PYR-OT-02', 'PYR-OT-03', 'PYR-GROSS-01', 'PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-05', 'PYR-NET-01'],
        'source' => '07 PAY-005',
        'period' => $oct,
        'org' => ['overtime_rates_x100' => ['normal' => 160, 'night' => 200]],
        'employees' => [
            'O1' => $oEmp(880000, ['normal' => 120, 'night' => 60, 'rest_day' => 60]),
        ],
        'expect' => [
            'O1' => $oStd(880000) + [
                'overtime_normal' => 16000, 'overtime_night' => 10000, 'overtime_rest_day' => 10000, 'overtime' => 36000,
                'gross' => 916000, 'taxable' => 916000, 'income_tax' => 144000,
                'employee_pension' => 61600, 'employer_pension' => 96800, 'total_deductions' => 205600, 'net' => 710400,
            ],
        ],
        'working' => [
            'O1' => array_merge($oWk('880,000'), [
                'overtime_normal = own rate 1.60 beats floor 1.5: 880,000 x 120 x 1.6 / 10,560 = 168,960,000 / 10,560 = 16,000 (PYR-OT-01, PYR-OT-02)',
                'overtime_night = own rate 2.00 beats floor 1.75: 880,000 x 60 x 2.0 / 10,560 = 105,600,000 / 10,560 = 10,000 (PYR-OT-01, PYR-OT-02)',
                'overtime_rest_day = no own rate, paid at floor 2.0: 880,000 x 60 x 2.0 / 10,560 = 105,600,000 / 10,560 = 10,000 (PYR-OT-01, PYR-OT-02)',
                'overtime = 16,000 + 10,000 + 10,000 = 36,000 (PYR-OT-01)',
                'gross = 880,000 + 36,000 + 0 = 916,000 (PYR-GROSS-01)',
                'taxable = 916,000 - 0 = 916,000 (PYR-GROSS-02)',
                'income_tax = 916,000 is in 700,001 to 1,000,000: 916,000 x 25 / 100 = 229,000; less 85,000 = 144,000 (PYR-TAX-01, PYR-TAX-03)',
                'employee_pension = 880,000 x 7 / 100 = 61,600 (PYR-PEN-05)',
                'employer_pension = 880,000 x 11 / 100 = 96,800 (PYR-PEN-05)',
                'total_deductions = 144,000 + 61,600 + 0 + 0 = 205,600 (PYR-NET-01)',
                'net = 916,000 - 205,600 = 710,400 (PYR-NET-01)',
            ]),
        ],
    ],
    [
        'id' => 'GP-OT-003',
        'rule' => ['PYR-OT-01', 'PYR-OT-02', 'PYR-GROSS-01', 'PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-05', 'PYR-NET-01'],
        'source' => '07 PAY-005',
        'period' => $oct,
        'org' => ['overtime_rates_x100' => ['normal' => 120, 'holiday' => 200]],
        'employees' => [
            'O1' => $oEmp(880000, ['normal' => 120, 'holiday' => 60]),
        ],
        'expect' => [
            'O1' => $oStd(880000) + [
                'overtime_normal' => 15000, 'overtime_holiday' => 12500, 'overtime' => 27500,
                'gross' => 907500, 'taxable' => 907500, 'income_tax' => 141875,
                'employee_pension' => 61600, 'employer_pension' => 96800, 'total_deductions' => 203475, 'net' => 704025,
            ],
        ],
        'working' => [
            'O1' => array_merge($oWk('880,000'), [
                'overtime_normal = own rate 1.20 is below floor 1.5, so 1.5: 880,000 x 120 x 1.5 / 10,560 = 158,400,000 / 10,560 = 15,000 (PYR-OT-01, PYR-OT-02)',
                'overtime_holiday = own rate 2.00 is below floor 2.5, so 2.5: 880,000 x 60 x 2.5 / 10,560 = 132,000,000 / 10,560 = 12,500 (PYR-OT-01, PYR-OT-02)',
                'overtime = 15,000 + 12,500 = 27,500 (PYR-OT-01)',
                'gross = 880,000 + 27,500 + 0 = 907,500 (PYR-GROSS-01)',
                'taxable = 907,500 - 0 = 907,500 (PYR-GROSS-02)',
                'income_tax = 907,500 is in 700,001 to 1,000,000: 907,500 x 25 / 100 = 226,875; less 85,000 = 141,875 (PYR-TAX-01, PYR-TAX-03)',
                'employee_pension = 880,000 x 7 / 100 = 61,600 (PYR-PEN-05)',
                'employer_pension = 880,000 x 11 / 100 = 96,800 (PYR-PEN-05)',
                'total_deductions = 141,875 + 61,600 + 0 + 0 = 203,475 (PYR-NET-01)',
                'net = 907,500 - 203,475 = 704,025 (PYR-NET-01)',
            ]),
        ],
    ],
    [
        'id' => 'GP-OT-004',
        'rule' => ['PYR-OT-01', 'PYR-OT-04', 'PYR-PRO-01', 'PYR-PRO-02', 'PYR-GROSS-01', 'PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-05', 'PYR-NET-01'],
        'source' => '07 section 1.3',
        'period' => $oct,
        'employees' => [
            'J1' => $oEmp(1550000, ['normal' => 120], ['hire_date' => '2026-10-22']),
        ],
        'expect' => [
            'J1' => [
                'in_run' => 1, 'monthly_basic' => 1550000, 'days_in_period' => 31, 'days_employed' => 10, 'period_basic' => 1550000,
                'prorated_basic' => 500000, 'lateness_step_logged' => 0, 'basic' => 500000,
                'overtime_normal' => 26420, 'overtime' => 26420, 'gross' => 526420, 'taxable' => 526420, 'income_tax' => 55284,
                'pension_exempt' => 0, 'pensionable' => 500000, 'employee_pension' => 35000, 'employer_pension' => 55000,
                'total_deductions' => 90284, 'net' => 436136,
            ],
        ],
        'working' => [
            'J1' => [
                'in_run = confirmed, salary 1,550,000 > 0 = 1 (PYR-SAL-05, PYR-SAL-07)',
                'monthly_basic = no history rows, so the salary on the record = 1,550,000 (PYR-SAL-01)',
                'days_in_period = 2026-10-01 to 2026-10-31 inclusive = 31 (PYR-PER-01)',
                'days_employed = hired 2026-10-22 to 2026-10-31 inclusive = 10 (PYR-PRO-01)',
                'period_basic = full_month, no Pagume day = monthly basic = 1,550,000 (PYR-PAG-02)',
                'prorated_basic = 1,550,000 x 10 / 31 = 500,000 exactly (PYR-PRO-02)',
                'lateness_step_logged = deductions switched off = 0 (PYR-LATE-01)',
                'basic = 500,000, no leave and no lateness (PYR-GROSS-01)',
                'overtime_normal = full monthly basic, not prorated: 1,550,000 x 120 x 1.5 / 10,560 = 279,000,000 / 10,560 = 26,420.45, rounded 26,420 (PYR-OT-01, PYR-OT-04)',
                'overtime = 26,420 (PYR-OT-01)',
                'gross = 500,000 + 26,420 + 0 = 526,420 (PYR-GROSS-01)',
                'taxable = 526,420 - 0 = 526,420 (PYR-GROSS-02)',
                'income_tax = 526,420 is in 400,001 to 700,000: 526,420 x 20 / 100 = 105,284; less 50,000 = 55,284 (PYR-TAX-01, PYR-TAX-03)',
                'pension_exempt = Ethiopian = 0 (PYR-PEN-15)',
                'pensionable = basic after proration = 500,000; overtime is not pensionable (PYR-PEN-03, PYR-PEN-04)',
                'employee_pension = 500,000 x 7 / 100 = 35,000 (PYR-PEN-05)',
                'employer_pension = 500,000 x 11 / 100 = 55,000 (PYR-PEN-05)',
                'total_deductions = 55,284 + 35,000 + 0 + 0 = 90,284 (PYR-NET-01)',
                'net = 526,420 - 90,284 = 436,136 (PYR-NET-01)',
            ],
        ],
    ],
    [
        'id' => 'GP-OT-005',
        'rule' => ['PYR-OT-01', 'PYR-OT-02', 'PYR-ROUND-03', 'PYR-GROSS-01', 'PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-05', 'PYR-NET-01'],
        'source' => '07 PAY-036',
        'period' => $oct,
        'employees' => [
            'O1' => $oEmp(1234567, ['normal' => 47, 'night' => 47, 'rest_day' => 47]),
        ],
        'expect' => [
            'O1' => $oStd(1234567) + [
                'overtime_normal' => 8242, 'overtime_night' => 9616, 'overtime_rest_day' => 10990, 'overtime' => 28848,
                'gross' => 1263415, 'taxable' => 1263415, 'income_tax' => 244025,
                'employee_pension' => 86420, 'employer_pension' => 135802, 'total_deductions' => 330445, 'net' => 932970,
            ],
        ],
        'working' => [
            'O1' => array_merge($oWk('1,234,567'), [
                'overtime_normal = 1,234,567 x 47 x 1.5 / 10,560 = 87,036,973.5 / 10,560 = 8,242.14, rounded 8,242 (PYR-OT-01)',
                'overtime_night = 1,234,567 x 47 x 1.75 / 10,560 = 101,543,135.75 / 10,560 = 9,615.83, rounded 9,616 (PYR-OT-01)',
                'overtime_rest_day = 1,234,567 x 47 x 2.0 / 10,560 = 116,049,298 / 10,560 = 10,989.52, rounded 10,990 (PYR-OT-01)',
                'overtime = 8,242 + 9,616 + 10,990 = 28,848; each kind rounded once, the sum not rounded again (PYR-OT-01, PYR-ROUND-03)',
                'gross = 1,234,567 + 28,848 + 0 = 1,263,415 (PYR-GROSS-01)',
                'taxable = 1,263,415 - 0 = 1,263,415 (PYR-GROSS-02)',
                'income_tax = 1,263,415 is in 1,000,001 to 1,400,000: 1,263,415 x 30 / 100 = 379,024.5, rounded half away 379,025; less 135,000 = 244,025 (PYR-TAX-01, PYR-TAX-03)',
                'employee_pension = 1,234,567 x 7 / 100 = 86,419.69, rounded 86,420 (PYR-PEN-05)',
                'employer_pension = 1,234,567 x 11 / 100 = 135,802.37, rounded 135,802 (PYR-PEN-05)',
                'total_deductions = 244,025 + 86,420 + 0 + 0 = 330,445 (PYR-NET-01)',
                'net = 1,263,415 - 330,445 = 932,970 (PYR-NET-01)',
            ]),
        ],
    ],
    [
        'id' => 'GP-OT-006',
        'rule' => ['PYR-OT-01', 'PYR-OT-02', 'PYR-GROSS-01', 'PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-05', 'PYR-NET-01'],
        'source' => 'register',
        'period' => $oct,
        'employees' => [
            'O1' => $oEmp(880000, ['normal' => 100, 'night' => 140]),
        ],
        'expect' => [
            'O1' => $oStd(880000) + [
                'overtime_normal' => 12500, 'overtime_night' => 20417, 'overtime' => 32917,
                'gross' => 912917, 'taxable' => 912917, 'income_tax' => 143229,
                'employee_pension' => 61600, 'employer_pension' => 96800, 'total_deductions' => 204829, 'net' => 708088,
            ],
        ],
        'working' => [
            'O1' => array_merge($oWk('880,000'), [
                'overtime_normal = 880,000 x 100 x 1.5 / 10,560 = 132,000,000 / 10,560 = 12,500 (PYR-OT-01, PYR-OT-02)',
                'overtime_night = 880,000 x 140 x 1.75 / 10,560 = 215,600,000 / 10,560 = 20,416.67, rounded 20,417 (PYR-OT-01, PYR-OT-02)',
                'overtime = 12,500 + 20,417 = 32,917 (PYR-OT-01)',
                'gross = 880,000 + 32,917 + 0 = 912,917 (PYR-GROSS-01)',
                'taxable = 912,917 - 0 = 912,917 (PYR-GROSS-02)',
                'income_tax = 912,917 is in 700,001 to 1,000,000: 912,917 x 25 / 100 = 228,229.25, rounded 228,229; less 85,000 = 143,229 (PYR-TAX-01, PYR-TAX-03)',
                'employee_pension = 880,000 x 7 / 100 = 61,600 (PYR-PEN-05)',
                'employer_pension = 880,000 x 11 / 100 = 96,800 (PYR-PEN-05)',
                'total_deductions = 143,229 + 61,600 + 0 + 0 = 204,829 (PYR-NET-01)',
                'net = 912,917 - 204,829 = 708,088 (PYR-NET-01)',
            ]),
        ],
    ],
    [
        'id' => 'GP-OT-007',
        'rule' => ['PYR-OT-01', 'PYR-OT-02', 'PYR-ROUND-01', 'PYR-ROUND-03', 'PYR-GROSS-01', 'PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-05', 'PYR-NET-01'],
        'source' => 'register',
        'period' => $oct,
        'employees' => [
            'O1' => $oEmp(100000, ['normal' => 10, 'night' => 10]),
            'O2' => $oEmp(355520, ['normal' => 1, 'night' => 1, 'rest_day' => 1]),
            'O3' => $oEmp(250000, ['normal' => 1, 'night' => 1, 'holiday' => 1]),
        ],
        'expect' => [
            'O1' => $oStd(100000) + [
                'overtime_normal' => 142, 'overtime_night' => 166, 'overtime' => 308, 'gross' => 100308, 'taxable' => 100308,
                'income_tax' => 0, 'employee_pension' => 7000, 'employer_pension' => 11000, 'total_deductions' => 7000, 'net' => 93308,
            ],
            'O2' => $oStd(355520) + [
                'overtime_normal' => 51, 'overtime_night' => 59, 'overtime_rest_day' => 67, 'overtime' => 177, 'gross' => 355697, 'taxable' => 355697,
                'income_tax' => 23355, 'employee_pension' => 24886, 'employer_pension' => 39107, 'total_deductions' => 48241, 'net' => 307456,
            ],
            'O3' => $oStd(250000) + [
                'overtime_normal' => 36, 'overtime_night' => 41, 'overtime_holiday' => 59, 'overtime' => 136, 'gross' => 250136, 'taxable' => 250136,
                'income_tax' => 7520, 'employee_pension' => 17500, 'employer_pension' => 27500, 'total_deductions' => 25020, 'net' => 225116,
            ],
        ],
        'working' => [
            'O1' => array_merge($oWk('100,000'), [
                'overtime_normal = 100,000 x 10 x 1.5 / 10,560 = 1,500,000 / 10,560 = 142.045, below half, rounded 142 (rounding up would give 143) (PYR-OT-01)',
                'overtime_night = 100,000 x 10 x 1.75 / 10,560 = 1,750,000 / 10,560 = 165.72, above half, rounded 166 (truncating would give 165) (PYR-OT-01)',
                'overtime = 142 + 166 = 308 (PYR-OT-01)',
                'gross = 100,000 + 308 + 0 = 100,308 (PYR-GROSS-01)',
                'taxable = 100,308 - 0 = 100,308 (PYR-GROSS-02)',
                'income_tax = 100,308 is in 0 to 200,000 at 0% = 0 (PYR-TAX-01)',
                'employee_pension = 100,000 x 7 / 100 = 7,000 (PYR-PEN-05)',
                'employer_pension = 100,000 x 11 / 100 = 11,000 (PYR-PEN-05)',
                'total_deductions = 0 + 7,000 + 0 + 0 = 7,000 (PYR-NET-01)',
                'net = 100,308 - 7,000 = 93,308 (PYR-NET-01)',
            ]),
            'O2' => array_merge($oWk('355,520'), [
                'overtime_normal = 355,520 x 1 x 1.5 / 10,560 = 533,280 / 10,560 = 50.5, exactly half, rounded away from zero 51 (rounding down would give 50) (PYR-OT-01)',
                'overtime_night = 355,520 x 1 x 1.75 / 10,560 = 622,160 / 10,560 = 58.92, above half, rounded 59 (truncating would give 58) (PYR-OT-01)',
                'overtime_rest_day = 355,520 x 1 x 2.0 / 10,560 = 711,040 / 10,560 = 67.33, below half, rounded 67 (rounding up would give 68) (PYR-OT-01)',
                'overtime = 51 + 59 + 67 = 177 (PYR-OT-01)',
                'gross = 355,520 + 177 + 0 = 355,697 (PYR-GROSS-01)',
                'taxable = 355,697 - 0 = 355,697 (PYR-GROSS-02)',
                'income_tax = 355,697 is in 200,001 to 400,000: 355,697 x 15 / 100 = 53,354.55, rounded 53,355; less 30,000 = 23,355 (PYR-TAX-01, PYR-TAX-03)',
                'employee_pension = 355,520 x 7 / 100 = 24,886.4, rounded 24,886 (PYR-PEN-05)',
                'employer_pension = 355,520 x 11 / 100 = 39,107.2, rounded 39,107 (PYR-PEN-05)',
                'total_deductions = 23,355 + 24,886 + 0 + 0 = 48,241 (PYR-NET-01)',
                'net = 355,697 - 48,241 = 307,456 (PYR-NET-01)',
            ]),
            'O3' => array_merge($oWk('250,000'), [
                'overtime_normal = 250,000 x 1 x 1.5 / 10,560 = 375,000 / 10,560 = 35.51, above half, rounded 36 (truncating would give 35) (PYR-OT-01)',
                'overtime_night = 250,000 x 1 x 1.75 / 10,560 = 437,500 / 10,560 = 41.43, below half, rounded 41 (rounding up would give 42) (PYR-OT-01)',
                'overtime_holiday = 250,000 x 1 x 2.5 / 10,560 = 625,000 / 10,560 = 59.19, below half, rounded 59 (rounding up would give 60) (PYR-OT-01)',
                'overtime = 36 + 41 + 59 = 136 (PYR-OT-01)',
                'gross = 250,000 + 136 + 0 = 250,136 (PYR-GROSS-01)',
                'taxable = 250,136 - 0 = 250,136 (PYR-GROSS-02)',
                'income_tax = 250,136 is in 200,001 to 400,000: 250,136 x 15 / 100 = 37,520.4, rounded 37,520; less 30,000 = 7,520 (PYR-TAX-01, PYR-TAX-03)',
                'employee_pension = 250,000 x 7 / 100 = 17,500 (PYR-PEN-05)',
                'employer_pension = 250,000 x 11 / 100 = 27,500 (PYR-PEN-05)',
                'total_deductions = 7,520 + 17,500 + 0 + 0 = 25,020 (PYR-NET-01)',
                'net = 250,136 - 25,020 = 225,116 (PYR-NET-01)',
            ]),
        ],
    ],
];
