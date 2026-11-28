<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A date or a date and time a workbook holds as a date, not as text.
 *
 * `Xlsx` never decides that a string looks like a date: an employee code or a
 * note that happens to read "2026-11-27" must stay text. A caller that knows
 * the field is a date says so by building one of these. The value is a wall
 * clock, the digits a person reads off the cell: Excel has no time zones, so
 * a time is converted to the organisation's zone before it gets here.
 */
final readonly class XlsxDate
{
    /** The number formats a cell shows, written the way Excel writes them. */
    public const DATE_FORMAT = 'yyyy-mm-dd';

    public const DATE_TIME_FORMAT = 'yyyy-mm-dd hh:mm';

    private function __construct(
        public DateTimeImmutable $value,
        public bool $withTime,
    ) {}

    /**
     * `Y-m-d` as a date, or the text itself when it is not exactly that: an
     * export never fails on a value it cannot read.
     */
    public static function fromDate(string $text): self|string
    {
        return self::parse($text, 'Y-m-d', false);
    }

    /**
     * `Y-m-d H:i`, as `ReaderDates::localStamp()` prints it, as a date and
     * time; the text itself when it is not exactly that.
     */
    public static function fromStamp(string $text): self|string
    {
        return self::parse($text, 'Y-m-d H:i', true);
    }

    private static function parse(string $text, string $format, bool $withTime): self|string
    {
        $value = DateTimeImmutable::createFromFormat('!'.$format, $text, new DateTimeZone('UTC'));

        // A format alone accepts 2026-02-31 and rolls it into March.
        if ($value === false || $value->format($format) !== $text) {
            return $text;
        }

        return new self($value, $withTime);
    }
}
