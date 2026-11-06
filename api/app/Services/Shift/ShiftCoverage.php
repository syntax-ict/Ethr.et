<?php

declare(strict_types=1);

namespace App\Services\Shift;

use App\Enums\AttendanceStatus;
use App\Enums\ExpectedDay;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\Attendance\ExpectedDays;
use App\Services\Attendance\ShiftMatcher;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * How well each shift is staffed over a range of days: who was scheduled on
 * it, who attended, and the hours planned against the hours covered.
 *
 * Scheduled is the people ExpectedDays calls at work that day (not on leave,
 * not a holiday, employed) whose shift for the day, by ShiftMatcher, is this
 * one. Attended is the distinct people with a non-voided attendance record
 * matched to the shift that day. Planned hours are scheduled person-days
 * times the shift's length less its break; covered hours are the records'
 * worked minutes (check-in to check-out, less the break).
 *
 * Feeds GET /shifts/coverage, the Shifts page's heatmap and stacked bar
 * (charts upgrade, 2026-11-04).
 */
final class ShiftCoverage
{
    public function __construct(
        private readonly ShiftMatcher $matcher,
        private readonly ExpectedDays $expected,
    ) {}

    /**
     * @param  Collection<int, Employee>  $employees  all of one tenant
     * @return array{from: string, to: string, days: list<string>, shifts: list<array<string, mixed>>}
     */
    public function between(int $tenantId, Collection $employees, CarbonInterface $from, CarbonInterface $to, string $zone): array
    {
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');

        $days = [];
        for ($day = Carbon::parse($first); $day->format('Y-m-d') <= $last; $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }

        /** @var array<int, array{shift: Shift, scheduled: array<string, int>, attended: array<string, int>, covered: int}> $byShift */
        $byShift = [];
        $take = function (Shift $shift) use (&$byShift): void {
            $byShift[$shift->id] ??= [
                'shift' => $shift,
                'scheduled' => [],
                'attended' => [],
                'covered' => 0,
            ];
        };

        if ($employees->isNotEmpty()) {
            $expected = $this->expected->between($employees, $from, $to);
            $shifts = $this->matcher->shiftsBetween($employees, $from, $to);

            foreach ($shifts as $employeeId => $perDay) {
                foreach ($perDay as $day => $shift) {
                    if ($shift === null || ($expected[$day][$employeeId] ?? null) !== ExpectedDay::WORKDAY) {
                        continue;
                    }

                    $take($shift);
                    $byShift[$shift->id]['scheduled'][$day] = ($byShift[$shift->id]['scheduled'][$day] ?? 0) + 1;
                }
            }
        }

        $records = AttendanceRecord::query()
            ->where('attendance_records.tenant_id', $tenantId)
            ->whereIn('attendance_records.employee_id', $employees->pluck('id'))
            ->whereNotNull('attendance_records.shift_id')
            ->whereDate('attendance_records.date', '>=', $first)
            ->whereDate('attendance_records.date', '<=', $last)
            ->where('attendance_records.status', '!=', AttendanceStatus::VOIDED)
            ->with('shift')
            ->get(['attendance_records.id', 'attendance_records.employee_id', 'attendance_records.shift_id', 'attendance_records.date', 'attendance_records.check_in', 'attendance_records.check_out']);

        $seen = [];
        foreach ($records as $record) {
            $shift = $record->shift;
            if ($shift === null) {
                continue;
            }

            $take($shift);
            $day = $record->date->format('Y-m-d');
            $key = $shift->id.'|'.$day.'|'.$record->employee_id;
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $byShift[$shift->id]['attended'][$day] = ($byShift[$shift->id]['attended'][$day] ?? 0) + 1;
            }
            $byShift[$shift->id]['covered'] += $record->workedMinutes();
        }

        $rows = [];
        foreach ($byShift as $entry) {
            $shift = $entry['shift'];
            $length = $this->lengthMinutes($shift, $first, $zone);
            $scheduledTotal = array_sum($entry['scheduled']);

            $byDay = [];
            foreach ($days as $day) {
                $byDay[] = [
                    'date' => $day,
                    'scheduled' => (int) ($entry['scheduled'][$day] ?? 0),
                    'attended' => (int) ($entry['attended'][$day] ?? 0),
                ];
            }

            $rows[] = [
                'public_id' => $shift->public_id,
                'name' => $shift->name,
                'name_am' => $shift->name_am,
                'start_time' => substr((string) $shift->start_time, 0, 5),
                'end_time' => substr((string) $shift->end_time, 0, 5),
                'planned_minutes' => (int) ($scheduledTotal * $length),
                'covered_minutes' => (int) $entry['covered'],
                'scheduled_people' => (int) $scheduledTotal,
                'attended_people' => (int) array_sum($entry['attended']),
                'by_day' => $byDay,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [$a['start_time'], $a['name']] <=> [$b['start_time'], $b['name']]);

        return [
            'from' => $first,
            'to' => $last,
            'days' => $days,
            'shifts' => $rows,
        ];
    }

    /** The minutes one person covers by working the whole shift, less its break. */
    private function lengthMinutes(Shift $shift, string $date, string $zone): int
    {
        [$start, $end] = $shift->windowOn($date, $zone);

        return max(0, (int) $start->diffInMinutes($end) - (int) $shift->break_minutes);
    }
}
