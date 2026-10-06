<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\CostSharingStatus;
use App\Models\Employee;
use App\Models\EmployeeCostSharing;

final class CostSharingService
{
    /**
     * The one obligation payroll will deduct against this month, or null.
     *
     * Deliberately singular where `LoanService::getActiveLoans()` is plural: an
     * employee can hold several concurrent loans, but cost sharing is one debt
     * to one counterparty for one education. Two active rows for the same
     * employee means a data-entry error, and summing them would quietly deduct
     * twice; taking the earliest keeps the behaviour defined and lets the
     * duplicate be found and corrected rather than silently over-withheld.
     */
    public function getActiveObligation(Employee $employee): ?EmployeeCostSharing
    {
        return EmployeeCostSharing::query()
            ->where('employee_id', $employee->id)
            ->where('status', CostSharingStatus::ACTIVE)
            ->orderBy('started_on')
            ->orderBy('id')
            ->first();
    }

    /**
     * The deduction for one pay period, in integer cents.
     *
     * Two things bound it. It is a percentage of the *prorated* gross the engine
     * actually computed, so a half-month or an unpaid-leave month withholds
     * proportionally rather than a flat figure. And it can never exceed the
     * outstanding balance — without that clamp the final month over-withholds
     * and the balance goes negative, which is how an amortising deduction turns
     * into a permanent one.
     */
    public function calculateDeduction(?EmployeeCostSharing $obligation, int $grossCents): int
    {
        if ($obligation === null || ! $obligation->status->isDeductible()) {
            return 0;
        }

        if ($grossCents <= 0 || $obligation->outstanding_cents <= 0) {
            return 0;
        }

        // intdiv on a scaled integer rather than float multiplication: the rate
        // carries 2 decimal places, so scale by 10_000 (100 for percent, 100 for
        // its decimals) and divide once. Keeps the whole path in integers, per
        // CLAUDE.md convention #3.
        $rateScaled = (int) round((float) $obligation->deduction_rate_percent * 100);
        $deduction = intdiv($grossCents * $rateScaled, 10_000);

        return min($deduction, $obligation->outstanding_cents);
    }

    /**
     * Reduce the balance and close the obligation when it reaches zero.
     *
     * Closing here rather than in a scheduled sweep means the employee's last
     * payslip and the row's completion are the same transaction — there is no
     * window in which a fully-repaid obligation is still marked deductible.
     */
    public function applyDeduction(EmployeeCostSharing $obligation, int $deductionCents): void
    {
        if ($deductionCents <= 0) {
            return;
        }

        $newOutstanding = max(0, $obligation->outstanding_cents - $deductionCents);

        $obligation->update([
            'outstanding_cents' => $newOutstanding,
            'status' => $newOutstanding === 0
                ? CostSharingStatus::COMPLETED
                : $obligation->status,
            'completed_at' => $newOutstanding === 0 ? now() : $obligation->completed_at,
        ]);
    }
}
