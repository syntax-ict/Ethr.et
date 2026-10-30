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

        $leave = $this->leaveDays($employee->tenant_id, [$employee->id], $from, $to)[$employee->id] ?? [];

        $window = $this->window($employee);
        $days = [];
        foreach ($working as $day => $works) {
            $days[$day] = $this->classify(self::within($window, $day), $works, isset($holidays[$day]), isset($leave[$day]));
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
        $leave = $this->leaveDays($first->tenant_id, $employees->pluck('id')->all(), $date, $date);

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
                self::within($this->window($employee), $day),
                $working[$employee->id] ?? false,
                isset($holidaysByBranch[$branch ?? 0][$day]),
                isset($leave[$employee->id][$day]),
            );
        }

        return $kinds;
    }

    /**
     * onDate() for every date in [$from, $to], with each lookup made once for
     * the whole range: the assignments, the holidays of each branch present
     * and the approved leave. Analytics asks this for up to a year.
     *
     * @param  Collection<int, Employee>  $employees  all of one tenant
     * @return array<string, array<int, ExpectedDay>> Y-m-d => employee id => kind
     */
    public function between(Collection $employees, CarbonInterface $from, CarbonInterface $to): array
    {
        $first = $employees->first();

        if ($first === null) {
            return [];
        }

        $working = $this->shifts->workingBetween($employees, $from, $to, $this->workingWeek($first));
        $leave = $this->leaveDays($first->tenant_id, $employees->pluck('id')->all(), $from, $to);

        $holidaysByBranch = [];
        $kinds = [];

        // Attributes are read once per employee, outside the day loop: each
        // read goes through Eloquent's accessors, and a year of 150 people
        // made three of them per day cost 1.7 seconds.
        foreach ($employees as $employee) {
            $id = $employee->id;
            $branch = $employee->branch_id ?? 0;
            $holidaysByBranch[$branch] ??= $this->holidays->getHolidayDates(
                $employee->tenant_id,
                Carbon::parse($from->format('Y-m-d')),
                Carbon::parse($to->format('Y-m-d')),
                $employee->branch_id,
            );
            $holidays = $holidaysByBranch[$branch];
            $onLeave = $leave[$id] ?? [];
            $window = $this->window($employee);

            foreach ($working[$id] ?? [] as $day => $works) {
                $kinds[$day][$id] = $this->classify(
                    self::within($window, $day),
                    $works,
                    isset($holidays[$day]),
                    isset($onLeave[$day]),
                );
            }
        }

        return $kinds;
    }

    private function classify(bool $employed, bool $works, bool $holiday, bool $onLeave): ExpectedDay
    {
        if (! $employed) {
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
     * The days an employee is on the books: from the hire date to the
     * termination date. Someone who has left or been suspended with no
     * termination date on file cannot be dated, so they are expected on no
     * day rather than absent on every one.
     *
     * Read once per employee: a year of an organisation is tens of thousands
     * of days, and each read of a cast attribute builds a new Carbon.
     *
     * @return array{hired: ?string, ends: ?string, undated: bool}
     */
    private function window(Employee $employee): array
    {
        return [
            'hired' => $employee->hire_date?->format('Y-m-d'),
            'ends' => $employee->termination_date?->format('Y-m-d'),
            'undated' => in_array($employee->status, [...EmployeeStatus::leaving(), EmployeeStatus::SUSPENDED], true),
        ];
    }

    /** @param  array{hired: ?string, ends: ?string, undated: bool}  $window */
    private static function within(array $window, string $day): bool
    {
        if ($window['hired'] !== null && $day < $window['hired']) {
            return false;
        }

        if ($window['ends'] !== null) {
            return $day <= $window['ends'];
        }

        return ! $window['undated'];
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
     * Stated `tenant_id` as well as the scope: payroll asks from a queued job,
     * where the tenant is whatever the job set.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, true>> employee id => Y-m-d => true
     */
    private function leaveDays(int $tenantId, array $employeeIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');
        $days = [];

        $requests = LeaveRequest::query()
            ->where('tenant_id', $tenantId)
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
