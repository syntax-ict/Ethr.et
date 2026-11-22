<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Exceptions\PayrollRunRefused;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Whether the attendance and leave figures a run paid are still the ones
 * today's records give, asked at approval in every reconciliation mode.
 *
 * Reconciliation only looks at decisions, and only when an organisation
 * requires it; the default is off. So a correction approved, a device backlog
 * drained, a late real check-out or unpaid leave approved after a run was
 * calculated used to leave a run to be approved and paid at overtime, lateness
 * and leave figures that were no longer true (docs/audit/BASELINE.md §25al).
 *
 * Two steps, both bounded. First, cheaply, who might differ: people with an
 * attendance record in the window the engine reads for overtime, a
 * correction, or a leave request written since the run began, and everyone
 * when a holiday in that window or a shift used in it was. Then only those
 * people are recomputed, by the engine's own methods
 * (PayrollEngine::attendanceFigures), and compared with their entry's log in
 * minutes and days, never cents. A change that moves no figure (a duplicate
 * punch logged, a photo purged) therefore refuses nothing.
 *
 * "Since" is the run's start (`processed_at`, stamped when the run is
 * reserved) or an entry's own `calculated_at` if that is earlier, never the
 * time an entry was written: a batch of people is computed inside one
 * transaction that reads a snapshot from its first query, so a change
 * committed after that snapshot but before a later person's `calculated_at`
 * would look older than their figure and be missed. A change committed
 * before a person was calculated is a candidate too, and costs only a
 * comparison that finds nothing.
 *
 * The unexplained-absence count is not compared (flagged, never deducted),
 * nor are overtime limits (flagged, never capped).
 */
final class AttendanceInputsCheck
{
    /** The log version `PayrollEngine::inputsChangedSince` also starts from; an older entry cannot be judged. */
    public const FIRST_COMPARABLE_VERSION = '1.9';

    private const OVERTIME_TYPES = ['normal', 'night', 'rest_day', 'holiday', 'holiday_night'];

    /** @throws PayrollRunRefused */
    public function assertUnchanged(PayrollEngine $engine, PayrollRun $run, ?float $deadline): void
    {
        $verdict = $this->changedSince($engine, $run, $deadline);

        if ($verdict->people > 0) {
            throw PayrollRunRefused::attendanceChangedSinceProcessed($verdict->people);
        }

        if ($verdict->timedOut) {
            throw PayrollRunRefused::attendanceCheckTimedOut();
        }
    }

    /** `$deadline` is a `microtime(true)` value, as RequestBudget::deadline() gives. */
    public function changedSince(PayrollEngine $engine, PayrollRun $run, ?float $deadline): AttendanceInputsVerdict
    {
        $tenantId = (int) $run->tenant_id;
        $start = Carbon::parse($run->period_start);
        $end = Carbon::parse($run->period_end);

        $since = $run->processed_at !== null ? Carbon::parse($run->processed_at) : null;
        $logged = [];

        PayrollEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('payroll_run_id', $run->id)
            ->select(['id', 'employee_id', 'created_at', 'calculation_log'])
            ->chunkById(200, function ($entries) use (&$logged, &$since): void {
                foreach ($entries as $entry) {
                    $log = $entry->getAttribute('calculation_log');
                    $log = is_array($log) ? $log : [];

                    if (version_compare((string) ($log['version'] ?? '0'), self::FIRST_COMPARABLE_VERSION, '<')) {
                        continue;
                    }

                    $logged[(int) $entry->employee_id] = $this->loggedFigures($log);
                    $calculated = isset($log['calculated_at']) && is_string($log['calculated_at'])
                        ? Carbon::parse($log['calculated_at'])
                        : $entry->created_at;

                    if ($calculated !== null && ($since === null || $calculated->lt($since))) {
                        $since = $calculated->copy();
                    }
                }
            });

        if ($logged === [] || $since === null) {
            return new AttendanceInputsVerdict(0, false);
        }

        // The Monday-to-Sunday weeks around the period, as gatherOvertimeByType()
        // reads them: whole weeks, so a record in the part of a week outside
        // the period still moves the limit it is flagged against.
        $windowStart = $start->copy()->startOfWeek(Carbon::MONDAY)->format('Y-m-d');
        $windowEnd = $end->copy()->endOfWeek(Carbon::SUNDAY)->format('Y-m-d');
        $sinceText = $since->copy()->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');

        $everyone = $this->sharedInputChanged($tenantId, $windowStart, $windowEnd, $sinceText);
        $candidates = $everyone
            ? array_keys($logged)
            : $this->candidates($tenantId, array_keys($logged), $start, $windowStart, $windowEnd, $sinceText);

        if ($candidates === []) {
            return new AttendanceInputsVerdict(0, false);
        }

        $rules = app(StatutoryRules::class)->for(Tenant::query()->findOrFail($tenantId), $start);
        $people = 0;

        foreach (array_chunk($candidates, 100) as $ids) {
            $employees = Employee::withTrashed()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');

            foreach ($ids as $id) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    return new AttendanceInputsVerdict($people, true);
                }

                $employee = $employees->get($id);

                // Not readable here (no organisation resolved): never judged
                // unchanged for want of looking.
                if ($employee === null) {
                    $people++;

                    continue;
                }

                $then = $logged[$id];
                $now = $engine->attendanceFigures($employee, $start, $end, $rules, $then['lateness_minutes'] !== null);

                if ($this->differs($then, $now)) {
                    $people++;
                }
            }
        }

        return new AttendanceInputsVerdict($people, false);
    }

    /**
     * A holiday in the window, or a shift some record in it uses, written
     * since: it can move any person's overtime type or rest day.
     */
    private function sharedInputChanged(int $tenantId, string $windowStart, string $windowEnd, string $since): bool
    {
        // Every status of a holiday counts: switching one off is a change.
        $holiday = DB::table('holidays')
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', $windowStart)
            ->whereDate('date', '<=', $windowEnd)
            ->where('updated_at', '>=', $since)
            ->exists();

        if ($holiday) {
            return true;
        }

        // A removed shift is a changed one: soft-deleted rows are not filtered.
        return DB::table('shifts')
            ->where('tenant_id', $tenantId)
            ->where('updated_at', '>=', $since)
            ->whereIn('id', DB::table('attendance_records')
                ->select('shift_id')
                ->where('tenant_id', $tenantId)
                ->whereNotNull('shift_id')
                ->whereDate('date', '>=', $windowStart)
                ->whereDate('date', '<=', $windowEnd))
            ->exists();
    }

    /**
     * Who has a record, a correction or a leave request written since. A
     * voided record counts: a merge voids one. Leave reaches two years back, as
     * Art. 85 counts a sick period from its first day.
     *
     * @param  list<int>  $employeeIds
     * @return list<int>
     */
    private function candidates(int $tenantId, array $employeeIds, Carbon $start, string $windowStart, string $windowEnd, string $since): array
    {
        $found = [];
        $leaveFrom = $start->copy()->subYears(2)->format('Y-m-d');
        $leaveTo = Carbon::parse($windowEnd)->format('Y-m-d');

        foreach (array_chunk($employeeIds, 500) as $ids) {
            $records = DB::table('attendance_records')
                ->where('tenant_id', $tenantId)
                ->whereIn('employee_id', $ids)
                ->whereDate('date', '>=', $windowStart)
                ->whereDate('date', '<=', $windowEnd)
                ->where('updated_at', '>=', $since)
                ->distinct()
                ->pluck('employee_id');

            $corrections = DB::table('attendance_corrections')
                ->where('tenant_id', $tenantId)
                ->whereIn('employee_id', $ids)
                ->where('updated_at', '>=', $since)
                ->whereIn('attendance_record_id', DB::table('attendance_records')
                    ->select('id')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('employee_id', $ids)
                    ->whereDate('date', '>=', $windowStart)
                    ->whereDate('date', '<=', $windowEnd))
                ->distinct()
                ->pluck('employee_id');

            // Deleted requests count: removing one moves unpaid days too.
            $leave = DB::table('leave_requests')
                ->where('tenant_id', $tenantId)
                ->whereIn('employee_id', $ids)
                ->whereDate('end_date', '>=', $leaveFrom)
                ->whereDate('start_date', '<=', $leaveTo)
                ->where('updated_at', '>=', $since)
                ->distinct()
                ->pluck('employee_id');

            foreach ([$records, $corrections, $leave] as $set) {
                foreach ($set as $id) {
                    $found[(int) $id] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * The compared figures out of an entry's log. Lateness is null when the
     * entry logged no lateness step: deductions were switched off for it.
     *
     * @param  array<string, mixed>  $log
     * @return array{overtime: array<string, int>, lateness_minutes: int|null, lateness_capped: bool, unpaid_leave_days: float, sick_half_days: float, sick_unpaid_days: float}
     */
    private function loggedFigures(array $log): array
    {
        $steps = [];
        foreach ((array) ($log['steps'] ?? []) as $step) {
            if (is_array($step) && isset($step['step'])) {
                $steps[(string) $step['step']] = $step;
            }
        }

        $overtime = [];
        foreach (self::OVERTIME_TYPES as $type) {
            $overtime[$type] = (int) ($steps['overtime']['by_type'][$type]['minutes'] ?? 0);
        }

        $lateness = $steps['lateness'] ?? null;

        return [
            'overtime' => $overtime,
            'lateness_minutes' => $lateness === null ? null : (int) ($lateness['deducted_minutes'] ?? 0),
            'lateness_capped' => $lateness !== null && ($lateness['capped'] ?? false) === true,
            'unpaid_leave_days' => (float) ($steps['unpaid_leave']['unpaid_leave_days'] ?? 0),
            'sick_half_days' => (float) ($steps['sick_leave']['half_pay_days'] ?? 0),
            'sick_unpaid_days' => (float) ($steps['sick_leave']['unpaid_days'] ?? 0),
        ];
    }

    /**
     * @param  array{overtime: array<string, int>, lateness_minutes: int|null, lateness_capped: bool, unpaid_leave_days: float, sick_half_days: float, sick_unpaid_days: float}  $then
     * @param  array{overtime: array<string, int>, lateness_minutes: int|null, unpaid_leave_days: float, sick_half_days: float, sick_unpaid_days: float}  $now
     */
    private function differs(array $then, array $now): bool
    {
        foreach (self::OVERTIME_TYPES as $type) {
            if (($now['overtime'][$type] ?? 0) !== $then['overtime'][$type]) {
                return true;
            }
        }

        // Leave days are counted in halves and finer: thousandths carry them.
        foreach (['unpaid_leave_days', 'sick_half_days', 'sick_unpaid_days'] as $key) {
            if (abs($now[$key] - $then[$key]) >= 0.0005) {
                return true;
            }
        }

        if ($then['lateness_minutes'] === null || $now['lateness_minutes'] === $then['lateness_minutes']) {
            return false;
        }

        // A deduction held at the cap stays at it while minutes only grow.
        return ! ($then['lateness_capped'] && $now['lateness_minutes'] > $then['lateness_minutes']);
    }
}
