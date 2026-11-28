<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Calendar arithmetic on plain dates (`Y-m-d`) and Unix timestamps. Nothing
 * here reads the clock: every moment is built from an absolute string or an
 * `@timestamp`.
 */
final class Calendar
{
    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date.' 12:00:00', new DateTimeZone('UTC')))
            ->modify(sprintf('%+d day', $days))
            ->format('Y-m-d');
    }

    /** The moment `$date` at `$secondOfDay` seconds after local midnight in `$tz`. */
    public static function at(string $date, int $secondOfDay, DateTimeZone $tz): int
    {
        $time = sprintf('%02d:%02d:%02d', intdiv($secondOfDay, 3600), intdiv($secondOfDay % 3600, 60), $secondOfDay % 60);

        return (new DateTimeImmutable($date.' '.$time, $tz))->getTimestamp();
    }

    public static function localDate(int $timestamp, DateTimeZone $tz): string
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone($tz)->format('Y-m-d');
    }

    public static function secondOfDay(int $timestamp, DateTimeZone $tz): int
    {
        $local = (new DateTimeImmutable('@'.$timestamp))->setTimezone($tz);

        return ((int) $local->format('G')) * 3600 + ((int) $local->format('i')) * 60 + (int) $local->format('s');
    }

    /** 1 Monday to 7 Sunday. */
    public static function isoWeekday(string $date): int
    {
        return (int) (new DateTimeImmutable($date.' 12:00:00', new DateTimeZone('UTC')))->format('N');
    }

    public static function format(int $timestamp, DateTimeZone $tz, string $pattern): string
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone($tz)->format($pattern);
    }
}
