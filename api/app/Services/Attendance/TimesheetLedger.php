<?php

declare(strict_types=1);

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Enums\ExpectedDay;
use App\Enums\TimesheetCode;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Holiday\HolidayService;
use App\Services\Leave\SickLeaveTiers;
use App\Services\Payroll\AttendanceReconciliation;
use App\Services\Payroll\PayrollEngine;
use App\Services\Payroll\PayrollPeriodLock;
use App\Services\Payroll\PeriodOvertime;
use App\Services\Payroll\StatutoryRuleSet;
use App\Support\TenantTime;
use App\Support\WorkingWeek;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * One employee-by-day reading of a period, for the monthly timesheet.
 *
 * It decides nothing of its own. Every figure is one the rest of ETHR already
 * produces, asked in one pass for everyone at once:
 *
 * - what the day is (workday, rest day, holiday, leave): `ExpectedDays`, with
 *   the leave's type from `ExpectedDays::leaveBetween()`;
 * - the shift and its scheduled minutes: `ShiftMatcher`, `Shift`;
 * - late and early minutes: `AttendanceEngine`, the code that rated the punch;
 * - overtime by rate: `PeriodOvertime`, the arithmetic payroll pays from;
 * - an absence: a workday with no attended record, up to yesterday, in an
 *   organisation that records attendance at all (`PayrollEngine`'s own guard);
 * - what is still to fix: `AttendanceReconciliation`;
 * - a day already paid: `PayrollPeriodLock`.
 *
 * A timesheet that read these any other way would show a different number from
 * the one payroll pays. `TimesheetLedgerTest` pins the two to each other.
 *
 * Callers hand in the employees they may show (reach is theirs to apply) and
 * all of one tenant. A period is at most a month of days; the cost is the
 * employees times the days, in memory, with a fixed number of queries.
 */
final class TimesheetLedger
{
    /** Overtime types, in the order payroll lists them. */
    public const OVERTIME_TYPES = ['normal', 'night', 'rest_day', 'holiday', 'holiday_night'];

    public function __construct(
        private readonly ExpectedDays $expectedDays,
        private readonly ShiftMatcher $shifts,
        private readonly HolidayService $holidays,
        private readonly AttendanceEngine $attendance,
        private readonly PeriodOvertime $overtime,
        private readonly PayrollEngine $payroll,
        private readonly PayrollPeriodLock $lock,
        private readonly AttendanceReconciliation $reconciliation,
        private readonly SickLeaveTiers $sickLeave,
    ) {}

    /**
     * @param  Collection<int, Employee>  $employees  all of one tenant, already limited to what the caller may see
     * @param  bool  $withPay  also read the pay-moving figures (unpaid leave, Art. 85 sick days) one employee at a time, as payroll does
     * @return array{
     *     days: list<string>,
     *     employees: array<int, array{
     *         employee: Employee,
     *         cells: array<string, array{
     *             code: TimesheetCode|null,
     *             kind: ExpectedDay,
     *             worked: bool,
     *             worked_minutes: int,
     *             scheduled_minutes: int,
     *             late_minutes: int,
     *             early_minutes: int,
     *             overtime: array<string, int>,
     *             records: list<string>,
     *             locked: bool,
     *             exception: array{type: string, state: string}|null,
     *         }>,
     *         totals: array<string, mixed>,
     *         check_ok: bool,
     *     }>
     * }
     */
    public function build(Collection $employees, Carbon $from, Carbon $to, StatutoryRuleSet $rules, bool $withPay = true): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $days = [];
        for ($cursor = $from->copy(); $cursor->lessThanOrEqualTo($to); $cursor->addDay()) {
            $days[] = $cursor->format('Y-m-d');
        }

        $first = $employees->first();
        if ($first === null || $days === []) {
            return ['days' => $days, 'employees' => []];
        }

        $tenantId = $first->tenant_id;
        $tenant = app(CurrentTenant::class)->tenantFor($tenantId);
        $zone = TenantTime::zone($tenant);
        $today = TenantTime::today($tenant)->format('Y-m-d');
        $yesterday = TenantTime::today($tenant)->subDay()->format('Y-m-d');
        $fromDay = $from->format('Y-m-d');
        $toDay = $to->format('Y-m-d');
        $ids = $employees->pluck('id')->all();

        // Whole Monday-to-Sunday weeks, as payroll reads overtime, so a limit
        // week that starts before the period is counted whole.
        $windowStart = $from->copy()->startOfWeek(Carbon::MONDAY);
        $windowEnd = $to->copy()->endOfWeek(Carbon::SUNDAY);

        $kinds = $this->expectedDays->between($employees, $from, $to);
        $leave = $this->expectedDays->leaveBetween($employees, $from, $to);
        $shiftsByEmployee = $this->shifts->shiftsBetween($employees, $from, $to);
        $rotationRestDays = $this->shifts->rotationRestDaysBetween($employees, $windowStart, $windowEnd);
        $workingWeek = WorkingWeek::of($tenant);
        $closed = $this->lock->closedDays($tenantId, $ids, $from, $to);

        $recordsByEmployee = AttendanceRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $ids)
            ->whereDate('date', '>=', $windowStart->format('Y-m-d'))
            ->whereDate('date', '<=', $windowEnd->format('Y-m-d'))
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->with('shift')
            ->get()
            ->groupBy('employee_id');

        $exceptions = $this->exceptionsByEmployee($tenant, $from, $to);

        // An organisation that recorded no attendance in the period is not
        // using attendance, and every workday would read as absent.
        $absenceCountable = $fromDay <= $yesterday
            && $this->payroll->attendanceInUse($tenantId, $fromDay, min($toDay, $yesterday));

        $holidaysByBranch = [];
        $result = [];

        foreach ($employees as $employee) {
            $id = $employee->id;
            $holidayDates = $holidaysByBranch[$employee->branch_id ?? 0] ??= $this->holidays->getHolidayDates(
                $tenantId, $windowStart, $windowEnd, $employee->branch_id,
            );

            /** @var Collection<int, AttendanceRecord> $records */
            $records = $recordsByEmployee->get($id, collect());
            $complete = $records->filter(fn (AttendanceRecord $r): bool => $r->check_in !== null && $r->check_out !== null)->values();
            $tally = $this->overtime->tally(
                $complete,
                $holidayDates,
                $rotationRestDays[$id] ?? [],
                $workingWeek,
                $from,
                $to,
                $rules,
            );

            $recordsByDay = $records
                ->filter(fn (AttendanceRecord $r): bool => $r->date !== null)
                ->groupBy(fn (AttendanceRecord $r): string => $r->date->format('Y-m-d'));

            $cells = [];
            foreach ($days as $day) {
                $cells[$day] = $this->cell(
                    $day,
                    $kinds[$day][$id] ?? ExpectedDay::NOT_EMPLOYED,
                    $leave[$id][$day] ?? null,
                    $recordsByDay->get($day, collect()),
                    $shiftsByEmployee[$id][$day] ?? null,
                    $tally['by_day_type'][$day] ?? [],
                    isset($closed[$id][$day]),
                    $exceptions[$employee->public_id][$day] ?? null,
                    $zone,
                    $day <= $yesterday && $absenceCountable,
                );
            }

            $totals = $this->totals($cells, $tally);

            if ($withPay) {
                $sick = $this->sickLeave->inPeriod($employee, $from, $to, $rules->int('payroll.working_days_per_month'));
                $totals['unpaid_leave_days'] = $this->payroll->gatherUnpaidLeaveDays($employee, $from, $to);
                $totals['sick_half_days'] = $sick['half'];
                $totals['sick_unpaid_days'] = $sick['unpaid'];
            }

            $result[$id] = [
                'employee' => $employee,
                'cells' => $cells,
                'totals' => $totals,
                'check_ok' => $this->consistent($totals, count($days)),
            ];
        }

        return ['days' => $days, 'employees' => $result];
    }

    /**
     * @param  array<string, mixed>|null  $leave
     * @param  Collection<int, AttendanceRecord>  $records  this employee's non-voided records dated $day
     * @param  array<string, int>  $overtime  minutes by overtime type on $day
     * @param  array{type: string, state: string}|null  $exception
     * @return array{code: TimesheetCode|null, kind: ExpectedDay, worked: bool, worked_minutes: int, scheduled_minutes: int, late_minutes: int, early_minutes: int, overtime: array<string, int>, records: list<string>, locked: bool, exception: array{type: string, state: string}|null}
     */
    private function cell(
        string $day,
        ExpectedDay $kind,
        ?array $leave,
        Collection $records,
        ?Shift $shift,
        array $overtime,
        bool $locked,
        ?array $exception,
        string $zone,
        bool $absenceCountable,
    ): array {
        $attended = $records->filter(fn (AttendanceRecord $r): bool => $r->check_in !== null || $r->check_out !== null);
        $worked = $attended->isNotEmpty();

        $workedMinutes = (int) $attended->sum(fn (AttendanceRecord $r): int => $r->workedMinutes());
        $late = (int) $attended->sum(fn (AttendanceRecord $r): int => $this->attendance->lateMinutes($r, $zone));
        $early = (int) $attended->sum(fn (AttendanceRecord $r): int => $this->attendance->earlyMinutes($r, $zone));

        // Late and early minutes belong to a workday only: a punch on a rest
        // day or a holiday is paid as overtime, never rated late.
        if ($kind !== ExpectedDay::WORKDAY) {
            $late = 0;
            $early = 0;
        }

        $code = match ($kind) {
            ExpectedDay::NOT_EMPLOYED => TimesheetCode::NOT_EMPLOYED,
            ExpectedDay::HOLIDAY => TimesheetCode::HOLIDAY,
            ExpectedDay::REST_DAY => TimesheetCode::REST_DAY,
            ExpectedDay::ON_LEAVE => $leave === null
                ? TimesheetCode::OTHER_LEAVE
                : TimesheetCode::forLeave($leave['kind'], $leave['follows_art85'], $leave['type_code'], $leave['half_day']),
            ExpectedDay::WORKDAY => $this->workdayCode($attended, $exception, $late, $early, $absenceCountable),
        };

        return [
            'code' => $code,
            'kind' => $kind,
            'worked' => $worked,
            'worked_minutes' => $workedMinutes,
            'scheduled_minutes' => $kind === ExpectedDay::WORKDAY && $shift !== null ? $shift->scheduledMinutesOn($day, $zone) : 0,
            'late_minutes' => $late,
            'early_minutes' => $early,
            'overtime' => $overtime,
            'records' => $attended->map(fn (AttendanceRecord $r): string => $r->public_id)->values()->all(),
            'locked' => $locked,
            'exception' => $exception,
        ];
    }

    /**
     * @param  Collection<int, AttendanceRecord>  $attended
     * @param  array{type: string, state: string}|null  $exception
     */
    private function workdayCode(Collection $attended, ?array $exception, int $late, int $early, bool $absenceCountable): ?TimesheetCode
    {
        if ($attended->isEmpty()) {
            return $absenceCountable ? TimesheetCode::ABSENT : null;
        }

        // A punch left open, or closed by auto check-out, that nobody has
        // settled: the day is attended but its hours cannot be trusted.
        if ($exception !== null
            && in_array($exception['type'], [AttendanceReconciliation::TYPE_MISSED_PUNCH, AttendanceReconciliation::TYPE_AUTO_CHECKOUT], true)
            && $exception['state'] !== AttendanceReconciliation::STATE_RESOLVED) {
            return TimesheetCode::MISSING_PUNCH;
        }

        if ($attended->contains(fn (AttendanceRecord $r): bool => $r->status === AttendanceStatus::LATE)) {
            return TimesheetCode::LATE;
        }

        if ($early > 0 || $attended->contains(fn (AttendanceRecord $r): bool => $r->status === AttendanceStatus::EARLY_LEAVE)) {
            return TimesheetCode::EARLY_LEAVE;
        }

        return TimesheetCode::PRESENT;
    }

    /**
     * @param  array<string, array<string, mixed>>  $cells
     * @param  array{buckets: array<string, int>, by_day: array<string, int>, skipped: list<array<string, string>>}  $tally
     * @return array<string, mixed>
     */
    private function totals(array $cells, array $tally): array
    {
        $totals = [
            'scheduled_days' => 0,
            'present_days' => 0,
            'late_days' => 0,
            'early_days' => 0,
            'missing_days' => 0,
            'absent_days' => 0,
            'pending_days' => 0,
            'leave_days' => 0,
            'leave_by_code' => [],
            'holiday_days' => 0,
            'rest_days' => 0,
            'not_employed_days' => 0,
            'locked_days' => 0,
            'worked_minutes' => 0,
            'scheduled_minutes' => 0,
            'late_minutes' => 0,
            'early_minutes' => 0,
            'overtime' => $tally['buckets'],
            'overtime_by_day' => $tally['by_day'],
            'overtime_skipped' => $tally['skipped'],
        ];

        foreach ($cells as $cell) {
            /** @var ExpectedDay $kind */
            $kind = $cell['kind'];
            /** @var TimesheetCode|null $code */
            $code = $cell['code'];

            $totals['worked_minutes'] += $cell['worked_minutes'];
            $totals['scheduled_minutes'] += $cell['scheduled_minutes'];
            $totals['late_minutes'] += $cell['late_minutes'];
            $totals['early_minutes'] += $cell['early_minutes'];
            $totals['locked_days'] += $cell['locked'] ? 1 : 0;

            switch ($kind) {
                case ExpectedDay::WORKDAY:
                    $totals['scheduled_days']++;
                    if ($code === null) {
                        $totals['pending_days']++;
                    } elseif ($code === TimesheetCode::ABSENT) {
                        $totals['absent_days']++;
                    } else {
                        $totals['present_days']++;
                        $totals['missing_days'] += $code === TimesheetCode::MISSING_PUNCH ? 1 : 0;
                        $totals['late_days'] += $code === TimesheetCode::LATE || $cell['late_minutes'] > 0 ? 1 : 0;
                        $totals['early_days'] += $cell['early_minutes'] > 0 || $code === TimesheetCode::EARLY_LEAVE ? 1 : 0;
                    }
                    break;
                case ExpectedDay::ON_LEAVE:
                    $totals['leave_days']++;
                    $key = ($code ?? TimesheetCode::OTHER_LEAVE)->value;
                    $totals['leave_by_code'][$key] = ($totals['leave_by_code'][$key] ?? 0) + 1;
                    break;
                case ExpectedDay::HOLIDAY:
                    $totals['holiday_days']++;
                    break;
                case ExpectedDay::REST_DAY:
                    $totals['rest_days']++;
                    break;
                case ExpectedDay::NOT_EMPLOYED:
                    $totals['not_employed_days']++;
                    break;
            }
        }

        return $totals;
    }

    /**
     * The row's two identities, written against ETHR's own classification: a
     * leave day is never also a workday, and a workday is attended, absent or
     * still to come. A row that fails is a bug in this class or in a rule it
     * reads, never a reason to hide the row.
     *
     * @param  array<string, mixed>  $totals
     */
    private function consistent(array $totals, int $dayCount): bool
    {
        $workdaysAccountedFor = $totals['scheduled_days'] === $totals['present_days'] + $totals['absent_days'] + $totals['pending_days'];

        $everyDayAccountedFor = $dayCount === $totals['scheduled_days'] + $totals['leave_days'] + $totals['holiday_days']
            + $totals['rest_days'] + $totals['not_employed_days'];

        return $workdaysAccountedFor && $everyDayAccountedFor;
    }

    /**
     * What reconciliation still has open or pending on each day, by employee
     * public id. Its own states, read as they are: `resolved` is kept so the
     * grid can show a settled exception differently from none.
     *
     * @return array<string, array<string, array{type: string, state: string}>>
     */
    private function exceptionsByEmployee(Tenant $tenant, Carbon $from, Carbon $to): array
    {
        $byEmployee = [];

        foreach ($this->reconciliation->forPeriod($tenant, $from, $to)['employees'] as $row) {
            foreach ($row['exceptions'] as $exception) {
                // One per day: a missing punch outranks the rest, then the first listed.
                $current = $byEmployee[$row['employee_public_id']][$exception['date']] ?? null;
                if ($current === null || $this->rank($exception['type']) < $this->rank($current['type'])) {
                    $byEmployee[$row['employee_public_id']][$exception['date']] = ['type' => $exception['type'], 'state' => $exception['state']];
                }
            }
        }

        return $byEmployee;
    }

    private function rank(string $type): int
    {
        return match ($type) {
            AttendanceReconciliation::TYPE_MISSED_PUNCH => 0,
            AttendanceReconciliation::TYPE_AUTO_CHECKOUT => 1,
            AttendanceReconciliation::TYPE_ABSENCE => 2,
            default => 3,
        };
    }
}
