<?php

// H2 theme TAX. DATA ONLY apart from three closures that only copy typed figures into arrays and strings.

$tEmp = fn (int $b): array => ['status' => 'confirmed', 'hire_date' => '2020-01-01', 'termination_date' => null, 'salary_cents' => $b];

$tStd = fn (int $b, int $d): array => [
    'in_run' => 1, 'monthly_basic' => $b, 'days_in_period' => $d, 'days_employed' => $d, 'period_basic' => $b,
    'prorated_basic' => $b, 'lateness_step_logged' => 0, 'basic' => $b, 'gross' => $b, 'taxable' => $b,
    'pension_exempt' => 0, 'pensionable' => $b,
];

$tEx = fn (int $tax, int $ep, int $er, int $td, int $net): array => [
    'income_tax' => $tax, 'employee_pension' => $ep, 'employer_pension' => $er, 'total_deductions' => $td, 'net' => $net,
];

$tWk = fn (string $b, string $d): array => [
    'in_run = confirmed, salary '.$b.' > 0 = 1 (PYR-SAL-05, PYR-SAL-07)',
    'monthly_basic = no history rows, so the salary on the record = '.$b.' (PYR-SAL-01)',
    'days_in_period = start to end inclusive = '.$d.' (PYR-PER-01)',
    'days_employed = hired 2020-01-01, before the period; no termination = '.$d.' (PYR-PRO-01)',
    'period_basic = full_month, no Pagume day = monthly basic = '.$b.' (PYR-PAG-02)',
    'prorated_basic = '.$b.' x '.$d.' / '.$d.' = '.$b.' (PYR-PRO-02)',
    'lateness_step_logged = deductions switched off = 0 (PYR-LATE-01)',
    'basic = '.$b.', no leave and no lateness (PYR-GROSS-01)',
    'gross = '.$b.' + 0 + 0 = '.$b.' (PYR-GROSS-01)',
    'taxable = '.$b.' - 0 = '.$b.' (PYR-GROSS-02)',
    'pension_exempt = Ethiopian = 0 (PYR-PEN-15)',
    'pensionable = basic = '.$b.' (PYR-PEN-03)',
];

$tPw = fn (string $tax, string $ep, string $er, string $td, string $net): array => [
    'income_tax = '.$tax,
    'employee_pension = '.$ep,
    'employer_pension = '.$er,
    'total_deductions = '.$td,
    'net = '.$net,
];

$oct = ['start' => '2026-10-01', 'end' => '2026-10-31', 'pagume_days' => 0, 'label' => 'October 2026'];
$jun25 = ['start' => '2025-06-01', 'end' => '2025-06-30', 'pagume_days' => 0, 'label' => 'June 2025'];

return [
    [
        'id' => 'GP-TAX-001',
        'rule' => ['PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => $oct,
        'employees' => [
            'T1' => $tEmp(200000),
            'T2' => $tEmp(200001),
            'T3' => $tEmp(1),
            'T4' => $tEmp(200010),
        ],
        'expect' => [
            'T1' => $tStd(200000, 31) + $tEx(0, 14000, 22000, 14000, 186000),
            'T2' => $tStd(200001, 31) + $tEx(0, 14000, 22000, 14000, 186001),
            'T3' => $tStd(1, 31) + $tEx(0, 0, 0, 0, 1),
            'T4' => $tStd(200010, 31) + $tEx(2, 14001, 22001, 14003, 186007),
        ],
        'working' => [
            'T1' => array_merge($tWk('200,000', '31'), $tPw(
                '200,000 is in 0 to 200,000 at 0% = 0 (PYR-TAX-01, PYR-TAX-03)',
                '200,000 x 7 / 100 = 14,000 (PYR-PEN-02, PYR-PEN-05)',
                '200,000 x 11 / 100 = 22,000 (PYR-PEN-02, PYR-PEN-05)',
                '0 + 14,000 + 0 + 0 = 14,000 (PYR-NET-01)',
                '200,000 - 14,000 = 186,000 (PYR-NET-01)'
            )),
            'T2' => array_merge($tWk('200,001', '31'), $tPw(
                '200,001 is in 200,001 to 400,000: 200,001 x 15 / 100 = 30,000.15, rounded 30,000; less 30,000 = 0 (PYR-TAX-01, PYR-TAX-03)',
                '200,001 x 7 / 100 = 14,000.07, rounded 14,000 (PYR-PEN-05)',
                '200,001 x 11 / 100 = 22,000.11, rounded 22,000 (PYR-PEN-05)',
                '0 + 14,000 + 0 + 0 = 14,000 (PYR-NET-01)',
                '200,001 - 14,000 = 186,001 (PYR-NET-01)'
            )),
            'T3' => array_merge($tWk('1', '31'), $tPw(
                '1 is in 0 to 200,000 at 0% = 0 (PYR-TAX-01)',
                '1 x 7 / 100 = 0.07, rounded 0 (PYR-PEN-05)',
                '1 x 11 / 100 = 0.11, rounded 0 (PYR-PEN-05)',
                '0 + 0 + 0 + 0 = 0 (PYR-NET-01)',
                '1 - 0 = 1 (PYR-NET-01)'
            )),
            'T4' => array_merge($tWk('200,010', '31'), $tPw(
                '200,010 is in 200,001 to 400,000: 200,010 x 15 / 100 = 30,001.5, rounded half away 30,002; less 30,000 = 2 (PYR-TAX-01, PYR-TAX-03)',
                '200,010 x 7 / 100 = 14,000.7, rounded 14,001 (PYR-PEN-05)',
                '200,010 x 11 / 100 = 22,001.1, rounded 22,001 (PYR-PEN-05)',
                '2 + 14,001 + 0 + 0 = 14,003 (PYR-NET-01)',
                '200,010 - 14,003 = 186,007 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-002',
        'rule' => ['PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => $oct,
        'employees' => [
            'T1' => $tEmp(400000),
            'T2' => $tEmp(400001),
        ],
        'expect' => [
            'T1' => $tStd(400000, 31) + $tEx(30000, 28000, 44000, 58000, 342000),
            'T2' => $tStd(400001, 31) + $tEx(30000, 28000, 44000, 58000, 342001),
        ],
        'working' => [
            'T1' => array_merge($tWk('400,000', '31'), $tPw(
                '400,000 is in 200,001 to 400,000: 400,000 x 15 / 100 = 60,000; less 30,000 = 30,000 (PYR-TAX-01, PYR-TAX-03)',
                '400,000 x 7 / 100 = 28,000 (PYR-PEN-05)',
                '400,000 x 11 / 100 = 44,000 (PYR-PEN-05)',
                '30,000 + 28,000 + 0 + 0 = 58,000 (PYR-NET-01)',
                '400,000 - 58,000 = 342,000 (PYR-NET-01)'
            )),
            'T2' => array_merge($tWk('400,001', '31'), $tPw(
                '400,001 is in 400,001 to 700,000: 400,001 x 20 / 100 = 80,000.2, rounded 80,000; less 50,000 = 30,000 (PYR-TAX-01, PYR-TAX-03)',
                '400,001 x 7 / 100 = 28,000.07, rounded 28,000 (PYR-PEN-05)',
                '400,001 x 11 / 100 = 44,000.11, rounded 44,000 (PYR-PEN-05)',
                '30,000 + 28,000 + 0 + 0 = 58,000 (PYR-NET-01)',
                '400,001 - 58,000 = 342,001 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-003',
        'rule' => ['PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => $oct,
        'employees' => [
            'T1' => $tEmp(700000),
            'T2' => $tEmp(700001),
        ],
        'expect' => [
            'T1' => $tStd(700000, 31) + $tEx(90000, 49000, 77000, 139000, 561000),
            'T2' => $tStd(700001, 31) + $tEx(90000, 49000, 77000, 139000, 561001),
        ],
        'working' => [
            'T1' => array_merge($tWk('700,000', '31'), $tPw(
                '700,000 is in 400,001 to 700,000: 700,000 x 20 / 100 = 140,000; less 50,000 = 90,000 (PYR-TAX-01, PYR-TAX-03)',
                '700,000 x 7 / 100 = 49,000 (PYR-PEN-05)',
                '700,000 x 11 / 100 = 77,000 (PYR-PEN-05)',
                '90,000 + 49,000 + 0 + 0 = 139,000 (PYR-NET-01)',
                '700,000 - 139,000 = 561,000 (PYR-NET-01)'
            )),
            'T2' => array_merge($tWk('700,001', '31'), $tPw(
                '700,001 is in 700,001 to 1,000,000: 700,001 x 25 / 100 = 175,000.25, rounded 175,000; less 85,000 = 90,000 (PYR-TAX-01, PYR-TAX-03)',
                '700,001 x 7 / 100 = 49,000.07, rounded 49,000 (PYR-PEN-05)',
                '700,001 x 11 / 100 = 77,000.11, rounded 77,000 (PYR-PEN-05)',
                '90,000 + 49,000 + 0 + 0 = 139,000 (PYR-NET-01)',
                '700,001 - 139,000 = 561,001 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-004',
        'rule' => ['PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => $oct,
        'employees' => [
            'T1' => $tEmp(1000000),
            'T2' => $tEmp(1000001),
        ],
        'expect' => [
            'T1' => $tStd(1000000, 31) + $tEx(165000, 70000, 110000, 235000, 765000),
            'T2' => $tStd(1000001, 31) + $tEx(165000, 70000, 110000, 235000, 765001),
        ],
        'working' => [
            'T1' => array_merge($tWk('1,000,000', '31'), $tPw(
                '1,000,000 is in 700,001 to 1,000,000: 1,000,000 x 25 / 100 = 250,000; less 85,000 = 165,000 (PYR-TAX-01, PYR-TAX-03)',
                '1,000,000 x 7 / 100 = 70,000 (PYR-PEN-05)',
                '1,000,000 x 11 / 100 = 110,000 (PYR-PEN-05)',
                '165,000 + 70,000 + 0 + 0 = 235,000 (PYR-NET-01)',
                '1,000,000 - 235,000 = 765,000 (PYR-NET-01)'
            )),
            'T2' => array_merge($tWk('1,000,001', '31'), $tPw(
                '1,000,001 is in 1,000,001 to 1,400,000: 1,000,001 x 30 / 100 = 300,000.3, rounded 300,000; less 135,000 = 165,000 (PYR-TAX-01, PYR-TAX-03)',
                '1,000,001 x 7 / 100 = 70,000.07, rounded 70,000 (PYR-PEN-05)',
                '1,000,001 x 11 / 100 = 110,000.11, rounded 110,000 (PYR-PEN-05)',
                '165,000 + 70,000 + 0 + 0 = 235,000 (PYR-NET-01)',
                '1,000,001 - 235,000 = 765,001 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-005',
        'rule' => ['PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => $oct,
        'employees' => [
            'T1' => $tEmp(1400000),
            'T2' => $tEmp(1400001),
        ],
        'expect' => [
            'T1' => $tStd(1400000, 31) + $tEx(285000, 98000, 154000, 383000, 1017000),
            'T2' => $tStd(1400001, 31) + $tEx(285000, 98000, 154000, 383000, 1017001),
        ],
        'working' => [
            'T1' => array_merge($tWk('1,400,000', '31'), $tPw(
                '1,400,000 is in 1,000,001 to 1,400,000: 1,400,000 x 30 / 100 = 420,000; less 135,000 = 285,000 (PYR-TAX-01, PYR-TAX-03)',
                '1,400,000 x 7 / 100 = 98,000 (PYR-PEN-05)',
                '1,400,000 x 11 / 100 = 154,000 (PYR-PEN-05)',
                '285,000 + 98,000 + 0 + 0 = 383,000 (PYR-NET-01)',
                '1,400,000 - 383,000 = 1,017,000 (PYR-NET-01)'
            )),
            'T2' => array_merge($tWk('1,400,001', '31'), $tPw(
                '1,400,001 is in 1,400,001 and over: 1,400,001 x 35 / 100 = 490,000.35, rounded 490,000; less 205,000 = 285,000 (PYR-TAX-01, PYR-TAX-03)',
                '1,400,001 x 7 / 100 = 98,000.07, rounded 98,000 (PYR-PEN-05)',
                '1,400,001 x 11 / 100 = 154,000.11, rounded 154,000 (PYR-PEN-05)',
                '285,000 + 98,000 + 0 + 0 = 383,000 (PYR-NET-01)',
                '1,400,001 - 383,000 = 1,017,001 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-006',
        'rule' => ['PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-02', 'PYR-PEN-05', 'PYR-ROUND-01'],
        'source' => '07 RND-001',
        'period' => $oct,
        'employees' => [
            'T1' => $tEmp(350050),
        ],
        'expect' => [
            'T1' => $tStd(350050, 31) + $tEx(22508, 24504, 38506, 47012, 303038),
        ],
        'working' => [
            'T1' => array_merge($tWk('350,050', '31'), $tPw(
                '350,050 is in 200,001 to 400,000: 350,050 x 15 / 100 = 52,507.5, rounded half away 52,508; less 30,000 = 22,508 (PYR-TAX-01, PYR-TAX-03)',
                '350,050 x 7 / 100 = 24,503.5, rounded half away 24,504 (PYR-PEN-05)',
                '350,050 x 11 / 100 = 38,505.5, rounded half away 38,506 (PYR-PEN-05)',
                '22,508 + 24,504 + 0 + 0 = 47,012 (PYR-NET-01)',
                '350,050 - 47,012 = 303,038 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-007',
        'rule' => ['PYR-TAX-02', 'PYR-TAX-03', 'PYR-PER-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => $jun25,
        'employees' => [
            'T1' => $tEmp(60000),
            'T2' => $tEmp(60001),
            'T3' => $tEmp(165000),
            'T4' => $tEmp(165001),
            'T5' => $tEmp(320000),
            'T6' => $tEmp(320001),
        ],
        'expect' => [
            'T1' => $tStd(60000, 30) + $tEx(0, 4200, 6600, 4200, 55800),
            'T2' => $tStd(60001, 30) + $tEx(0, 4200, 6600, 4200, 55801),
            'T3' => $tStd(165000, 30) + $tEx(10500, 11550, 18150, 22050, 142950),
            'T4' => $tStd(165001, 30) + $tEx(10500, 11550, 18150, 22050, 142951),
            'T5' => $tStd(320000, 30) + $tEx(33750, 22400, 35200, 56150, 263850),
            'T6' => $tStd(320001, 30) + $tEx(33750, 22400, 35200, 56150, 263851),
        ],
        'working' => [
            'T1' => array_merge($tWk('60,000', '30'), $tPw(
                'June 2025 starts before 2025-07-07: 979/2016 ladder. 60,000 is in 0 to 60,000 at 0% = 0 (PYR-TAX-02, PYR-TAX-04)',
                '60,000 x 7 / 100 = 4,200 (PYR-PEN-05)',
                '60,000 x 11 / 100 = 6,600 (PYR-PEN-05)',
                '0 + 4,200 + 0 + 0 = 4,200 (PYR-NET-01)',
                '60,000 - 4,200 = 55,800 (PYR-NET-01)'
            )),
            'T2' => array_merge($tWk('60,001', '30'), $tPw(
                '979/2016 ladder. 60,001 is in 60,001 to 165,000: 60,001 x 10 / 100 = 6,000.1, rounded 6,000; less 6,000 = 0 (PYR-TAX-02, PYR-TAX-03)',
                '60,001 x 7 / 100 = 4,200.07, rounded 4,200 (PYR-PEN-05)',
                '60,001 x 11 / 100 = 6,600.11, rounded 6,600 (PYR-PEN-05)',
                '0 + 4,200 + 0 + 0 = 4,200 (PYR-NET-01)',
                '60,001 - 4,200 = 55,801 (PYR-NET-01)'
            )),
            'T3' => array_merge($tWk('165,000', '30'), $tPw(
                '979/2016 ladder. 165,000 is in 60,001 to 165,000: 165,000 x 10 / 100 = 16,500; less 6,000 = 10,500 (PYR-TAX-02, PYR-TAX-03)',
                '165,000 x 7 / 100 = 11,550 (PYR-PEN-05)',
                '165,000 x 11 / 100 = 18,150 (PYR-PEN-05)',
                '10,500 + 11,550 + 0 + 0 = 22,050 (PYR-NET-01)',
                '165,000 - 22,050 = 142,950 (PYR-NET-01)'
            )),
            'T4' => array_merge($tWk('165,001', '30'), $tPw(
                '979/2016 ladder. 165,001 is in 165,001 to 320,000: 165,001 x 15 / 100 = 24,750.15, rounded 24,750; less 14,250 = 10,500 (PYR-TAX-02, PYR-TAX-03)',
                '165,001 x 7 / 100 = 11,550.07, rounded 11,550 (PYR-PEN-05)',
                '165,001 x 11 / 100 = 18,150.11, rounded 18,150 (PYR-PEN-05)',
                '10,500 + 11,550 + 0 + 0 = 22,050 (PYR-NET-01)',
                '165,001 - 22,050 = 142,951 (PYR-NET-01)'
            )),
            'T5' => array_merge($tWk('320,000', '30'), $tPw(
                '979/2016 ladder. 320,000 is in 165,001 to 320,000: 320,000 x 15 / 100 = 48,000; less 14,250 = 33,750 (PYR-TAX-02, PYR-TAX-03)',
                '320,000 x 7 / 100 = 22,400 (PYR-PEN-05)',
                '320,000 x 11 / 100 = 35,200 (PYR-PEN-05)',
                '33,750 + 22,400 + 0 + 0 = 56,150 (PYR-NET-01)',
                '320,000 - 56,150 = 263,850 (PYR-NET-01)'
            )),
            'T6' => array_merge($tWk('320,001', '30'), $tPw(
                '979/2016 ladder. 320,001 is in 320,001 to 525,000: 320,001 x 20 / 100 = 64,000.2, rounded 64,000; less 30,250 = 33,750 (PYR-TAX-02, PYR-TAX-03)',
                '320,001 x 7 / 100 = 22,400.07, rounded 22,400 (PYR-PEN-05)',
                '320,001 x 11 / 100 = 35,200.11, rounded 35,200 (PYR-PEN-05)',
                '33,750 + 22,400 + 0 + 0 = 56,150 (PYR-NET-01)',
                '320,001 - 56,150 = 263,851 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-008',
        'rule' => ['PYR-TAX-02', 'PYR-TAX-03', 'PYR-PER-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => $jun25,
        'employees' => [
            'T1' => $tEmp(525000),
            'T2' => $tEmp(525001),
            'T3' => $tEmp(780000),
            'T4' => $tEmp(780001),
            'T5' => $tEmp(1090000),
            'T6' => $tEmp(1090001),
        ],
        'expect' => [
            'T1' => $tStd(525000, 30) + $tEx(74750, 36750, 57750, 111500, 413500),
            'T2' => $tStd(525001, 30) + $tEx(74750, 36750, 57750, 111500, 413501),
            'T3' => $tStd(780000, 30) + $tEx(138500, 54600, 85800, 193100, 586900),
            'T4' => $tStd(780001, 30) + $tEx(138500, 54600, 85800, 193100, 586901),
            'T5' => $tStd(1090000, 30) + $tEx(231500, 76300, 119900, 307800, 782200),
            'T6' => $tStd(1090001, 30) + $tEx(231500, 76300, 119900, 307800, 782201),
        ],
        'working' => [
            'T1' => array_merge($tWk('525,000', '30'), $tPw(
                '979/2016 ladder. 525,000 is in 320,001 to 525,000: 525,000 x 20 / 100 = 105,000; less 30,250 = 74,750 (PYR-TAX-02, PYR-TAX-03)',
                '525,000 x 7 / 100 = 36,750 (PYR-PEN-05)',
                '525,000 x 11 / 100 = 57,750 (PYR-PEN-05)',
                '74,750 + 36,750 + 0 + 0 = 111,500 (PYR-NET-01)',
                '525,000 - 111,500 = 413,500 (PYR-NET-01)'
            )),
            'T2' => array_merge($tWk('525,001', '30'), $tPw(
                '979/2016 ladder. 525,001 is in 525,001 to 780,000: 525,001 x 25 / 100 = 131,250.25, rounded 131,250; less 56,500 = 74,750 (PYR-TAX-02, PYR-TAX-03)',
                '525,001 x 7 / 100 = 36,750.07, rounded 36,750 (PYR-PEN-05)',
                '525,001 x 11 / 100 = 57,750.11, rounded 57,750 (PYR-PEN-05)',
                '74,750 + 36,750 + 0 + 0 = 111,500 (PYR-NET-01)',
                '525,001 - 111,500 = 413,501 (PYR-NET-01)'
            )),
            'T3' => array_merge($tWk('780,000', '30'), $tPw(
                '979/2016 ladder. 780,000 is in 525,001 to 780,000: 780,000 x 25 / 100 = 195,000; less 56,500 = 138,500 (PYR-TAX-02, PYR-TAX-03)',
                '780,000 x 7 / 100 = 54,600 (PYR-PEN-05)',
                '780,000 x 11 / 100 = 85,800 (PYR-PEN-05)',
                '138,500 + 54,600 + 0 + 0 = 193,100 (PYR-NET-01)',
                '780,000 - 193,100 = 586,900 (PYR-NET-01)'
            )),
            'T4' => array_merge($tWk('780,001', '30'), $tPw(
                '979/2016 ladder. 780,001 is in 780,001 to 1,090,000: 780,001 x 30 / 100 = 234,000.3, rounded 234,000; less 95,500 = 138,500 (PYR-TAX-02, PYR-TAX-03)',
                '780,001 x 7 / 100 = 54,600.07, rounded 54,600 (PYR-PEN-05)',
                '780,001 x 11 / 100 = 85,800.11, rounded 85,800 (PYR-PEN-05)',
                '138,500 + 54,600 + 0 + 0 = 193,100 (PYR-NET-01)',
                '780,001 - 193,100 = 586,901 (PYR-NET-01)'
            )),
            'T5' => array_merge($tWk('1,090,000', '30'), $tPw(
                '979/2016 ladder. 1,090,000 is in 780,001 to 1,090,000: 1,090,000 x 30 / 100 = 327,000; less 95,500 = 231,500 (PYR-TAX-02, PYR-TAX-03)',
                '1,090,000 x 7 / 100 = 76,300 (PYR-PEN-05)',
                '1,090,000 x 11 / 100 = 119,900 (PYR-PEN-05)',
                '231,500 + 76,300 + 0 + 0 = 307,800 (PYR-NET-01)',
                '1,090,000 - 307,800 = 782,200 (PYR-NET-01)'
            )),
            'T6' => array_merge($tWk('1,090,001', '30'), $tPw(
                '979/2016 ladder. 1,090,001 is in 1,090,001 and over: 1,090,001 x 35 / 100 = 381,500.35, rounded 381,500; less 150,000 = 231,500 (PYR-TAX-02, PYR-TAX-03)',
                '1,090,001 x 7 / 100 = 76,300.07, rounded 76,300 (PYR-PEN-05)',
                '1,090,001 x 11 / 100 = 119,900.11, rounded 119,900 (PYR-PEN-05)',
                '231,500 + 76,300 + 0 + 0 = 307,800 (PYR-NET-01)',
                '1,090,001 - 307,800 = 782,201 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-009',
        'rule' => ['PYR-TAX-02', 'PYR-TAX-03', 'PYR-TAX-04', 'PYR-PER-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'PYR-TAX-04 example',
        'period' => ['start' => '2025-07-01', 'end' => '2025-07-31', 'pagume_days' => 0, 'label' => 'July 2025'],
        'employees' => [
            'T1' => $tEmp(500000),
        ],
        'expect' => [
            'T1' => $tStd(500000, 31) + $tEx(69750, 35000, 55000, 104750, 395250),
        ],
        'working' => [
            'T1' => array_merge($tWk('500,000', '31'), $tPw(
                'period starts 2025-07-01, before 2025-07-07: 979/2016 ladder. 500,000 is in 320,001 to 525,000: 500,000 x 20 / 100 = 100,000; less 30,250 = 69,750 (PYR-TAX-02, PYR-TAX-03, PYR-TAX-04)',
                '500,000 x 7 / 100 = 35,000 (PYR-PEN-05)',
                '500,000 x 11 / 100 = 55,000 (PYR-PEN-05)',
                '69,750 + 35,000 + 0 + 0 = 104,750 (PYR-NET-01)',
                '500,000 - 104,750 = 395,250 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-010',
        'rule' => ['PYR-TAX-02', 'PYR-TAX-03', 'PYR-TAX-04', 'PYR-PER-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => ['start' => '2025-07-06', 'end' => '2025-07-31', 'pagume_days' => 0, 'label' => '6 to 31 July 2025'],
        'employees' => [
            'T1' => $tEmp(500000),
        ],
        'expect' => [
            'T1' => $tStd(500000, 26) + $tEx(69750, 35000, 55000, 104750, 395250),
        ],
        'working' => [
            'T1' => array_merge($tWk('500,000', '26'), $tPw(
                'period starts 2025-07-06, the last day of 979/2016: 500,000 x 20 / 100 = 100,000; less 30,250 = 69,750 (PYR-TAX-02, PYR-TAX-04, PYR-PER-03)',
                '500,000 x 7 / 100 = 35,000 (PYR-PEN-05)',
                '500,000 x 11 / 100 = 55,000 (PYR-PEN-05)',
                '69,750 + 35,000 + 0 + 0 = 104,750 (PYR-NET-01)',
                '500,000 - 104,750 = 395,250 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-011',
        'rule' => ['PYR-TAX-01', 'PYR-TAX-03', 'PYR-TAX-04', 'PYR-PER-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => ['start' => '2025-07-07', 'end' => '2025-07-31', 'pagume_days' => 0, 'label' => '7 to 31 July 2025'],
        'employees' => [
            'T1' => $tEmp(500000),
        ],
        'expect' => [
            'T1' => $tStd(500000, 25) + $tEx(50000, 35000, 55000, 85000, 415000),
        ],
        'working' => [
            'T1' => array_merge($tWk('500,000', '25'), $tPw(
                'period starts 2025-07-07, the first day of 1395/2025: 500,000 is in 400,001 to 700,000: 500,000 x 20 / 100 = 100,000; less 50,000 = 50,000 (PYR-TAX-01, PYR-TAX-04)',
                '500,000 x 7 / 100 = 35,000 (PYR-PEN-05)',
                '500,000 x 11 / 100 = 55,000 (PYR-PEN-05)',
                '50,000 + 35,000 + 0 + 0 = 85,000 (PYR-NET-01)',
                '500,000 - 85,000 = 415,000 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-012',
        'rule' => ['PYR-TAX-02', 'PYR-TAX-03', 'PYR-PER-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => ['start' => '2025-06-15', 'end' => '2025-07-14', 'pagume_days' => 0, 'label' => '15 June to 14 July 2025'],
        'employees' => [
            'T1' => $tEmp(500000),
        ],
        'expect' => [
            'T1' => $tStd(500000, 30) + $tEx(69750, 35000, 55000, 104750, 395250),
        ],
        'working' => [
            'T1' => array_merge($tWk('500,000', '30'), $tPw(
                'the ladder is the one in force on the period start 2025-06-15 even though the period ends after 2025-07-07: 979/2016, 500,000 x 20 / 100 = 100,000; less 30,250 = 69,750 (PYR-PER-03, PYR-TAX-02)',
                '500,000 x 7 / 100 = 35,000 (PYR-PEN-05)',
                '500,000 x 11 / 100 = 55,000 (PYR-PEN-05)',
                '69,750 + 35,000 + 0 + 0 = 104,750 (PYR-NET-01)',
                '500,000 - 104,750 = 395,250 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-013',
        'rule' => ['PYR-TAX-01', 'PYR-TAX-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => '07 G4',
        'period' => $oct,
        'employees' => [
            'G4' => $tEmp(900000),
            'T2' => $tEmp(300000),
            'T3' => $tEmp(550000),
            'T4' => $tEmp(1200000),
            'T5' => $tEmp(2000000),
        ],
        'expect' => [
            'G4' => $tStd(900000, 31) + $tEx(140000, 63000, 99000, 203000, 697000),
            'T2' => $tStd(300000, 31) + $tEx(15000, 21000, 33000, 36000, 264000),
            'T3' => $tStd(550000, 31) + $tEx(60000, 38500, 60500, 98500, 451500),
            'T4' => $tStd(1200000, 31) + $tEx(225000, 84000, 132000, 309000, 891000),
            'T5' => $tStd(2000000, 31) + $tEx(495000, 140000, 220000, 635000, 1365000),
        ],
        'working' => [
            'G4' => array_merge($tWk('900,000', '31'), $tPw(
                '900,000 is in 700,001 to 1,000,000: 900,000 x 25 / 100 = 225,000; less 85,000 = 140,000; cumulative check 0 + 30,000 + 60,000 + 50,000 = 140,000 (PYR-TAX-01, PYR-TAX-03)',
                '900,000 x 7 / 100 = 63,000 (PYR-PEN-05)',
                '900,000 x 11 / 100 = 99,000 (PYR-PEN-05)',
                '140,000 + 63,000 + 0 + 0 = 203,000 (PYR-NET-01)',
                '900,000 - 203,000 = 697,000 (PYR-NET-01)'
            )),
            'T2' => array_merge($tWk('300,000', '31'), $tPw(
                '300,000 is in 200,001 to 400,000: 300,000 x 15 / 100 = 45,000; less 30,000 = 15,000 (PYR-TAX-01, PYR-TAX-03)',
                '300,000 x 7 / 100 = 21,000 (PYR-PEN-05)',
                '300,000 x 11 / 100 = 33,000 (PYR-PEN-05)',
                '15,000 + 21,000 + 0 + 0 = 36,000 (PYR-NET-01)',
                '300,000 - 36,000 = 264,000 (PYR-NET-01)'
            )),
            'T3' => array_merge($tWk('550,000', '31'), $tPw(
                '550,000 is in 400,001 to 700,000: 550,000 x 20 / 100 = 110,000; less 50,000 = 60,000 (PYR-TAX-01, PYR-TAX-03)',
                '550,000 x 7 / 100 = 38,500 (PYR-PEN-05)',
                '550,000 x 11 / 100 = 60,500 (PYR-PEN-05)',
                '60,000 + 38,500 + 0 + 0 = 98,500 (PYR-NET-01)',
                '550,000 - 98,500 = 451,500 (PYR-NET-01)'
            )),
            'T4' => array_merge($tWk('1,200,000', '31'), $tPw(
                '1,200,000 is in 1,000,001 to 1,400,000: 1,200,000 x 30 / 100 = 360,000; less 135,000 = 225,000 (PYR-TAX-01, PYR-TAX-03)',
                '1,200,000 x 7 / 100 = 84,000 (PYR-PEN-05)',
                '1,200,000 x 11 / 100 = 132,000 (PYR-PEN-05)',
                '225,000 + 84,000 + 0 + 0 = 309,000 (PYR-NET-01)',
                '1,200,000 - 309,000 = 891,000 (PYR-NET-01)'
            )),
            'T5' => array_merge($tWk('2,000,000', '31'), $tPw(
                '2,000,000 is in 1,400,001 and over: 2,000,000 x 35 / 100 = 700,000; less 205,000 = 495,000; cumulative check 30,000 + 60,000 + 75,000 + 120,000 + 210,000 = 495,000 (PYR-TAX-01, PYR-TAX-03)',
                '2,000,000 x 7 / 100 = 140,000 (PYR-PEN-05)',
                '2,000,000 x 11 / 100 = 220,000 (PYR-PEN-05)',
                '495,000 + 140,000 + 0 + 0 = 635,000 (PYR-NET-01)',
                '2,000,000 - 635,000 = 1,365,000 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-014',
        'rule' => ['PYR-TAX-02', 'PYR-TAX-03', 'PYR-PER-03', 'PYR-PEN-02', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => $jun25,
        'employees' => [
            'T1' => $tEmp(100000),
            'T2' => $tEmp(250000),
            'T3' => $tEmp(450000),
            'T4' => $tEmp(650000),
            'T5' => $tEmp(900000),
            'T6' => $tEmp(1500000),
        ],
        'expect' => [
            'T1' => $tStd(100000, 30) + $tEx(4000, 7000, 11000, 11000, 89000),
            'T2' => $tStd(250000, 30) + $tEx(23250, 17500, 27500, 40750, 209250),
            'T3' => $tStd(450000, 30) + $tEx(59750, 31500, 49500, 91250, 358750),
            'T4' => $tStd(650000, 30) + $tEx(106000, 45500, 71500, 151500, 498500),
            'T5' => $tStd(900000, 30) + $tEx(174500, 63000, 99000, 237500, 662500),
            'T6' => $tStd(1500000, 30) + $tEx(375000, 105000, 165000, 480000, 1020000),
        ],
        'working' => [
            'T1' => array_merge($tWk('100,000', '30'), $tPw(
                '979/2016 ladder. 100,000 is in 60,001 to 165,000: 100,000 x 10 / 100 = 10,000; less 6,000 = 4,000 (PYR-TAX-02, PYR-TAX-03)',
                '100,000 x 7 / 100 = 7,000 (PYR-PEN-05)',
                '100,000 x 11 / 100 = 11,000 (PYR-PEN-05)',
                '4,000 + 7,000 + 0 + 0 = 11,000 (PYR-NET-01)',
                '100,000 - 11,000 = 89,000 (PYR-NET-01)'
            )),
            'T2' => array_merge($tWk('250,000', '30'), $tPw(
                '979/2016 ladder. 250,000 is in 165,001 to 320,000: 250,000 x 15 / 100 = 37,500; less 14,250 = 23,250 (PYR-TAX-02, PYR-TAX-03)',
                '250,000 x 7 / 100 = 17,500 (PYR-PEN-05)',
                '250,000 x 11 / 100 = 27,500 (PYR-PEN-05)',
                '23,250 + 17,500 + 0 + 0 = 40,750 (PYR-NET-01)',
                '250,000 - 40,750 = 209,250 (PYR-NET-01)'
            )),
            'T3' => array_merge($tWk('450,000', '30'), $tPw(
                '979/2016 ladder. 450,000 is in 320,001 to 525,000: 450,000 x 20 / 100 = 90,000; less 30,250 = 59,750 (PYR-TAX-02, PYR-TAX-03)',
                '450,000 x 7 / 100 = 31,500 (PYR-PEN-05)',
                '450,000 x 11 / 100 = 49,500 (PYR-PEN-05)',
                '59,750 + 31,500 + 0 + 0 = 91,250 (PYR-NET-01)',
                '450,000 - 91,250 = 358,750 (PYR-NET-01)'
            )),
            'T4' => array_merge($tWk('650,000', '30'), $tPw(
                '979/2016 ladder. 650,000 is in 525,001 to 780,000: 650,000 x 25 / 100 = 162,500; less 56,500 = 106,000 (PYR-TAX-02, PYR-TAX-03)',
                '650,000 x 7 / 100 = 45,500 (PYR-PEN-05)',
                '650,000 x 11 / 100 = 71,500 (PYR-PEN-05)',
                '106,000 + 45,500 + 0 + 0 = 151,500 (PYR-NET-01)',
                '650,000 - 151,500 = 498,500 (PYR-NET-01)'
            )),
            'T5' => array_merge($tWk('900,000', '30'), $tPw(
                '979/2016 ladder. 900,000 is in 780,001 to 1,090,000: 900,000 x 30 / 100 = 270,000; less 95,500 = 174,500 (PYR-TAX-02, PYR-TAX-03)',
                '900,000 x 7 / 100 = 63,000 (PYR-PEN-05)',
                '900,000 x 11 / 100 = 99,000 (PYR-PEN-05)',
                '174,500 + 63,000 + 0 + 0 = 237,500 (PYR-NET-01)',
                '900,000 - 237,500 = 662,500 (PYR-NET-01)'
            )),
            'T6' => array_merge($tWk('1,500,000', '30'), $tPw(
                '979/2016 ladder. 1,500,000 is in 1,090,001 and over: 1,500,000 x 35 / 100 = 525,000; less 150,000 = 375,000; cumulative check 10,500 + 23,250 + 41,000 + 63,750 + 93,000 + 143,500 = 375,000 (PYR-TAX-02, PYR-TAX-03)',
                '1,500,000 x 7 / 100 = 105,000 (PYR-PEN-05)',
                '1,500,000 x 11 / 100 = 165,000 (PYR-PEN-05)',
                '375,000 + 105,000 + 0 + 0 = 480,000 (PYR-NET-01)',
                '1,500,000 - 480,000 = 1,020,000 (PYR-NET-01)'
            )),
        ],
    ],
    [
        'id' => 'GP-TAX-015',
        'rule' => ['PYR-TAX-01', 'PYR-TAX-03', 'PYR-ROUND-01', 'PYR-PEN-05'],
        'source' => 'register',
        'period' => $oct,
        'employees' => [
            'T1' => $tEmp(250001),
            'T2' => $tEmp(250010),
            'T3' => $tEmp(250004),
            'T4' => $tEmp(800001),
            'T5' => $tEmp(800002),
            'T6' => $tEmp(800003),
            'T7' => $tEmp(1500001),
            'T8' => $tEmp(1500010),
            'T9' => $tEmp(1500002),
        ],
        'expect' => [
            'T1' => $tStd(250001, 31) + $tEx(7500, 17500, 27500, 25000, 225001),
            'T2' => $tStd(250010, 31) + $tEx(7502, 17501, 27501, 25003, 225007),
            'T3' => $tStd(250004, 31) + $tEx(7501, 17500, 27500, 25001, 225003),
            'T4' => $tStd(800001, 31) + $tEx(115000, 56000, 88000, 171000, 629001),
            'T5' => $tStd(800002, 31) + $tEx(115001, 56000, 88000, 171001, 629001),
            'T6' => $tStd(800003, 31) + $tEx(115001, 56000, 88000, 171001, 629002),
            'T7' => $tStd(1500001, 31) + $tEx(320000, 105000, 165000, 425000, 1075001),
            'T8' => $tStd(1500010, 31) + $tEx(320004, 105001, 165001, 425005, 1075005),
            'T9' => $tStd(1500002, 31) + $tEx(320001, 105000, 165000, 425001, 1075001),
        ],
        'working' => [
            'T1' => array_merge($tWk('250,001', '31'), $tPw(
                '250,001 is in 200,001 to 400,000: 250,001 x 15 / 100 = 37,500.15, fraction below half, rounded 37,500; less 30,000 = 7,500 (rounding up would give 7,501) (PYR-TAX-01, PYR-TAX-03, PYR-ROUND-01)',
                '250,001 x 7 / 100 = 17,500.07, rounded 17,500 (PYR-PEN-05)',
                '250,001 x 11 / 100 = 27,500.11, rounded 27,500 (PYR-PEN-05)',
                '7,500 + 17,500 + 0 + 0 = 25,000 (PYR-NET-01)',
                '250,001 - 25,000 = 225,001 (PYR-NET-01)'
            )),
            'T2' => array_merge($tWk('250,010', '31'), $tPw(
                '250,010 is in 200,001 to 400,000: 250,010 x 15 / 100 = 37,501.5, exactly half, rounded away from zero 37,502; less 30,000 = 7,502 (rounding down would give 7,501) (PYR-TAX-01, PYR-TAX-03, PYR-ROUND-01)',
                '250,010 x 7 / 100 = 17,500.7, rounded 17,501 (truncating would give 17,500) (PYR-PEN-05)',
                '250,010 x 11 / 100 = 27,501.1, rounded 27,501 (PYR-PEN-05)',
                '7,502 + 17,501 + 0 + 0 = 25,003 (PYR-NET-01)',
                '250,010 - 25,003 = 225,007 (PYR-NET-01)'
            )),
            'T3' => array_merge($tWk('250,004', '31'), $tPw(
                '250,004 is in 200,001 to 400,000: 250,004 x 15 / 100 = 37,500.6, fraction above half, rounded up 37,501; less 30,000 = 7,501 (truncating would give 7,500) (PYR-TAX-01, PYR-TAX-03, PYR-ROUND-01)',
                '250,004 x 7 / 100 = 17,500.28, rounded 17,500 (PYR-PEN-05)',
                '250,004 x 11 / 100 = 27,500.44, rounded 27,500 (PYR-PEN-05)',
                '7,501 + 17,500 + 0 + 0 = 25,001 (PYR-NET-01)',
                '250,004 - 25,001 = 225,003 (PYR-NET-01)'
            )),
            'T4' => array_merge($tWk('800,001', '31'), $tPw(
                '800,001 is in 700,001 to 1,000,000: 800,001 x 25 / 100 = 200,000.25, below half, rounded 200,000; less 85,000 = 115,000 (rounding up would give 115,001) (PYR-TAX-01, PYR-TAX-03, PYR-ROUND-01)',
                '800,001 x 7 / 100 = 56,000.07, rounded 56,000 (PYR-PEN-05)',
                '800,001 x 11 / 100 = 88,000.11, rounded 88,000 (PYR-PEN-05)',
                '115,000 + 56,000 + 0 + 0 = 171,000 (PYR-NET-01)',
                '800,001 - 171,000 = 629,001 (PYR-NET-01)'
            )),
            'T5' => array_merge($tWk('800,002', '31'), $tPw(
                '800,002 is in 700,001 to 1,000,000: 800,002 x 25 / 100 = 200,000.5, exactly half, rounded away from zero 200,001; less 85,000 = 115,001 (rounding down would give 115,000) (PYR-TAX-01, PYR-TAX-03, PYR-ROUND-01)',
                '800,002 x 7 / 100 = 56,000.14, rounded 56,000 (PYR-PEN-05)',
                '800,002 x 11 / 100 = 88,000.22, rounded 88,000 (PYR-PEN-05)',
                '115,001 + 56,000 + 0 + 0 = 171,001 (PYR-NET-01)',
                '800,002 - 171,001 = 629,001 (PYR-NET-01)'
            )),
            'T6' => array_merge($tWk('800,003', '31'), $tPw(
                '800,003 is in 700,001 to 1,000,000: 800,003 x 25 / 100 = 200,000.75, above half, rounded 200,001; less 85,000 = 115,001 (truncating would give 115,000) (PYR-TAX-01, PYR-TAX-03, PYR-ROUND-01)',
                '800,003 x 7 / 100 = 56,000.21, rounded 56,000 (PYR-PEN-05)',
                '800,003 x 11 / 100 = 88,000.33, rounded 88,000 (PYR-PEN-05)',
                '115,001 + 56,000 + 0 + 0 = 171,001 (PYR-NET-01)',
                '800,003 - 171,001 = 629,002 (PYR-NET-01)'
            )),
            'T7' => array_merge($tWk('1,500,001', '31'), $tPw(
                '1,500,001 is in 1,400,001 and over: 1,500,001 x 35 / 100 = 525,000.35, below half, rounded 525,000; less 205,000 = 320,000 (rounding up would give 320,001) (PYR-TAX-01, PYR-TAX-03, PYR-ROUND-01)',
                '1,500,001 x 7 / 100 = 105,000.07, rounded 105,000 (PYR-PEN-05)',
                '1,500,001 x 11 / 100 = 165,000.11, rounded 165,000 (PYR-PEN-05)',
                '320,000 + 105,000 + 0 + 0 = 425,000 (PYR-NET-01)',
                '1,500,001 - 425,000 = 1,075,001 (PYR-NET-01)'
            )),
            'T8' => array_merge($tWk('1,500,010', '31'), $tPw(
                '1,500,010 is in 1,400,001 and over: 1,500,010 x 35 / 100 = 525,003.5, exactly half, rounded away from zero 525,004; less 205,000 = 320,004 (rounding down would give 320,003) (PYR-TAX-01, PYR-TAX-03, PYR-ROUND-01)',
                '1,500,010 x 7 / 100 = 105,000.7, rounded 105,001 (truncating would give 105,000) (PYR-PEN-05)',
                '1,500,010 x 11 / 100 = 165,001.1, rounded 165,001 (PYR-PEN-05)',
                '320,004 + 105,001 + 0 + 0 = 425,005 (PYR-NET-01)',
                '1,500,010 - 425,005 = 1,075,005 (PYR-NET-01)'
            )),
            'T9' => array_merge($tWk('1,500,002', '31'), $tPw(
                '1,500,002 is in 1,400,001 and over: 1,500,002 x 35 / 100 = 525,000.7, above half, rounded 525,001; less 205,000 = 320,001 (truncating would give 320,000) (PYR-TAX-01, PYR-TAX-03, PYR-ROUND-01)',
                '1,500,002 x 7 / 100 = 105,000.14, rounded 105,000 (PYR-PEN-05)',
                '1,500,002 x 11 / 100 = 165,000.22, rounded 165,000 (PYR-PEN-05)',
                '320,001 + 105,000 + 0 + 0 = 425,001 (PYR-NET-01)',
                '1,500,002 - 425,001 = 1,075,001 (PYR-NET-01)'
            )),
        ],
    ],
];
