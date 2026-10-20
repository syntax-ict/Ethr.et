<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollEntry;
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
                ->where('status', 'approved')
                ->whereDate('period_start', '<=', $day)
                ->whereDate('period_end', '>=', $day))
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
