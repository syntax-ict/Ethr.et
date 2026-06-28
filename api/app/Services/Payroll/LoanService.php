<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeLoan;

final class LoanService
{
    public function getActiveLoans(Employee $employee): \Illuminate\Database\Eloquent\Collection
    {
        return EmployeeLoan::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->get();
    }

    public function calculateMonthlyDeduction(Employee $employee): int
    {
        return (int) $this->getActiveLoans($employee)->sum('monthly_deduction_cents');
    }

    public function applyDeduction(EmployeeLoan $loan, int $deductionCents): void
    {
        $newRemaining = max(0, $loan->remaining_cents - $deductionCents);

        $loan->update([
            'remaining_cents' => $newRemaining,
            'status' => $newRemaining <= 0 ? 'completed' : 'active',
        ]);
    }
}
