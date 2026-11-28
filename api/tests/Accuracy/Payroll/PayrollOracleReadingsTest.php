<?php

declare(strict_types=1);

use Tests\Accuracy\Oracle\Payroll\PayslipOracle;
use Tests\Accuracy\Oracle\Undecided;

$build = static function (array $over = []): array {
    $base = [
        'id' => 'GP-RDG-001',
        'rule' => ['PYR-PER-01'],
        'source' => 'register',
        'period' => ['start' => '2026-10-01', 'end' => '2026-10-31', 'pagume_days' => 0],
        'org' => [],
        'employees' => ['E' => [
            'status' => 'confirmed', 'hire_date' => '2020-01-01', 'termination_date' => null, 'deleted' => false,
            'nationality' => 'ethiopian', 'salary_cents' => 880000, 'inputs' => [],
        ]],
    ];

    return array_replace_recursive($base, $over);
};

$pagume = ['start' => '2026-09-06', 'end' => '2026-09-10', 'pagume_days' => 5];
$late = ['lateness_deductions' => true];
$transport = ['transport' => ['type' => 'fixed', 'amount_cents' => 60000, 'taxable' => false]];
$loanOf = static fn (int $instalment): array => ['L1' => ['instalment_cents' => $instalment, 'remaining_cents' => 900000, 'status' => 'active']];
$joiner = static fn (string $hire, int $unpaid): array => [
    'org' => $late,
    'employees' => ['E' => [
        'salary_cents' => 1234567, 'hire_date' => $hire,
        'inputs' => ['unpaid_leave_thousandths' => $unpaid, 'lateness_minutes' => 47],
        'loans' => ['L1' => ['instalment_cents' => 150000, 'remaining_cents' => 900000, 'status' => 'active']],
    ]],
];
$bank = ['PYR-BANK-04', 'PYR-BANK-05', 'PYR-BANK-06'];

// Each row: [question, who ('run' or an employee code), field, rules, value]
$boundary = static fn (): array => [
    'PYR-PAG-06 basic 600,000, 5-day Pagume' => [
        $build(['period' => $pagume, 'employees' => ['E' => ['salary_cents' => 600000]]]),
        'E', 'period_basic', ['PYR-PAG-06'], 600000,
    ],
    'PYR-PAG-05 hired Pagume 3, 1,234,567' => [
        $build(['period' => $pagume, 'org' => ['pagume' => 'daily_rate'], 'employees' => ['E' => ['salary_cents' => 1234567, 'hire_date' => '2026-09-08']]]),
        'E', 'prorated_basic', ['PYR-PAG-05'], 121766,
    ],
    'PYR-LATE-06 the joiner example' => [
        $build($joiner('2026-10-17', 2500)),
        'E', 'lateness_ceiling', ['PYR-LATE-06'], 152359,
    ],
    'PYR-ROUND-04 1,000,000, 3 days' => [
        $build(['employees' => ['E' => ['salary_cents' => 1000000, 'inputs' => ['unpaid_leave_thousandths' => 3000]]]]),
        'E', 'unpaid_leave_deduction', ['PYR-ROUND-04'], 136365,
    ],
    'PYR-ROUND-05 2 minutes at 40,000' => [
        $build(['org' => $late, 'employees' => ['E' => ['inputs' => ['lateness_minutes' => 2]]]]),
        'E', 'lateness_uncapped', ['PYR-ROUND-05'], 167,
    ],
    'PYR-ALW-07 transport 60,000' => [
        $build(['period' => $pagume, 'org' => ['pagume' => 'daily_rate', 'allowances' => $transport], 'employees' => ['E' => ['salary_cents' => 600000]]]),
        'E', 'allowance.transport', ['PYR-ALW-07'], 60000,
    ],
    'PYR-NET-02 basic 300,000, loan 300,000' => [
        $build(['employees' => ['E' => ['salary_cents' => 300000, 'loans' => $loanOf(300000)]]]),
        'E', 'net', ['PYR-NET-02'], -36000,
    ],
    'PYR-PEN-11 diplomatic_mission' => [
        $build(['org' => ['organization_type' => 'diplomatic_mission'], 'employees' => ['E' => ['salary_cents' => 1000000]]]),
        'E', 'employee_pension', ['PYR-PEN-11'], 70000,
    ],
    'PYR-PEN-14 domestic_worker' => [
        $build(['employees' => ['E' => ['salary_cents' => 1000000, 'domestic_worker' => true]]]),
        'E', 'employee_pension', ['PYR-PEN-14'], 70000,
    ],
    'PYR-PRO-05 termination before the period start' => [
        $build(['employees' => ['E' => ['salary_cents' => 930000, 'termination_date' => '2026-09-20']]]),
        'E', 'days_employed', ['PYR-PRO-05'], 31,
    ],
    'PYR-BANK after_approval.delete' => [
        $build(['run' => 'approve', 'after_approval' => ['delete' => ['E']]]),
        'run', 'bank.total', $bank, 683400,
    ],
];

// The same inputs one step off the boundary: the field comes back answered with this value.
$control = static fn (): array => [
    'PYR-PAG-06 a period that is not Pagume alone' => [
        $build(['period' => ['start' => '2026-09-06', 'end' => '2026-09-12', 'pagume_days' => 5], 'employees' => ['E' => ['salary_cents' => 600000]]]),
        'E', 'period_basic', 600000,
    ],
    'PYR-PAG-05 hired on the first Pagume day' => [
        $build(['period' => $pagume, 'org' => ['pagume' => 'daily_rate'], 'employees' => ['E' => ['salary_cents' => 1234567, 'hire_date' => '2026-09-06']]]),
        'E', 'prorated_basic', 202943,
    ],
    'PYR-LATE-06 no leave, whole month' => [
        $build($joiner('2020-01-01', 0)),
        'E', 'lateness_ceiling', 411522,
    ],
    'PYR-ROUND-04 a daily rate that divides exactly' => [
        $build(['employees' => ['E' => ['inputs' => ['unpaid_leave_thousandths' => 3000]]]]),
        'E', 'unpaid_leave_deduction', 120000,
    ],
    'PYR-ROUND-05 a whole number of cents' => [
        $build(['org' => $late, 'employees' => ['E' => ['inputs' => ['lateness_minutes' => 3]]]]),
        'E', 'lateness_uncapped', 250,
    ],
    'PYR-ALW-07 full_month' => [
        $build(['period' => $pagume, 'org' => ['allowances' => $transport], 'employees' => ['E' => ['salary_cents' => 600000]]]),
        'E', 'allowance.transport', 60000,
    ],
    'PYR-NET-02 a smaller loan' => [
        $build(['employees' => ['E' => ['salary_cents' => 300000, 'loans' => $loanOf(100000)]]]),
        'E', 'net', 164000,
    ],
    'PYR-PEN-11 a private organisation' => [
        $build(['org' => ['organization_type' => 'private'], 'employees' => ['E' => ['salary_cents' => 1000000]]]),
        'E', 'employee_pension', 70000,
    ],
    'PYR-PEN-14 not a domestic worker' => [
        $build(['employees' => ['E' => ['salary_cents' => 1000000, 'domestic_worker' => false]]]),
        'E', 'employee_pension', 70000,
    ],
    'PYR-PRO-05 termination on the period start' => [
        $build(['employees' => ['E' => ['salary_cents' => 930000, 'termination_date' => '2026-10-01']]]),
        'E', 'days_employed', 1,
    ],
    'PYR-BANK nobody deleted' => [
        $build(['run' => 'approve']),
        'run', 'bank.total', 683400,
    ],
];

it('answers a field on a boundary as Undecided with its rule and the baseline value', function (array $question, string $who, string $field, array $rules, int $literal): void {
    $statutory = require __DIR__.'/fixtures/statutory.php';
    $out = PayslipOracle::compute($question, $statutory);
    $value = $who === 'run' ? $out['run'][$field] : $out['employees'][$who][$field];

    expect($value)->toBeInstanceOf(Undecided::class);
    expect($value->rules)->toBe($rules);
    expect($value->literal)->toBe($literal);
})->with($boundary);

it('answers the same field one step off the boundary', function (array $question, string $who, string $field, int $expected): void {
    $statutory = require __DIR__.'/fixtures/statutory.php';
    $out = PayslipOracle::compute($question, $statutory);
    $value = $who === 'run' ? $out['run'][$field] : $out['employees'][$who][$field];

    expect($value)->toBe($expected);
})->with($control);
