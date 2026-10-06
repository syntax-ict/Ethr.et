<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\AttendanceRecord;
use Illuminate\Support\Carbon;

/**
 * Splits a record's overtime minutes into the four Ethiopian overtime
 * categories so each can be paid at its own rate:
 *   - normal        (1.25x) — daytime, ordinary day
 *   - night         (1.5x)  — night hours, ordinary day
 *   - holiday       (2.0x)  — daytime, public holiday / rest day
 *   - holiday_night (2.5x)  — night hours, public holiday / rest day
 *
 * Night is the 22:00–06:00 window (constant across the year; Ethiopia has no
 * DST). All arithmetic is in whole minutes.
 */
final class OvertimeClassifier
{
    private const NIGHT_START_HOUR = 22;

    private const NIGHT_END_HOUR = 6;

    /**
     * Work on a public holiday or a weekly rest day is paid at its Art. 68(1)
     * rate for the whole time worked — there are no scheduled hours on those
     * days to be "over". Only an ordinary working day counts from the shift's
     * end. Until 2026-10-01 every day counted from the shift's end, so a full
     * day worked on a holiday paid only what ran past 17:30, and rest days
     * were not recognised at all.
     *
     * @return array{normal: int, night: int, rest_day: int, holiday: int, holiday_night: int}
     */
    public function classify(AttendanceRecord $record, bool $isHoliday, bool $isRestDay = false): array
    {
        $buckets = ['normal' => 0, 'night' => 0, 'rest_day' => 0, 'holiday' => 0, 'holiday_night' => 0];

        $window = ($isHoliday || $isRestDay) ? $record->workedWindow() : $record->overtimeWindow();
        if ($window === null) {
            return $buckets;
        }

        [$start, $end] = $window;

        $totalMinutes = (int) $start->diffInMinutes($end);
        $nightMinutes = $this->nightMinutes($start, $end);
        $dayMinutes = max(0, $totalMinutes - $nightMinutes);

        if ($isHoliday) {
            $buckets['holiday_night'] = $nightMinutes;
            $buckets['holiday'] = $dayMinutes;
        } elseif ($isRestDay) {
            // 2x already exceeds the 1.75x night rate, so a rest day's night
            // hours are rest-day hours too.
            $buckets['rest_day'] = $totalMinutes;
        } else {
            $buckets['night'] = $nightMinutes;
            $buckets['normal'] = $dayMinutes;
        }

        return $buckets;
    }

    /**
     * Minutes of [start, end] that fall inside the 22:00–06:00 night window,
     * summed across every calendar day the interval touches (handles overtime
     * that crosses midnight).
     */
    private function nightMinutes(Carbon $start, Carbon $end): int
    {
        $minutes = 0;

        $day = $start->copy()->startOfDay();
        $lastDay = $end->copy()->startOfDay();

        while ($day->lte($lastDay)) {
            // Early window: [00:00, 06:00)
            $minutes += $this->overlapMinutes(
                $start, $end,
                $day->copy(), $day->copy()->addHours(self::NIGHT_END_HOUR),
            );

            // Late window: [22:00, next 00:00)
            $minutes += $this->overlapMinutes(
                $start, $end,
                $day->copy()->addHours(self::NIGHT_START_HOUR), $day->copy()->addDay(),
            );

            $day->addDay();
        }

        return $minutes;
    }

    private function overlapMinutes(Carbon $aStart, Carbon $aEnd, Carbon $bStart, Carbon $bEnd): int
    {
        $start = $aStart->gt($bStart) ? $aStart : $bStart;
        $end = $aEnd->lt($bEnd) ? $aEnd : $bEnd;

        if ($end->lte($start)) {
            return 0;
        }

        return (int) $start->diffInMinutes($end);
    }
}
