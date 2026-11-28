<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Calendar arithmetic on plain dates (`Y-m-d`) and Unix timestamps. The only
 * file of the oracle that constructs a date object, and it does so only from a
 * validated absolute date and time or from an `@timestamp`: nothing here can
 * read the clock, and an empty or relative input throws instead of defaulting.
 */
final class Calendar
{
    public static function zone(string $name): DateTimeZone
    {
        if ($name === '' || ! in_array($name, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("unknown time zone: '{$name}'");
        }

        return new DateTimeZone($name);
    }

    /** Strict `Y-m-d` of a real date. */
    public static function date(string $date): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("not an absolute date Y-m-d: '{$date}'");
        }

        return $date;
    }

    /** Seconds after midnight of a strict `H:i` or `H:i:s`. */
    public static function clockSeconds(string $time): int
    {
        if (! preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $time, $m) || (int) $m[1] > 23 || (int) $m[2] > 59 || (int) ($m[3] ?? 0) > 59) {
            throw new InvalidArgumentException("not a time H:i or H:i:s: '{$time}'");
        }

        return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) ($m[3] ?? 0);
    }

    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable(self::date($date).' 12:00:00', new DateTimeZone('UTC')))
            ->modify(sprintf('%+d day', $days))
            ->format('Y-m-d');
    }

    /** The moment `$date` at `$secondOfDay` seconds after local midnight in `$tz`. */
    public static function at(string $date, int $secondOfDay, DateTimeZone $tz): int
    {
        if ($secondOfDay < 0 || $secondOfDay > 86400) {
            throw new InvalidArgumentException("second of day out of range: {$secondOfDay}");
        }
        $time = $secondOfDay === 86400
            ? '24:00:00'
            : sprintf('%02d:%02d:%02d', intdiv($secondOfDay, 3600), intdiv($secondOfDay % 3600, 60), $secondOfDay % 60);

        return (new DateTimeImmutable(self::date($date).' '.$time, $tz))->getTimestamp();
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
        return (int) (new DateTimeImmutable(self::date($date).' 12:00:00', new DateTimeZone('UTC')))->format('N');
    }

    public static function format(int $timestamp, DateTimeZone $tz, string $pattern): string
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone($tz)->format($pattern);
    }
}
