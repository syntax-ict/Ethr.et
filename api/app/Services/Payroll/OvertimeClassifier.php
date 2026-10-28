<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\AttendanceRecord;
use Illuminate\Support\Carbon;

/**
 * Splits a record's overtime minutes into the categories Labour Proclamation
 * No. 1156/2019 Art. 68(1) pays at different rates: normal and night on an
 * ordinary day, rest_day on a weekly rest day, holiday and holiday_night on a
 * public holiday. The rates themselves are OvertimeCalculator's.
 *
 * Night is the `overtime.night_window` statutory parameter, 22:00 to 06:00
 * since 2016 (Ethiopia has no DST), passed in by the caller rather than held
 * here. All arithmetic is in whole minutes.
 */
final class OvertimeClassifier
{
    /**
     * Work on a public holiday or a weekly rest day is paid at its Art. 68(1)
     * rate for the whole time worked — there are no scheduled hours on those
     * days to be "over". Only an ordinary working day counts from the shift's
     * end. Until 2026-10-01 every day counted from the shift's end, so a full
     * day worked on a holiday paid only what ran past 17:30, and rest days
     * were not recognised at all.
     *
     * @param  array{start_hour: int, end_hour: int}|null  $nightWindow  hours of the day night overtime starts and
     *                                                                   ends; required, nullable only so it can follow
     *                                                                   `$isRestDay`'s default
     * @return array{normal: int, night: int, rest_day: int, holiday: int, holiday_night: int}
     */
    public function classify(AttendanceRecord $record, bool $isHoliday, bool $isRestDay = false, ?array $nightWindow = null): array
    {
        if ($nightWindow === null) {
            throw new \InvalidArgumentException('OvertimeClassifier::classify needs the overtime.night_window statutory parameter');
        }

        $buckets = ['normal' => 0, 'night' => 0, 'rest_day' => 0, 'holiday' => 0, 'holiday_night' => 0];

        $window = ($isHoliday || $isRestDay) ? $record->workedWindow() : $record->overtimeWindow();
        if ($window === null) {
            return $buckets;
        }

        [$start, $end] = $window;

        $totalMinutes = (int) $start->diffInMinutes($end);
        $nightMinutes = $this->nightMinutes($start, $end, (int) $nightWindow['start_hour'], (int) $nightWindow['end_hour']);
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
     * Minutes of [start, end] that fall inside the night window, summed
     * across every calendar day the interval touches (handles overtime that
     * crosses midnight).
     */
    private function nightMinutes(Carbon $start, Carbon $end, int $nightStartHour, int $nightEndHour): int
    {
        $minutes = 0;

        $day = $start->copy()->startOfDay();
        $lastDay = $end->copy()->startOfDay();

        while ($day->lte($lastDay)) {
            // Early window: [00:00, end hour)
            $minutes += $this->overlapMinutes(
                $start, $end,
                $day->copy(), $day->copy()->addHours($nightEndHour),
            );

            // Late window: [start hour, next 00:00)
            $minutes += $this->overlapMinutes(
                $start, $end,
                $day->copy()->addHours($nightStartHour), $day->copy()->addDay(),
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
