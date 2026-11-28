<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

use DateTimeZone;

/** Register section 8: what a day is for a person, and whether they were absent. */
final class DayClassifier
{
    /**
     * @param  array{hire: string, termination: ?string, holidays: list<string>, leave: ?array{0: string, 1: string}, shift: ?array<string, mixed>}  $person
     */
    public function __construct(private readonly array $person) {}

    /** AR-ABS-01: not employed, holiday, rest day, on leave, workday, in that order. */
    public function classify(string $date, Trace $trace): string
    {
        $trace->decided('AR-ABS-01');

        if ($date < $this->person['hire'] || ($this->person['termination'] !== null && $date > $this->person['termination'])) {
            return 'not_employed';
        }

        // AR-ABS-03: only holidays of the person's own branch are in the list.
        if (in_array($date, $this->person['holidays'], true)) {
            $trace->decided('AR-ABS-03');

            return 'holiday';
        }

        // AR-ABS-02: the shift's working days, else Monday to Friday.
        $trace->decided('AR-ABS-02');
        $workingDays = $this->person['shift']['days'] ?? [1, 2, 3, 4, 5];
        if (! in_array(Calendar::isoWeekday($date), $workingDays, true)) {
            return 'rest_day';
        }

        $leave = $this->person['leave'];
        if ($leave !== null && $date >= $leave[0] && $date <= $leave[1]) {
            return 'on_leave';
        }

        return 'workday';
    }

    /**
     * AR-ABS-04 and AR-ABS-05. `$now` is the moment the day is read, or null
     * when the case gives none (then no day has passed).
     */
    public function absent(string $date, string $class, bool $hasRecord, bool $organisationRecordsAttendance, ?int $now, DateTimeZone $tz, Trace $trace): bool
    {
        // AR-STATUS-06 (Implemented): absence is worked out on reading, never stored.
        $trace->implemented('AR-STATUS-06');

        if ($now === null || $class !== 'workday' || $hasRecord) {
            return false;
        }
        $trace->decided('AR-ABS-04');
        if (! $organisationRecordsAttendance) {
            $trace->decided('AR-ABS-05');

            return false;
        }

        return $date < Calendar::localDate($now, $tz);
    }
}
