<?php

declare(strict_types=1);

use App\Services\Payroll\TaxCalculator;
use Illuminate\Support\Carbon;

/**
 * Employment income tax, Proclamation No. 1395/2025 (in force 7 July 2025).
 *
 * Expected values are derived from the statutory *cumulative* form, which is how
 * the proclamation states the schedule, while the calculator uses the equivalent
 * quick form `gross × rate − deduction`. Deriving them the other way round would
 * only re-assert the implementation's own arithmetic.
 *
 *   0 – 2,000      0%
 *   2,001 – 4,000  15%   0 + 15% over 2,000
 *   4,001 – 7,000  20%   300 + 20% over 4,000
 *   7,001 – 10,000 25%   900 + 25% over 7,000
 *  10,001 – 14,000 30%   1,650 + 30% over 10,000
 *      over 14,000 35%   2,850 + 35% over 14,000
 */
dataset('tax_brackets', [
    // [grossCents, expectedTaxCents]
    'zero income' => [0, 0],
    'band 1 floor' => [1, 0],
    'band 1 ceiling — 2,000 is now exempt' => [200000, 0],
    'band 2 floor' => [200001, 0],
    'band 2 mid — 3,000' => [300000, 15000],
    'band 2 ceiling — 4,000' => [400000, 30000],
    'band 3 floor' => [400001, 30000],
    'band 3 mid — 5,000' => [500000, 50000],
    'band 3 ceiling — 7,000' => [700000, 90000],
    'band 4 floor' => [700001, 90000],
    'band 4 ceiling — 10,000' => [1000000, 165000],
    'band 5 floor' => [1000001, 165000],
    'band 5 ceiling — 14,000' => [1400000, 285000],
    'band 6 floor' => [1400001, 285000],
    'band 6 high earner — 20,000' => [2000000, 495000],
]);

test('calculates Ethiopian income tax under Proclamation 1395/2025', function (int $gross, int $expected) {
    expect((new TaxCalculator)->calculate($gross))->toBe($expected);
})->with('tax_brackets');

/**
 * The whole point of the 2025 amendment: the tax-free threshold tripled. A
 * bracket table can be updated correctly in the aggregate and still get the
 * bottom wrong, and the bottom is where it matters most — 157.50 ETB is ~8% of
 * a 2,000 ETB gross.
 */
test('income up to 2,000 ETB is exempt, where 979/2016 taxed it', function () {
    $calc = new TaxCalculator;

    expect($calc->calculate(200000))->toBe(0)
        ->and($calc->calculate(150000))->toBe(0)
        ->and($calc->calculate(60001))->toBe(0);

    // What the superseded ladder charged on the same 2,000 ETB.
    expect($calc->calculate(200000, null, Carbon::parse('2025-06-30')))->toBe(15750);
});

/**
 * Payroll is re-runnable — PayrollEngine::void() reprocesses a past period — so
 * a period before the amendment must reproduce the tax actually withheld at the
 * time, not today's. Without the as-of date this silently retaxes history.
 */
describe('effective dating', function () {
    test('a period before 7 July 2025 uses the 979/2016 ladder', function () {
        $calc = new TaxCalculator;

        // 5,000 ETB: 697.50 under 979/2016, 500.00 under 1395/2025.
        expect($calc->calculate(500000, null, Carbon::parse('2025-06-30')))->toBe(69750);
    });

    test('a period on or after 7 July 2025 uses the 1395/2025 ladder', function () {
        $calc = new TaxCalculator;

        expect($calc->calculate(500000, null, Carbon::parse('2025-07-07')))->toBe(50000)
            ->and($calc->calculate(500000, null, Carbon::parse('2026-01-31')))->toBe(50000);
    });

    test('the boundary falls between 6 and 7 July 2025', function () {
        $calc = new TaxCalculator;

        expect($calc->calculate(500000, null, Carbon::parse('2025-07-06')))->toBe(69750)
            ->and($calc->calculate(500000, null, Carbon::parse('2025-07-07')))->toBe(50000);
    });

    test('omitting the as-of date resolves against today', function () {
        $calc = new TaxCalculator;

        expect($calc->calculate(500000))->toBe($calc->calculate(500000, null, Carbon::now()));
    });
});

/**
 * Non-decreasing, not strictly increasing — the ladder is continuous, so the
 * first cent of a band is taxed the same as the last cent of the one below it
 * (2,000.01 ETB still pays 0). A strict assertion here fails on correct
 * behaviour, which is the trap this comment exists to stop someone re-entering.
 */
test('tax never decreases as income rises', function () {
    $calc = new TaxCalculator;

    $prev = 0;
    foreach ([0, 200000, 200001, 400000, 400001, 700001, 1000001, 1400001, 5000000] as $amount) {
        $tax = $calc->calculate($amount);
        expect($tax)->toBeGreaterThanOrEqual($prev);
        $prev = $tax;
    }
});

test('tax strictly increases between bands', function () {
    $calc = new TaxCalculator;

    $prev = -1;
    foreach ([100000, 300000, 500000, 800000, 1200000, 2000000, 5000000] as $amount) {
        $tax = $calc->calculate($amount);
        expect($tax)->toBeGreaterThan($prev);
        $prev = $tax;
    }
});

/**
 * Continuity itself, asserted directly: crossing a threshold must never create
 * a cliff where earning one more cent costs more than a cent in tax.
 */
test('crossing a band boundary never costs more than it earns', function () {
    $calc = new TaxCalculator;

    foreach ([200000, 400000, 700000, 1000000, 1400000] as $ceiling) {
        expect($calc->calculate($ceiling + 1) - $calc->calculate($ceiling))->toBeLessThanOrEqual(1);
    }
});

test('effective rate never reaches the 35 percent marginal rate', function () {
    $calc = new TaxCalculator;

    foreach ([300000, 500000, 1000000, 5000000, 50000000] as $gross) {
        expect($calc->calculate($gross) / $gross * 100)->toBeLessThan(35.0);
    }
});
