<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Calendar\EthiopianCalendar;

/**
 * Which calendar a column of dates is in, when its dates say so themselves.
 *
 * Most dates are real in both calendars: 2016-04-15 is a day of either, and
 * nothing in it says which one was meant. Some are real in one only. A 13th
 * month, a 30 February or a 29 February of a Gregorian common year is
 * Ethiopian; a 31st is Gregorian, since every Ethiopian month has 30 days or
 * fewer. One such date is enough to know, and only such dates count: a list
 * of dates real in both calendars says nothing, and nothing is guessed.
 *
 * A column holding dates of both kinds says nothing either: its lines are
 * refused one by one, whichever calendar is chosen. Dates that name their
 * own calendar ("2011-10-30 E.C.") and the day numbers Excel keeps a date as
 * are read as they say, and are left out.
 *
 * Only a hint for the reader (EmployeeImportGrid): the calendar a list is
 * read in is always the one they chose or the column name gave.
 */
final class DateColumnCalendar
{
    /**
     * @param  array<int, string>  $values  the column's cells, by the line the reader counts
     * @return array{calendar: 'ethiopian'|'gregorian', value: string, line: int}|null
     */
    public static function detect(array $values): ?array
    {
        $ethiopian = null;
        $gregorian = null;
        $calendar = app(EthiopianCalendar::class);

        foreach ($values as $line => $value) {
            $value = trim($value);
            if ($value === '' || EmployeeImportRow::isSerialDay($value)) {
                continue;
            }

            $parts = EmployeeImportRow::dateParts($value);
            if ($parts === null || $parts[3] !== null) {
                continue;
            }

            [$year, $month, $day] = $parts;
            $inEthiopian = self::ethiopianDay($calendar, $year, $month, $day);
            $inGregorian = checkdate($month, $day, $year);

            if ($inEthiopian && ! $inGregorian) {
                $ethiopian ??= ['calendar' => 'ethiopian', 'value' => $value, 'line' => $line];
            } elseif ($inGregorian && ! $inEthiopian) {
                $gregorian ??= ['calendar' => 'gregorian', 'value' => $value, 'line' => $line];
            }

            if ($ethiopian !== null && $gregorian !== null) {
                return null;
            }
        }

        return $ethiopian ?? $gregorian;
    }

    private static function ethiopianDay(EthiopianCalendar $calendar, int $year, int $month, int $day): bool
    {
        if ($month < 1 || $month > 13 || $day < 1) {
            return false;
        }

        return $day <= ($month === 13 ? $calendar->daysInPagume($year) : 30);
    }
}
