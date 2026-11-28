<?php

declare(strict_types=1);

namespace Tests\Accuracy\Oracle;

use DateTimeZone;

/**
 * Register sections 1 to 4 and 9: one resolved record in, its status, minutes
 * and overtime buckets out. It never decides which records exist.
 */
final class Rater
{
    public function __construct(private readonly Trace $trace) {}

    /**
     * @param  array<string, mixed>|null  $shift  from Shifts::normalise()
     * @param  string  $class  workday, rest_day, holiday, on_leave or not_employed
     * @return array{status: mixed, late: mixed, early: int, worked: int, overtime: mixed}
     */
    public function rate(int $in, ?int $out, ?array $shift, string $workDate, string $class, DateTimeZone $tz): array
    {
        // AR-CLOCK-04: every count of minutes is cut down. Used by all of it.
        $this->trace->implemented('AR-CLOCK-04');

        $nonWorking = in_array($class, ['holiday', 'rest_day', 'not_employed'], true);

        return [
            ...$this->statusAndMinutes($in, $out, $shift, $workDate, $nonWorking, $tz),
            'worked' => $this->worked($in, $out, $shift),
            'overtime' => $this->overtime($in, $out, $shift, $workDate, $class, $tz),
        ];
    }

    /** @return array{status: mixed, late: mixed, early: int} */
    private function statusAndMinutes(int $in, ?int $out, ?array $shift, string $workDate, bool $nonWorking, DateTimeZone $tz): array
    {
        if ($shift === null) {
            // AR-SHIFT-06
            return ['status' => 'pending', 'late' => 0, 'early' => 0];
        }

        if ($nonWorking) {
            // AR-MIN-06 (Implemented): no late or early minutes on a day that is not a working day.
            $this->trace->implemented('AR-MIN-06');

            return [
                'status' => new NoOpinion('the register gives no status for a record on a day that is not a working day'),
                'late' => 0,
                'early' => 0,
            ];
        }

        [$start, $end] = Shifts::window($shift, $workDate, $tz);
        $graceEnd = $start + $shift['grace'] * 60;

        if ($in < $start) {
            // Arrival before shift start: AR-MIN-07 is Undecided. It decides what
            // the early minutes earn, not the status or late minutes asked here.
            $this->trace->undecided('AR-MIN-07');
        }

        $early = 0;
        if ($out !== null && $out < $end - $shift['early'] * 60) {
            // AR-STATUS-03 and AR-MIN-02, counted whatever the day's lateness (AR-STATUS-04, AR-MIN-03).
            $early = intdiv($end - $out, 60);
        }

        if ($in <= $graceEnd) {
            return ['status' => $early > 0 ? 'early_leave' : 'present', 'late' => 0, 'early' => $early];
        }

        if ($in < $graceEnd + 60) {
            // AR-STATUS-07: past the end of grace but inside the same minute.
            $this->trace->undecided('AR-STATUS-07');
            $late = intdiv($in - $start, 60);

            return [
                'status' => new Undecided('AR-STATUS-07', 'late'),
                'late' => new Undecided('AR-STATUS-07', $late),
                'early' => $early,
            ];
        }

        // AR-STATUS-01, AR-MIN-01, AR-STATUS-04: late stays late.
        return ['status' => 'late', 'late' => intdiv($in - $start, 60), 'early' => $early];
    }

    private function worked(int $in, ?int $out, ?array $shift): int
    {
        if ($out === null) {
            // AR-MIN-05 (Implemented)
            $this->trace->implemented('AR-MIN-05');

            return 0;
        }

        if ($shift === null) {
            $this->trace->implemented('AR-MIN-05');
        }

        // AR-MIN-04: the fixed break once, never below zero.
        return max(0, intdiv($out - $in, 60) - ($shift['break'] ?? 0));
    }

    /**
     * Overtime buckets in minutes, zero buckets left out.
     *
     * @return array<string, int>
     */
    private function overtime(int $in, ?int $out, ?array $shift, string $workDate, string $class, DateTimeZone $tz): array
    {
        if ($out === null || $out <= $in || $class === 'not_employed') {
            return [];
        }

        if ($class === 'holiday' || $class === 'rest_day') {
            // AR-OT-02, AR-OT-11: the whole span, no break subtracted.
            $total = intdiv($out - $in, 60);
            $night = intdiv($this->nightSeconds($in, $out, $tz), 60);

            if ($class === 'holiday') {
                return $this->clean(['holiday' => $total - $night, 'holiday_night' => $night]);
            }

            // AR-OT-12 (Implemented): no rest day night rate.
            $this->trace->implemented('AR-OT-12');

            return $this->clean(['rest_day' => $total]);
        }

        if ($shift === null) {
            // AR-OT-08
            return [];
        }

        // AR-OT-01: from the later of the shift's end and the check-in.
        $end = Shifts::window($shift, $workDate, $tz)[1];
        $from = max($end, $in);
        if ($out <= $from) {
            return [];
        }

        $total = intdiv($out - $from, 60);
        $night = intdiv($this->nightSeconds($from, $out, $tz), 60);
        $buckets = $this->clean(['normal' => $total - $night, 'night' => $night]);

        if ($buckets !== []) {
            // AR-OT-09 is Undecided: the oracle applies no minimum, as the register
            // says today's behaviour is, and records that the answer rests on it.
            $this->trace->undecided('AR-OT-09');
        }

        return $buckets;
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
            foreach ([[0, $date, 6 * 3600], [22 * 3600, $date, 24 * 3600]] as [$startSecond, $day, $endSecond]) {
                $windowStart = Calendar::at($day, $startSecond, $tz);
                $windowEnd = $endSecond === 24 * 3600 ? Calendar::at($next, 0, $tz) : Calendar::at($day, $endSecond, $tz);
                $seconds += max(0, min($to, $windowEnd) - max($from, $windowStart));
            }
            $date = $next;
        }

        return $seconds;
    }

    /**
     * @param  array<string, int>  $buckets
     * @return array<string, int>
     */
    private function clean(array $buckets): array
    {
        return array_filter($buckets, static fn (int $minutes): bool => $minutes > 0);
    }
}
