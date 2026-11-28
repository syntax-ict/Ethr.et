<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

use Closure;
use DateTimeZone;

/**
 * Register sections 1 to 4 and 9: one resolved record in, its status, minutes
 * and overtime buckets out. It never decides which records exist. Every
 * Undecided rule it meets is read through the Readings it was given.
 */
final class Rater
{
    /**
     * @param  Closure(string): string  $classOf  the class of any calendar date (AR-OT-13)
     */
    public function __construct(
        private readonly Trace $trace,
        private readonly Readings $readings,
        private readonly Closure $classOf,
    ) {}

    /**
     * @param  array<string, mixed>|null  $shift  from Shifts::normalise()
     * @param  string  $class  workday, rest_day, holiday, on_leave or not_employed
     * @return array{status: mixed, late: int, early: int, worked: int, overtime: array<string, int>}
     */
    public function rate(int $in, ?int $out, ?array $shift, string $workDate, string $class, DateTimeZone $tz, bool $takeBreak = true): array
    {
        // AR-CLOCK-04: every count of minutes is cut down.
        $this->trace->implemented('AR-CLOCK-04');

        // AR-ABS-08 is read here: a leave day rated as a day off or as a workday.
        $dayOff = in_array($class, ['holiday', 'rest_day', 'not_employed'], true)
            || ($class === 'on_leave' && $this->readings->flag('leave_day_off'));

        return [
            ...$this->statusAndMinutes($in, $out, $shift, $workDate, $dayOff, $tz),
            'worked' => $this->worked($in, $out, $shift, $takeBreak),
            'overtime' => $this->overtime($in, $out, $shift, $workDate, $class, $dayOff, $tz),
        ];
    }

    /** @return array{status: mixed, late: int, early: int} */
    private function statusAndMinutes(int $in, ?int $out, ?array $shift, string $workDate, bool $dayOff, DateTimeZone $tz): array
    {
        if ($shift === null) {
            $this->trace->decided('AR-SHIFT-06');

            return ['status' => 'pending', 'late' => 0, 'early' => 0];
        }

        if ($dayOff) {
            $this->trace->implemented('AR-MIN-06');

            return [
                'status' => new NoOpinion('the register gives no status for a record on a day that is not a working day'),
                'late' => 0,
                'early' => 0,
            ];
        }

        [$start, $end] = Shifts::window($shift, $workDate, $tz);
        $this->trace->decided('AR-STATUS-02');

        $early = 0;
        if ($out !== null) {
            $missed = intdiv($end - $out, 60);
            // AR-STATUS-03; the window is to the second or in whole minutes (AR-CLOCK-06).
            $isEarly = $this->readings->flag('minutes')
                ? $missed > $shift['early']
                : $out < $end - $shift['early'] * 60;
            if ($isEarly) {
                $this->trace->decided('AR-STATUS-03');
                $this->trace->decided('AR-MIN-02');
                $early = $missed;
            }
        }

        // AR-STATUS-01, with the grace boundary read to the second or in minutes (AR-STATUS-07).
        $present = $this->readings->flag('grace_minutes')
            ? intdiv($in - $start, 60) <= $shift['grace']
            : $in <= $start + $shift['grace'] * 60;
        $this->trace->decided('AR-STATUS-01');

        if ($present) {
            return ['status' => $early > 0 ? 'early_leave' : 'present', 'late' => 0, 'early' => $early];
        }

        $this->trace->decided('AR-MIN-01');
        if ($early > 0) {
            $this->trace->decided('AR-STATUS-04');
            $this->trace->decided('AR-MIN-03');
        }

        return ['status' => 'late', 'late' => intdiv($in - $start, 60), 'early' => $early];
    }

    private function worked(int $in, ?int $out, ?array $shift, bool $takeBreak): int
    {
        if ($out === null) {
            $this->trace->implemented('AR-MIN-05');

            return 0;
        }

        if ($shift === null) {
            $this->trace->implemented('AR-MIN-05');
        }
        $this->trace->decided('AR-MIN-04');

        // AR-MIN-04: the fixed break once, never below zero. Once per record or
        // once per day is AR-MIN-08, read through $takeBreak by the caller.
        return max(0, intdiv($out - $in, 60) - ($takeBreak ? ($shift['break'] ?? 0) : 0));
    }

    /**
     * Overtime buckets in minutes, zero buckets left out.
     *
     * @return array<string, int>
     */
    private function overtime(int $in, ?int $out, ?array $shift, string $workDate, string $class, bool $dayOff, DateTimeZone $tz): array
    {
        if ($out === null || $out <= $in || $class === 'not_employed') {
            return [];
        }

        if ($dayOff) {
            $this->trace->decided('AR-OT-02');
            $from = $in; // the whole span, no break subtracted
        } else {
            if ($shift === null) {
                $this->trace->decided('AR-OT-08');

                return [];
            }
            // AR-OT-01 (and AR-MIN-07: arriving early earns nothing): from the later of the shift's end and the check-in.
            $this->trace->decided('AR-OT-01');
            $from = max(Shifts::window($shift, $workDate, $tz)[1], $in);
            if ($out <= $from) {
                return [];
            }
        }

        // Seconds per kind, each calendar day of the span taking the work date's
        // class, or its own (AR-OT-13).
        $effective = $dayOff && $class === 'on_leave' ? 'rest_day' : $class;
        $seconds = [];
        $cursor = $from;
        while ($cursor < $out) {
            $date = Calendar::localDate($cursor, $tz);
            $segmentEnd = min($out, Calendar::at(Calendar::addDays($date, 1), 0, $tz));
            $segmentClass = $effective;
            if ($this->readings->flag('calendar_day_class') && $date !== $workDate) {
                $own = ($this->classOf)($date);
                $segmentClass = in_array($own, ['holiday', 'rest_day'], true) ? $own : 'workday';
            }
            $night = $this->nightSeconds($cursor, $segmentEnd, $tz);
            $day = ($segmentEnd - $cursor) - $night;

            foreach ($this->kinds($segmentClass, $day, $night) as $kind => $s) {
                $seconds[$kind] = ($seconds[$kind] ?? 0) + $s;
            }
            $cursor = $segmentEnd;
        }

        return $this->cut($seconds);
    }

    /**
     * @return array<string, int> seconds by overtime kind for one segment
     */
    private function kinds(string $class, int $daySeconds, int $nightSeconds): array
    {
        switch ($class) {
            case 'holiday':
                $this->trace->decided('AR-OT-11');
                $this->trace->decided('AR-OT-03');

                return ['holiday' => $daySeconds, 'holiday_night' => $nightSeconds];
            case 'rest_day':
                // AR-OT-12 (Implemented): no rest day night rate.
                $this->trace->implemented('AR-OT-12');

                return ['rest_day' => $daySeconds + $nightSeconds];
            default:
                $this->trace->decided('AR-OT-03');

                return ['normal' => $daySeconds, 'night' => $nightSeconds];
        }
    }

    /**
     * Whole minutes per kind. AR-CLOCK-07: cut once for the span (the remainder
     * to the day kind) or once per kind.
     *
     * @param  array<string, int>  $seconds
     * @return array<string, int>
     */
    private function cut(array $seconds): array
    {
        $this->trace->implemented('AR-CLOCK-04');
        $minutes = array_map(static fn (int $s): int => intdiv($s, 60), $seconds);

        if (! $this->readings->flag('cut_per_kind')) {
            $dayKinds = array_diff_key($seconds, ['night' => 1, 'holiday_night' => 1]);
            if ($dayKinds !== []) {
                arsort($dayKinds);
                $main = array_key_first($dayKinds);
                $minutes[$main] = intdiv(array_sum($seconds), 60) - (array_sum($minutes) - $minutes[$main]);
            }
        }

        return array_filter($minutes, static fn (int $m): bool => $m > 0);
    }

    /**
     * Seconds of [from, to) inside the night window, 22:00 up to but not
     * including 06:00, on every calendar day the span touches (AR-OT-03).
     */
    private function nightSeconds(int $from, int $to, DateTimeZone $tz): int
    {
        $seconds = 0;
        $date = Calendar::addDays(Calendar::localDate($from, $tz), -1);
        $last = Calendar::addDays(Calendar::localDate($to, $tz), 1);

        while ($date <= $last) {
            $next = Calendar::addDays($date, 1);
            $midnight = Calendar::at($date, 0, $tz);
            foreach ([[$midnight, Calendar::at($date, 6 * 3600, $tz)], [Calendar::at($date, 22 * 3600, $tz), Calendar::at($next, 0, $tz)]] as [$windowStart, $windowEnd]) {
                $seconds += max(0, min($to, $windowEnd) - max($from, $windowStart));
            }
            $date = $next;
        }

        return $seconds;
    }
}
