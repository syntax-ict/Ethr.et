<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Support\ApprovalRefusal;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A day an employee has been paid for in an approved payroll run is closed to
 * human edits of their attendance.
 *
 * Approved entries are stored, so editing a paid day never rewrote a payslip.
 * It did something quieter: the next void and reprocess of that period paid
 * from the edited punches, and nothing on the run said its inputs had moved
 * since it was approved (blueprint assessment 2026-10-20, attendance
 * acceptance test). A correction, manual entry, conflict resolution, integrity
 * rejection or import for a closed day is refused; voiding the run reopens it.
 *
 * Machine ingestion (devices, offline sync, kiosk) is not refused: a punch
 * that arrives late is a fact about what happened, and it changes no stored
 * entry.
 */
final class PayrollPeriodLock
{
    public function isClosed(int $employeeId, CarbonInterface|string $date): bool
    {
        $day = Carbon::parse($date)->toDateString();

        return PayrollEntry::query()
            ->where('employee_id', $employeeId)
            ->whereHas('payrollRun', fn ($run) => $run
                ->whereIn('status', PayrollRun::RELEASED_STATUSES)
                ->whereDate('period_start', '<=', $day)
                ->whereDate('period_end', '>=', $day))
            ->exists();
    }

    /**
     * The days each employee is closed on, for many employees at once:
     * the same rule as isClosed() (an entry in a released run whose period
     * covers the day), asked in one query for a whole timesheet.
     *
     * @param  list<int>  $employeeIds  all of one tenant
     * @return array<int, array<string, true>> employee id => Y-m-d => true
     */
    public function closedDays(int $tenantId, array $employeeIds, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $first = Carbon::parse($from)->toDateString();
        $last = Carbon::parse($to)->toDateString();
        $closed = [];

        $entries = PayrollEntry::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $employeeIds)
            ->whereHas('payrollRun', fn ($run) => $run
                ->whereIn('status', PayrollRun::RELEASED_STATUSES)
                ->whereDate('period_start', '<=', $last)
                ->whereDate('period_end', '>=', $first))
            ->with('payrollRun:id,period_start,period_end')
            ->get(['employee_id', 'payroll_run_id']);

        foreach ($entries as $entry) {
            $run = $entry->payrollRun;
            if ($run === null) {
                continue;
            }

            $cursor = Carbon::parse(max($run->period_start->toDateString(), $first));
            $end = Carbon::parse(min($run->period_end->toDateString(), $last));

            while ($cursor->lessThanOrEqualTo($end)) {
                $closed[$entry->employee_id][$cursor->toDateString()] = true;
                $cursor = $cursor->addDay();
            }
        }

        return $closed;
    }

    /**
     * Whether any day from $from to $to is closed for the employee: one
     * question for a span, such as a leave request.
     */
    public function anyClosed(int $employeeId, CarbonInterface|string $from, CarbonInterface|string $to): bool
    {
        return PayrollEntry::query()
            ->where('employee_id', $employeeId)
            ->whereHas('payrollRun', fn ($run) => $run
                ->whereIn('status', PayrollRun::RELEASED_STATUSES)
                ->whereDate('period_start', '<=', Carbon::parse($to)->toDateString())
                ->whereDate('period_end', '>=', Carbon::parse($from)->toDateString()))
            ->exists();
    }

    public function refusal(int $employeeId, CarbonInterface|string $date): ?ApprovalRefusal
    {
        return $this->isClosed($employeeId, $date)
            ? ApprovalRefusal::payrollPeriodClosed(__('attendance.payroll_period_closed'))
            : null;
    }

    /**
     * Thrown rather than returned, so the response shapes Scramble publishes
     * for the callers stay as they are.
     */
    public function assertOpen(int $employeeId, CarbonInterface|string $date): void
    {
        $refusal = $this->refusal($employeeId, $date);

        if ($refusal !== null) {
            throw new HttpResponseException($refusal->toResponse());
        }
    }
}
