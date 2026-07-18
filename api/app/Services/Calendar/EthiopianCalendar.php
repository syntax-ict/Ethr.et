<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Bidirectional Ethiopian <-> Gregorian calendar conversion.
 *
 * The Ethiopian (Amete Mihret / Incarnation) calendar has 13 months: twelve
 * months of exactly 30 days plus a 13th month, Pagume, of 5 days (6 in a leap
 * year). Its leap rule is the simple Julian one — a year is leap when
 * (year mod 4) == 3 — so it runs in perfect lockstep with the Julian calendar.
 * Rather than a floating "New Year = Sept 11/12" approximation, this service
 * converts through the astronomical Julian Day Number (JDN), which is exact for
 * all dates and correctly tracks the widening Julian/Gregorian gap over
 * centuries.
 *
 * Anchor: Ethiopian 1/1/1 (Meskerem 1, Year 1) == 29 August 8 CE (Julian),
 * whose JDN is 1,724,221. Verified reference points (Gregorian):
 *   - Meskerem 1, 2017 EC  == 2024-09-11
 *   - Meskerem 1, 2016 EC  == 2023-09-12  (follows the 6-day Pagume of 2015 EC)
 *   - Pagume 1..6, 2015 EC == 2023-09-06 .. 2023-09-11  (leap year, 6 days)
 *   - Pagume 1..5, 2016 EC == 2024-09-06 .. 2024-09-10  (common year, 5 days)
 */
final class EthiopianCalendar
{
    /** JDN of Ethiopian 1/1/1 (Meskerem 1, Year 1 EC). */
    private const JDN_EPOCH_OFFSET = 1_724_221;

    public function isLeapYear(int $ethiopianYear): bool
    {
        return $ethiopianYear % 4 === 3;
    }

    /** Length of Pagume (the 13th month) for the given Ethiopian year: 5 or 6. */
    public function daysInPagume(int $ethiopianYear): int
    {
        return $this->isLeapYear($ethiopianYear) ? 6 : 5;
    }

    /**
     * Convert a Gregorian date to its Ethiopian calendar components.
     *
     * @return array{year: int, month: int, day: int}
     */
    public function gregorianToEthiopian(Carbon $date): array
    {
        return $this->jdnToEthiopian(
            $this->gregorianToJdn($date->year, $date->month, $date->day)
        );
    }

    /**
     * Convert an Ethiopian calendar date to a Gregorian Carbon (at 00:00 UTC).
     */
    public function ethiopianToGregorian(int $year, int $month, int $day): Carbon
    {
        $this->assertValidEthiopianDate($year, $month, $day);

        [$gy, $gm, $gd] = $this->jdnToGregorian($this->ethiopianToJdn($year, $month, $day));

        return Carbon::create($gy, $gm, $gd, 0, 0, 0, 'UTC');
    }

    /**
     * Count how many days of the inclusive Gregorian range [start, end] fall
     * within Ethiopian Pagume (month 13). A monthly payroll period can overlap
     * at most one Pagume, so the matched Ethiopian year and its Pagume length
     * (5 or 6) are reported alongside the count.
     *
     * @return array{days: int, ethiopian_year: int|null, pagume_length: int|null}
     */
    public function pagumeDaysInRange(Carbon $start, Carbon $end): array
    {
        $cursor = $start->copy()->startOfDay();
        $last = $end->copy()->startOfDay();

        $days = 0;
        $ethiopianYear = null;

        while ($cursor->lte($last)) {
            $eth = $this->gregorianToEthiopian($cursor);
            if ($eth['month'] === 13) {
                $days++;
                $ethiopianYear ??= $eth['year'];
            }
            $cursor->addDay();
        }

        return [
            'days' => $days,
            'ethiopian_year' => $ethiopianYear,
            'pagume_length' => $ethiopianYear !== null ? $this->daysInPagume($ethiopianYear) : null,
        ];
    }

    private function ethiopianToJdn(int $year, int $month, int $day): int
    {
        return self::JDN_EPOCH_OFFSET
            + $this->ethiopianYearStartOffset($year)
            + 30 * ($month - 1)
            + ($day - 1);
    }

    /**
     * @return array{year: int, month: int, day: int}
     */
    private function jdnToEthiopian(int $jdn): array
    {
        $n = $jdn - self::JDN_EPOCH_OFFSET; // 0-based day index; day 0 == 1/1/1

        // Estimate the year, then nudge into place. The 6-day Pagume of a leap
        // year sits mid-cycle, so a closed form is fiddly; a 1-2 step adjustment
        // is trivially correct and cheap.
        $year = intdiv($n, 365) + 1;
        while ($this->ethiopianYearStartOffset($year) > $n) {
            $year--;
        }
        while ($this->ethiopianYearStartOffset($year + 1) <= $n) {
            $year++;
        }

        $dayOfYear = $n - $this->ethiopianYearStartOffset($year); // 0-based

        return [
            'year' => $year,
            'month' => intdiv($dayOfYear, 30) + 1,
            'day' => ($dayOfYear % 30) + 1,
        ];
    }

    /** Days from the epoch (Meskerem 1, Year 1) to Meskerem 1 of $year. */
    private function ethiopianYearStartOffset(int $year): int
    {
        return 365 * ($year - 1) + intdiv($year, 4);
    }

    private function gregorianToJdn(int $year, int $month, int $day): int
    {
        $a = intdiv(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;

        return $day
            + intdiv(153 * $m + 2, 5)
            + 365 * $y
            + intdiv($y, 4)
            - intdiv($y, 100)
            + intdiv($y, 400)
            - 32045;
    }

    /**
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    private function jdnToGregorian(int $jdn): array
    {
        $a = $jdn + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);

        $day = $e - intdiv(153 * $m + 2, 5) + 1;
        $month = $m + 3 - 12 * intdiv($m, 10);
        $year = 100 * $b + $d - 4800 + intdiv($m, 10);

        return [$year, $month, $day];
    }

    private function assertValidEthiopianDate(int $year, int $month, int $day): void
    {
        if ($month < 1 || $month > 13) {
            throw new InvalidArgumentException("Ethiopian month must be 1-13, got {$month}.");
        }

        $maxDay = $month === 13 ? $this->daysInPagume($year) : 30;

        if ($day < 1 || $day > $maxDay) {
            throw new InvalidArgumentException(
                "Ethiopian day must be 1-{$maxDay} for month {$month} of year {$year}, got {$day}."
            );
        }
    }
}
