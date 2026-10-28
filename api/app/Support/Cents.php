<?php

declare(strict_types=1);

namespace App\Support;

use OverflowException;

/**
 * Integer money arithmetic for payroll.
 *
 * Every figure on a payslip used to be a float expression passed through
 * round(): `$basic * $workedDays / $totalDays`, `$hourlyRate * $hours *
 * $multiplier`. The true value of each is a fraction of whole cents, and a
 * float can land a hair either side of a half, so the same inputs could
 * round to different cents depending on the order of the operations. These
 * helpers compute the fraction exactly and round once, half away from zero —
 * the same rule as PHP's round() on the positive values payroll had, and
 * symmetric for the negative differences retro pay will produce.
 *
 * A product that would leave the 64-bit range throws rather than silently
 * becoming a float.
 */
final class Cents
{
    /** Rates are carried in ten-thousandths: 7% is 70000, a 1.75x multiplier is 17500. */
    public const RATE_SCALE = 10000;

    public const ROUNDING = 'integer_half_up';

    /** a × num ÷ den, rounded half away from zero. */
    public static function mulDiv(int $a, int $num, int $den): int
    {
        if ($den === 0) {
            throw new \DivisionByZeroError('Cents::mulDiv by zero');
        }

        $product = self::multiply($a, $num);

        if ($den < 0) {
            $product = -$product;
            $den = -$den;
        }

        $quotient = intdiv($product, $den);
        $remainder = $product % $den;

        if (2 * abs($remainder) >= $den) {
            $quotient += $product < 0 ? -1 : 1;
        }

        return $quotient;
    }

    /** a × num ÷ den, rounded toward zero. Cost sharing has always truncated (Q7 in the payroll plan). */
    public static function mulDivDown(int $a, int $num, int $den): int
    {
        if ($den === 0) {
            throw new \DivisionByZeroError('Cents::mulDivDown by zero');
        }

        return intdiv(self::multiply($a, $num), $den);
    }

    /** $rate percent of $cents, rounded half away from zero. `$rate` is a percentage: 7, '7.00', 11.5. */
    public static function percentOf(int $cents, int|float|string $rate): int
    {
        return self::mulDiv($cents, self::scaledRate($rate), 100 * self::RATE_SCALE);
    }

    /** A rate or multiplier in ten-thousandths, parsed without a float when it arrives as a string. */
    public static function scaledRate(int|float|string $rate): int
    {
        if (is_int($rate)) {
            return self::multiply($rate, self::RATE_SCALE);
        }

        if (is_string($rate) && preg_match('/^\s*(-?)(\d+)(?:\.(\d{1,4}))?\s*$/', $rate, $m) === 1) {
            $value = self::multiply((int) $m[2], self::RATE_SCALE) + (int) str_pad($m[3] ?? '', 4, '0');

            return $m[1] === '-' ? -$value : $value;
        }

        return (int) round(((float) $rate) * self::RATE_SCALE);
    }

    private static function multiply(int $a, int $b): int
    {
        if ($a !== 0 && abs($b) > intdiv(PHP_INT_MAX, abs($a))) {
            throw new OverflowException("Cents: {$a} × {$b} leaves the integer range");
        }

        return $a * $b;
    }
}
