<?php

declare(strict_types=1);

use App\Services\Payroll\TaxCalculator;

dataset('tax_brackets', [
    // [grossCents, expectedTax, label]
    'zero income' => [0, 0],
    'bracket 1 floor' => [1, 0],
    'bracket 1 ceiling' => [60000, 0],
    'bracket 2 floor' => [60001, 0],
    'bracket 2 mid' => [100000, 4000],
    'bracket 2 ceiling' => [165000, 10500],
    'bracket 3 floor' => [165001, 10500],
    'bracket 3 ceiling' => [320000, 33750],
    'bracket 4 floor' => [320001, 33750],
    'bracket 4 ceiling' => [525000, 74750],
    'bracket 5 floor' => [525001, 74750],
    'bracket 5 ceiling' => [780000, 138500],
    'bracket 6 floor' => [780001, 138500],
    'bracket 6 ceiling' => [1090000, 231500],
    'bracket 7 floor' => [1090001, 231500],
    'bracket 7 high earner' => [2000000, 550000],
]);

test('calculates Ethiopian income tax for default brackets', function (int $gross, int $expected) {
    $calc = new TaxCalculator;
    expect($calc->calculate($gross))->toBe($expected);
})->with('tax_brackets');

test('returns zero for zero income', function () {
    $calc = new TaxCalculator;
    expect($calc->calculate(0))->toBe(0);
});

test('handles exact bracket boundaries', function () {
    $calc = new TaxCalculator;

    $atCeiling = $calc->calculate(60000);
    $justAbove = $calc->calculate(60001);

    expect($atCeiling)->toBe(0);
    expect($justAbove)->toBeGreaterThanOrEqual(0);
});

test('tax always increases with income', function () {
    $calc = new TaxCalculator;

    $prev = 0;
    foreach ([60001, 165001, 320001, 525001, 780001, 1090001] as $amount) {
        $tax = $calc->calculate($amount);
        expect($tax)->toBeGreaterThanOrEqual($prev);
        $prev = $tax;
    }
});

test('effective rate never exceeds 35 percent', function () {
    $calc = new TaxCalculator;

    foreach ([100000, 500000, 1000000, 5000000] as $gross) {
        $tax = $calc->calculate($gross);
        $effectiveRate = $tax / $gross * 100;
        expect($effectiveRate)->toBeLessThan(35.0);
    }
});
