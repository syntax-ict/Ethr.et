<?php

declare(strict_types=1);

use App\Support\Cents;

it('rounds an exact half away from zero', function () {
    expect(Cents::mulDiv(5, 1, 2))->toBe(3)
        ->and(Cents::mulDiv(-5, 1, 2))->toBe(-3)
        ->and(Cents::mulDiv(7, 1, 2))->toBe(4)
        ->and(Cents::mulDiv(4, 1, 3))->toBe(1)
        ->and(Cents::mulDiv(5, 1, 3))->toBe(2)
        ->and(Cents::mulDiv(10, 3, -4))->toBe(-8);
});

it('truncates when asked to', function () {
    expect(Cents::mulDivDown(19, 1, 10))->toBe(1)
        ->and(Cents::mulDivDown(-19, 1, 10))->toBe(-1);
});

it('takes a percentage exactly, from a string, a float or an integer', function () {
    expect(Cents::percentOf(1234567, '7.00'))->toBe(86420) // 86,419.69
        ->and(Cents::percentOf(1234567, 7.5))->toBe(92593) // 92,592.525
        ->and(Cents::percentOf(1234567, 11))->toBe(135802) // 135,802.37
        ->and(Cents::percentOf(200001, 15))->toBe(30000)  // 30,000.15
        ->and(Cents::percentOf(100, '12.345'))->toBe(12);
});

it('parses rates into ten-thousandths without a float', function () {
    expect(Cents::scaledRate('7.35'))->toBe(73500)
        ->and(Cents::scaledRate('1.75'))->toBe(17500)
        ->and(Cents::scaledRate(1.333))->toBe(13330)
        ->and(Cents::scaledRate(2))->toBe(20000)
        ->and(Cents::scaledRate('-0.5'))->toBe(-5000);
});

it('agrees with the exact fraction on random inputs', function () {
    mt_srand(20261027);

    for ($i = 0; $i < 2000; $i++) {
        $a = mt_rand(-50_000_000, 50_000_000);
        $num = mt_rand(1, 10_000);
        $den = mt_rand(1, 10_000);
        $r = Cents::mulDiv($a, $num, $den);

        // |r - a·num/den| ≤ 1/2, i.e. |2·r·den − 2·a·num| ≤ den.
        expect(abs(2 * $r * $den - 2 * $a * $num))->toBeLessThanOrEqual($den);
    }
});

it('refuses a product that leaves the integer range instead of becoming a float', function () {
    Cents::mulDiv(PHP_INT_MAX, 2, 3);
})->throws(OverflowException::class);
