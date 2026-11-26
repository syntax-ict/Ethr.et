<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The one code a monthly timesheet shows for one employee on one day.
 *
 * The code is a reading of what the attendance rules already decided: the
 * day's `ExpectedDay`, the day's records and the leave type. It adds no rule.
 * Where it names two things at once, the order is fixed (late before early,
 * a missing punch before both) so the legend, the grid and the export agree.
 *
 * `short()` is the printed letter or letters; the label a reader sees comes
 * from `lang/*\/report.php` (`timesheet.code.<value>`).
 */
enum TimesheetCode: string
{
    case PRESENT = 'present';
    case LATE = 'late';
    case EARLY_LEAVE = 'early_leave';
    case ABSENT = 'absent';
    case MISSING_PUNCH = 'missing_punch';
    case ANNUAL_LEAVE = 'annual_leave';
    case SICK_LEAVE = 'sick_leave';
    case MATERNITY_LEAVE = 'maternity_leave';
    case OTHER_LEAVE = 'other_leave';
    case FIELD_DUTY = 'field_duty';
    case HALF_DAY_LEAVE = 'half_day_leave';
    case HOLIDAY = 'holiday';
    case REST_DAY = 'rest_day';
    case NOT_EMPLOYED = 'not_employed';

    /** The letters printed in the grid and the legend. */
    public function short(): string
    {
        return match ($this) {
            self::PRESENT => 'P',
            self::LATE => 'L',
            self::EARLY_LEAVE => 'E',
            self::ABSENT => 'A',
            self::MISSING_PUNCH => 'M',
            self::ANNUAL_LEAVE => 'AL',
            self::SICK_LEAVE => 'SL',
            self::MATERNITY_LEAVE => 'ML',
            self::OTHER_LEAVE => 'OL',
            self::FIELD_DUTY => 'FD',
            self::HALF_DAY_LEAVE => '½',
            self::HOLIDAY => 'H',
            self::REST_DAY => 'R',
            self::NOT_EMPLOYED => '–',
        };
    }

    /** A day the person was at work or expected there, as opposed to a day off. */
    public function isLeave(): bool
    {
        return in_array($this, [
            self::ANNUAL_LEAVE,
            self::SICK_LEAVE,
            self::MATERNITY_LEAVE,
            self::OTHER_LEAVE,
            self::FIELD_DUTY,
            self::HALF_DAY_LEAVE,
        ], true);
    }

    /**
     * The leave code for an approved request's type, read from the type's own
     * fields and never from its name: a duty type is field duty, an Art. 85
     * type is sick leave, and the catalogue codes `annual` and `maternity`
     * keep their letters. Anything else is other leave. A request for a
     * single day of less than a day is a half day, whatever the type.
     */
    public static function forLeave(string $kind, bool $followsArt85, ?string $typeCode, bool $halfDay): self
    {
        if ($halfDay) {
            return self::HALF_DAY_LEAVE;
        }

        if ($kind === 'duty') {
            return self::FIELD_DUTY;
        }

        if ($followsArt85) {
            return self::SICK_LEAVE;
        }

        return match ($typeCode) {
            'annual' => self::ANNUAL_LEAVE,
            'maternity' => self::MATERNITY_LEAVE,
            default => self::OTHER_LEAVE,
        };
    }
}
