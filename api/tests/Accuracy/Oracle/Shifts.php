<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

use DateTimeZone;

/** A shift from the fixture file, with its times as seconds after midnight. */
final class Shifts
{
    /**
     * @param  array<string, mixed>  $raw  one entry of fixtures/shifts.php
     * @return array{start: int, end: int, crosses: bool, grace: int, early: int, break: int, days: list<int>}
     */
    public static function normalise(array $raw): array
    {
        $start = self::seconds((string) $raw['start_time']);
        $end = self::seconds((string) $raw['end_time']);

        return [
            'start' => $start,
            'end' => $end,
            // AR-SHIFT-04: an end that is not after the start is on the next day.
            'crosses' => $end <= $start,
            'grace' => (int) $raw['grace_minutes'],
            'early' => (int) $raw['early_departure_minutes'],
            'break' => (int) $raw['break_minutes'],
            'days' => array_map('intval', explode(',', (string) $raw['working_days'])),
        ];
    }

    /**
     * Start and end moments of the shift worked on `$workDate`.
     *
     * @param  array{start: int, end: int, crosses: bool}  $shift
     * @return array{0: int, 1: int}
     */
    public static function window(array $shift, string $workDate, DateTimeZone $tz): array
    {
        $endDate = $shift['crosses'] ? Calendar::addDays($workDate, 1) : $workDate;

        return [
            Calendar::at($workDate, $shift['start'], $tz),
            Calendar::at($endDate, $shift['end'], $tz),
        ];
    }

    private static function seconds(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 3600 + $m * 60;
    }
}
