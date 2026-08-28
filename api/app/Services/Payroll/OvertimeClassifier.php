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
     * @return array{normal: int, night: int, holiday: int, holiday_night: int}
     */
    public function classify(AttendanceRecord $record, bool $isHoliday): array
    {
        $buckets = ['normal' => 0, 'night' => 0, 'holiday' => 0, 'holiday_night' => 0];

        $window = $record->overtimeWindow();
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
