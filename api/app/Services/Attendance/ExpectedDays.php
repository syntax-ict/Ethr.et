<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\EmployeeStatus;
use App\Enums\ExpectedDay;
use App\Enums\LeaveStatus;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Services\Holiday\HolidayService;
use App\Support\WorkingWeek;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Whether an employee was expected at work on a day, and if not, why.
 *
 * One answer for every view that counts absence. Until 2026-10-24 there were
 * three, each wrong in its own way (blueprint AT5, S7, RP6):
 *
 * - `GET /attendance/today` counted every employee without a check-in as
 *   absent, including people who had left or been suspended, people on
 *   approved leave (it read `on_leave` from attendance rows nothing writes),
 *   and everyone on a public holiday or a rest day.
 * - The team summary and the employee timeline skipped Saturday and Sunday
 *   whatever the organisation's working week or the person's shift said, and
 *   counted holidays and approved leave as absences.
 *
 * The order decides a day that is two things at once: not yet employed or
 * already gone, then a holiday, then a rest day, then leave. A holiday or rest
 * day inside a leave request is not a leave day, as `LeaveDayCalculator`
 * already counts it.
 *
 * Nothing here writes attendance. An absence is still the absence of a
 * record on a WORKDAY; this only says which days are WORKDAYs.
 */
final class ExpectedDays
{
    public function __construct(
        private readonly ShiftMatcher $shifts,
        private readonly HolidayService $holidays,
    ) {}

    /**
     * @return array<string, ExpectedDay> keyed by Y-m-d, every day in the range
     */
    public function forEmployee(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        $working = $this->shifts->workingDays($employee, $from, $to, $this->workingWeek($employee));

        $holidays = $this->holidays->getHolidayDates(
            $employee->tenant_id,
            Carbon::parse($from->format('Y-m-d')),
            Carbon::parse($to->format('Y-m-d')),
            $employee->branch_id,
        );

        $leave = $this->leaveDays([$employee->id], $from, $to)[$employee->id] ?? [];

        $days = [];
        foreach ($working as $day => $works) {
            $days[$day] = $this->classify($employee, $day, $works, isset($holidays[$day]), isset($leave[$day]));
        }

        return $days;
    }

    /**
     * @param  Collection<int, Employee>  $employees  all of one tenant
     * @return array<int, ExpectedDay> keyed by employee id
     */
    public function onDate(Collection $employees, CarbonInterface $date): array
    {
        $first = $employees->first();

        if ($first === null) {
            return [];
        }

        $day = $date->format('Y-m-d');
        $working = $this->shifts->workingOn($employees, $date, $this->workingWeek($first));
        $leave = $this->leaveDays($employees->pluck('id')->all(), $date, $date);

        // Holidays can be branch-wide, so one lookup per branch present.
        $holidaysByBranch = [];
        $kinds = [];

        foreach ($employees as $employee) {
            $branch = $employee->branch_id;
            $holidaysByBranch[$branch ?? 0] ??= $this->holidays->getHolidayDates(
                $employee->tenant_id,
                Carbon::parse($day),
                Carbon::parse($day),
                $branch,
            );

            $kinds[$employee->id] = $this->classify(
                $employee,
                $day,
                $working[$employee->id] ?? false,
                isset($holidaysByBranch[$branch ?? 0][$day]),
                isset($leave[$employee->id][$day]),
            );
        }

        return $kinds;
    }

    private function classify(Employee $employee, string $day, bool $works, bool $holiday, bool $onLeave): ExpectedDay
    {
        if (! $this->employedOn($employee, $day)) {
            return ExpectedDay::NOT_EMPLOYED;
        }

        if ($holiday) {
            return ExpectedDay::HOLIDAY;
        }

        if (! $works) {
            return ExpectedDay::REST_DAY;
        }

        return $onLeave ? ExpectedDay::ON_LEAVE : ExpectedDay::WORKDAY;
    }

    /**
     * Inside the hire and termination dates. Someone who has left or been
     * suspended with no termination date on file cannot be dated, so they are
     * expected on no day rather than absent on every one.
     */
    private function employedOn(Employee $employee, string $day): bool
    {
        if ($employee->hire_date !== null && $day < $employee->hire_date->format('Y-m-d')) {
            return false;
        }

        if ($employee->termination_date !== null) {
            return $day <= $employee->termination_date->format('Y-m-d');
        }

        return ! in_array($employee->status, [...EmployeeStatus::leaving(), EmployeeStatus::SUSPENDED], true);
    }

    /** @return list<int> */
    private function workingWeek(Employee $employee): array
    {
        // `Tenant` is global (no tenant scope), so this needs no bypass.
        return WorkingWeek::of(Tenant::query()->find($employee->tenant_id));
    }

    /**
     * Approved leave, as the days it covers inside [$from, $to].
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, true>> employee id => Y-m-d => true
     */
    private function leaveDays(array $employeeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');
        $days = [];

        $requests = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', LeaveStatus::APPROVED)
            ->whereDate('start_date', '<=', $last)
            ->whereDate('end_date', '>=', $first)
            ->get(['employee_id', 'start_date', 'end_date']);

        foreach ($requests as $request) {
            if ($request->start_date === null || $request->end_date === null) {
                continue;
            }

            $cursor = Carbon::parse(max($request->start_date->format('Y-m-d'), $first));
            $end = Carbon::parse(min($request->end_date->format('Y-m-d'), $last));

            while ($cursor->lessThanOrEqualTo($end)) {
                $days[$request->employee_id][$cursor->format('Y-m-d')] = true;
                $cursor = $cursor->addDay();
            }
        }

        return $days;
    }
}
