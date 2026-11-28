<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle\Payroll;

use InvalidArgumentException;

/** The one audited place for division and rounding. Integers only. */
final class Exact
{
    /** a x num / den, rounded half away from zero; den must be > 0. */
    public static function mulDivHalfUp(int $a, int $num, int $den): int
    {
        if ($den <= 0) {
            throw new InvalidArgumentException('denominator must be positive');
        }
        $p = self::mul($a, $num);
        $q = intdiv($p, $den);
        $r = abs($p % $den);
        if ($r >= $den - $r) {
            $q += $p < 0 ? -1 : 1;
        }

        return $q;
    }

    /** a x num / den, rounded toward zero. */
    public static function mulDivDown(int $a, int $num, int $den): int
    {
        if ($den <= 0) {
            throw new InvalidArgumentException('denominator must be positive');
        }

        return intdiv(self::mul($a, $num), $den);
    }

    /** a x b, throwing when the product would leave the int range. */
    public static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        if ($a === PHP_INT_MIN || $b === PHP_INT_MIN) {
            if ($a === 1) {
                return $b;
            }
            if ($b === 1) {
                return $a;
            }
            throw new InvalidArgumentException('integer overflow');
        }
        if (abs($a) > intdiv(PHP_INT_MAX, abs($b))) {
            throw new InvalidArgumentException('integer overflow');
        }

        return $a * $b;
    }
}
