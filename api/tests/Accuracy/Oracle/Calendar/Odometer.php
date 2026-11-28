<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle\Calendar;

use InvalidArgumentException;

/**
 * Ethiopian month starts, found by counting days.
 *
 * Two counters are kept side by side, one for the Gregorian date and one for
 * the Ethiopian date, and both are moved one day at a time away from a single
 * known pair, forwards and backwards. Whenever the Ethiopian counter reads the
 * first of a month, the Gregorian counter is written down.
 *
 * Nothing here converts a date to a day number, and there is no closed form:
 * the only things taken from the calendars' rules are the month lengths, the
 * Ethiopian leap year (Pagume has 6 days when the year leaves 3 after division
 * by 4, otherwise 5) and the Gregorian leap year.
 */
final class Odometer
{
    /**
     * @param  array{gregorian: array{0: int, 1: int, 2: int}, ethiopian: array{0: int, 1: int, 2: int}}  $seed
     * @return array<int, array{months: list<string>, pagume_days: int}> keyed by Ethiopian year
     */
    public static function table(array $seed, int $fromYear, int $toYear): array
    {
        [$gy, $gm, $gd] = array_map('intval', $seed['gregorian']);
        [$ey, $em, $ed] = array_map('intval', $seed['ethiopian']);

        if ($em < 1 || $em > 13 || $ed < 1 || $ed > self::ethiopianMonthLength($ey, $em)) {
            throw new InvalidArgumentException('the seed is not a real Ethiopian date');
        }
        if ($gm < 1 || $gm > 12 || $gd < 1 || $gd > self::gregorianMonthLength($gy, $gm)) {
            throw new InvalidArgumentException('the seed is not a real Gregorian date');
        }
        if ($fromYear > $ey || $toYear < $ey) {
            throw new InvalidArgumentException('the seed must lie inside the years asked for');
        }

        /** @var array<int, array<int, string>> $starts */
        $starts = [];
        /** @var array<int, int> $pagume */
        $pagume = [];

        // Forwards from the seed, to the last day of the last year.
        [$cy, $cm, $cd, $ay, $am, $ad] = [$gy, $gm, $gd, $ey, $em, $ed];
        while (true) {
            if ($ad === 1) {
                $starts[$ay][$am] = sprintf('%04d-%02d-%02d', $cy, $cm, $cd);
            }
            if ($am === 13) {
                $pagume[$ay] = $ad;
            }
            if ($ay === $toYear && $am === 13 && $ad === self::ethiopianMonthLength($ay, 13)) {
                break;
            }
            [$cy, $cm, $cd] = self::nextGregorian($cy, $cm, $cd);
            [$ay, $am, $ad] = self::nextEthiopian($ay, $am, $ad);
        }

        // Backwards from the seed, to the first day of the first year.
        [$cy, $cm, $cd, $ay, $am, $ad] = [$gy, $gm, $gd, $ey, $em, $ed];
        while (true) {
            if ($ad === 1) {
                $starts[$ay][$am] = sprintf('%04d-%02d-%02d', $cy, $cm, $cd);
            }
            if ($ay === $fromYear && $am === 1 && $ad === 1) {
                break;
            }
            [$cy, $cm, $cd] = self::previousGregorian($cy, $cm, $cd);
            [$ay, $am, $ad] = self::previousEthiopian($ay, $am, $ad);
            if ($am === 13 && ! isset($pagume[$ay])) {
                $pagume[$ay] = $ad;
            }
        }

        $table = [];
        for ($year = $fromYear; $year <= $toYear; $year++) {
            ksort($starts[$year]);
            $table[$year] = ['months' => array_values($starts[$year]), 'pagume_days' => $pagume[$year]];
        }

        return $table;
    }

    private static function gregorianLeap(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    private static function gregorianMonthLength(int $year, int $month): int
    {
        return match ($month) {
            2 => self::gregorianLeap($year) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    private static function ethiopianMonthLength(int $year, int $month): int
    {
        if ($month < 13) {
            return 30;
        }

        return $year % 4 === 3 ? 6 : 5;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function nextGregorian(int $y, int $m, int $d): array
    {
        if ($d < self::gregorianMonthLength($y, $m)) {
            return [$y, $m, $d + 1];
        }

        return $m < 12 ? [$y, $m + 1, 1] : [$y + 1, 1, 1];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function previousGregorian(int $y, int $m, int $d): array
    {
        if ($d > 1) {
            return [$y, $m, $d - 1];
        }
        if ($m > 1) {
            return [$y, $m - 1, self::gregorianMonthLength($y, $m - 1)];
        }

        return [$y - 1, 12, 31];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function nextEthiopian(int $y, int $m, int $d): array
    {
        if ($d < self::ethiopianMonthLength($y, $m)) {
            return [$y, $m, $d + 1];
        }

        return $m < 13 ? [$y, $m + 1, 1] : [$y + 1, 1, 1];
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function previousEthiopian(int $y, int $m, int $d): array
    {
        if ($d > 1) {
            return [$y, $m, $d - 1];
        }
        if ($m > 1) {
            return [$y, $m - 1, 30];
        }

        return [$y - 1, 13, self::ethiopianMonthLength($y - 1, 13)];
    }
}
