<?php

declare(strict_types=1);

use Tests\Accuracy\Oracle\Payroll\Exact;

it('rounds half away from zero within half a unit, and a tie goes away from zero', function (): void {
    $bad = [];
    for ($a = -60; $a <= 60; $a++) {
        for ($num = 0; $num <= 25; $num++) {
            for ($den = 1; $den <= 17; $den++) {
                $p = $a * $num;
                $r = Exact::mulDivHalfUp($a, $num, $den);
                $error = abs(2 * ($p - $r * $den));
                $ok = $error <= $den;
                if ($error === $den) {
                    $ok = $ok && abs($r * $den) > abs($p);
                }
                if (! $ok) {
                    $bad[] = "$a x $num / $den gave $r";
                }
            }
        }
    }

    expect($bad)->toBe([]);
});

it('divides toward zero, with the remainder carrying the sign of the product', function (): void {
    $bad = [];
    for ($a = -60; $a <= 60; $a++) {
        for ($num = 0; $num <= 25; $num++) {
            for ($den = 1; $den <= 17; $den++) {
                $p = $a * $num;
                $q = Exact::mulDivDown($a, $num, $den);
                $remainder = $p - $q * $den;
                $ok = abs($remainder) < $den
                    && abs($q * $den) <= abs($p)
                    && ($remainder === 0 || ($remainder < 0) === ($p < 0));
                if (! $ok) {
                    $bad[] = "$a x $num / $den gave $q";
                }
            }
        }
    }

    expect($bad)->toBe([]);
});

it('throws when a product would leave the int range, and not before', function (): void {
    expect(fn () => Exact::mul(PHP_INT_MAX, 2))->toThrow(InvalidArgumentException::class);
    expect(fn () => Exact::mul(PHP_INT_MIN, -1))->toThrow(InvalidArgumentException::class);
    expect(fn () => Exact::mul(intdiv(PHP_INT_MAX, 3) + 1, 3))->toThrow(InvalidArgumentException::class);
    expect(fn () => Exact::mulDivHalfUp(PHP_INT_MAX, 2, 1))->toThrow(InvalidArgumentException::class);
    expect(fn () => Exact::mulDivDown(PHP_INT_MAX, 2, 1))->toThrow(InvalidArgumentException::class);
    expect(Exact::mul(PHP_INT_MAX, 1))->toBe(PHP_INT_MAX);
    expect(Exact::mul(PHP_INT_MAX, -1))->toBe(-PHP_INT_MAX);
    expect(Exact::mul(intdiv(PHP_INT_MAX, 3), 3))->toBe(intdiv(PHP_INT_MAX, 3) * 3);
});

it('refuses a denominator that is not positive', function (): void {
    expect(fn () => Exact::mulDivHalfUp(1, 1, 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => Exact::mulDivHalfUp(1, 1, -3))->toThrow(InvalidArgumentException::class);
    expect(fn () => Exact::mulDivDown(1, 1, 0))->toThrow(InvalidArgumentException::class);
});
