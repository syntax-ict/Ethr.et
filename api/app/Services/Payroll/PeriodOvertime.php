<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\AttendanceRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * One person's overtime over a pay period, split by the rate it is paid at.
 *
 * This is the arithmetic `PayrollEngine::gatherOvertimeByType()` always did,
 * lifted out so the monthly timesheet adds up overtime with the same code
 * payroll pays from, not a second reading of it. It queries nothing: the
 * caller loads the records, the holidays and the rotation rest days, because
 * payroll loads them for one person and the timesheet for everyone at once.
 *
 * The records handed in must be what payroll reads: both punches present, none
 * voided, and the whole Monday-to-Sunday weeks that touch the period, so a
 * limit week that starts in the previous period is counted whole.
 */
final class PeriodOvertime
{
    public function __construct(private readonly OvertimeClassifier $classifier) {}

    /**
     * @param  Collection<int, AttendanceRecord>  $records  complete, not voided, shift loaded, whole weeks
     * @param  array<string, bool>  $holidayDates  Y-m-d => true, for the person's branch, whole weeks
     * @param  array<string, bool>  $rotationRestDays  Y-m-d => rest day, where a rotation governs the day
     * @param  list<int>  $workingWeek  ISO weekdays, the organisation's, for a record with no shift
     * @return array{buckets: array{normal: int, night: int, rest_day: int, holiday: int, holiday_night: int}, by_day: array<string, int>, by_day_type: array<string, array<string, int>>, skipped: list<array{record: string, kept: string, date: string}>}
     */
    public function tally(
        Collection $records,
        array $holidayDates,
        array $rotationRestDays,
        array $workingWeek,
        Carbon $periodStart,
        Carbon $periodEnd,
        StatutoryRuleSet $rules,
    ): array {
        $limitedTypes = $rules->list('overtime.limited_types');
        $nightWindow = $rules->list('overtime.night_window');
        $buckets = ['normal' => 0, 'night' => 0, 'rest_day' => 0, 'holiday' => 0, 'holiday_night' => 0];
        $byDay = [];
        $byDayType = [];

        $firstPaid = $periodStart->format('Y-m-d');
        $lastPaid = $periodEnd->format('Y-m-d');

        $overlapping = OverlappingAttendance::skipped($records);
        $byId = $records->keyBy('id');
        $skipped = [];

        foreach ($records as $record) {
            $date = Carbon::parse($record->date);
            $day = $date->format('Y-m-d');
            $paid = $day >= $firstPaid && $day <= $lastPaid;

            if (isset($overlapping[$record->id])) {
                if ($paid) {
                    $skipped[] = ['record' => $record->public_id, 'kept' => $byId[$overlapping[$record->id]]->public_id, 'date' => $day];
                }

                continue;
            }

            $isHoliday = isset($holidayDates[$day]);
            $isRestDay = $rotationRestDays[$day]
                ?? ($record->shift !== null
                    ? ! $record->shift->isWorkingDay($date)
                    : ! in_array($date->dayOfWeekIso, $workingWeek, true));

            foreach ($this->classifier->classify($record, $isHoliday, $isRestDay, $nightWindow) as $type => $minutes) {
                if ($paid) {
                    $buckets[$type] += $minutes;
                    if ($minutes > 0) {
                        $byDayType[$day][$type] = ($byDayType[$day][$type] ?? 0) + $minutes;
                    }
                }
                if (in_array($type, $limitedTypes, true)) {
                    $byDay[$day] = ($byDay[$day] ?? 0) + $minutes;
                }
            }
        }

        ksort($byDay);
        ksort($byDayType);

        return ['buckets' => $buckets, 'by_day' => $byDay, 'by_day_type' => $byDayType, 'skipped' => $skipped];
    }
}
