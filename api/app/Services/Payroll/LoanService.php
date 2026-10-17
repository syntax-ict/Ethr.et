<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeLoan;
use Illuminate\Database\Eloquent\Collection;

final class LoanService
{
    /** @return Collection<int, EmployeeLoan> */
    public function getActiveLoans(Employee $employee): Collection
    {
        return EmployeeLoan::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
    }

    /**
     * What one loan can be charged this period: its instalment, but never more
     * than it still owes.
     *
     * Until 2026-10-17 the payslip deducted the full instalment and only the
     * balance floored at zero, so the final month withheld money the employee
     * did not owe and nothing recorded it. Cost sharing has always clamped the
     * same way (CostSharingService::calculateDeduction).
     */
    public function deductionFor(EmployeeLoan $loan): int
    {
        return max(0, min($loan->monthly_deduction_cents, $loan->remaining_cents));
    }

    public function calculateMonthlyDeduction(Employee $employee): int
    {
        return (int) $this->getActiveLoans($employee)
            ->sum(fn (EmployeeLoan $loan): int => $this->deductionFor($loan));
    }

    /**
     * Draw the balance down by what an approved run deducted, and close the
     * loan when it reaches zero. PayrollBalanceService calls this on approval,
     * never while a run is being calculated.
     */
    public function applyDeduction(EmployeeLoan $loan, int $deductionCents): void
    {
        $newRemaining = max(0, $loan->remaining_cents - $deductionCents);

        $loan->update([
            'remaining_cents' => $newRemaining,
            'status' => $newRemaining <= 0 ? 'completed' : 'active',
        ]);
    }

    /**
     * Give back what a voided, approved run deducted. A loan that run closed
     * is reopened; one cancelled since keeps its status and only gets its
     * balance back.
     */
    public function restoreDeduction(EmployeeLoan $loan, int $deductionCents): void
    {
        if ($deductionCents <= 0) {
            return;
        }

        $newRemaining = $loan->remaining_cents + $deductionCents;

        $loan->update([
            'remaining_cents' => $newRemaining,
            'status' => $loan->status === 'completed' ? 'active' : $loan->status,
        ]);
    }
}
