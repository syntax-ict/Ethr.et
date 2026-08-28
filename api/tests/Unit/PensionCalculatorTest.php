<?php

declare(strict_types=1);

use App\Services\Payroll\PensionCalculator;

test('calculates default 7% employee and 11% employer contributions', function () {
    $calc = new PensionCalculator;
    $result = $calc->calculate(100000);

    expect($result)->toBe([
        'employee_cents' => 7000,
        'employer_cents' => 11000,
    ]);
});

test('returns zero for zero salary', function () {
    $calc = new PensionCalculator;
    $result = $calc->calculate(0);

    expect($result)->toBe([
        'employee_cents' => 0,
        'employer_cents' => 0,
    ]);
});

test('handles large salaries without overflow', function () {
    $calc = new PensionCalculator;
    $result = $calc->calculate(10_000_000);

    expect($result['employee_cents'])->toBe(700_000);
    expect($result['employer_cents'])->toBe(1_100_000);
});

test('rounds to nearest cent', function () {
    $calc = new PensionCalculator;
    $result = $calc->calculate(33333);

    expect($result['employee_cents'])->toBe(2333);
    expect($result['employer_cents'])->toBe(3667);
});

test('accepts custom rates', function () {
    $calc = new PensionCalculator(employeeRate: 10.0, employerRate: 15.0);
    $result = $calc->calculate(100000);

    expect($result)->toBe([
        'employee_cents' => 10000,
        'employer_cents' => 15000,
    ]);
});

test('employee contribution is always less than employer contribution at default rates', function () {
    $calc = new PensionCalculator;

    foreach ([50000, 100000, 250000, 500000] as $salary) {
        $result = $calc->calculate($salary);
        expect($result['employee_cents'])->toBeLessThan($result['employer_cents']);
    }
});
