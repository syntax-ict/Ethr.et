<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Enums\EmployeeStatus;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use Carbon\Carbon;

final class PayrollEngine
{
    public function __construct(
        private readonly TaxCalculator $taxCalculator,
        private readonly PensionCalculator $pensionCalculator,
        private readonly OvertimeCalculator $overtimeCalculator,
        private readonly LoanService $loanService,
    ) {}

    public function process(int $tenantId, Carbon $periodStart, Carbon $periodEnd, int $processedBy): PayrollRun
    {
        $run = PayrollRun::create([
            'tenant_id' => $tenantId,
            'period_label' => $periodStart->format('F Y'),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => 'processing',
            'processed_by' => $processedBy,
            'processed_at' => now(),
        ]);

        $employees = Employee::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [
                EmployeeStatus::HIRED,
                EmployeeStatus::PROBATION,
                EmployeeStatus::CONFIRMED,
            ])
            ->get();

        $totalGross = 0;
        $totalNet = 0;
        $totalTax = 0;
        $count = 0;

        foreach ($employees as $employee) {
            $entry = $this->processEmployee($employee, $run, $periodStart, $periodEnd);
            if ($entry) {
                $totalGross += $entry->gross_cents;
                $totalNet += $entry->net_cents;
                $totalTax += $entry->income_tax_cents;
                $count++;
            }
        }

        $run->update([
            'status' => 'completed',
            'employee_count' => $count,
            'gross_total_cents' => $totalGross,
            'net_total_cents' => $totalNet,
            'tax_total_cents' => $totalTax,
        ]);

        return $run;
    }

    private function processEmployee(
        Employee $employee,
        PayrollRun $run,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): ?PayrollEntry {
        $basicSalary = $employee->salary_cents;

        if ($basicSalary <= 0) {
            return null;
        }

        $hireDate = $employee->hire_date;
        $prorationFactor = 1.0;
        if ($hireDate && $hireDate->gt($periodStart)) {
            $totalDays = $periodStart->diffInDays($periodEnd) + 1;
            $workedDays = $hireDate->diffInDays($periodEnd) + 1;
            $prorationFactor = $workedDays / $totalDays;
        }

        $proratedBasic = (int) round($basicSalary * $prorationFactor);

        $overtimeMinutes = $this->getOvertimeMinutes($employee, $periodStart, $periodEnd);
        $overtimeAmount = $this->overtimeCalculator->calculate(
            $proratedBasic,
            22,
            8,
            $overtimeMinutes,
        );

        $allowances = [];
        $totalAllowances = 0;

        $grossSalary = $proratedBasic + $overtimeAmount + $totalAllowances;

        $taxableAmount = $grossSalary;
        $incomeTax = $this->taxCalculator->calculate($taxableAmount);

        $pension = $this->pensionCalculator->calculate($proratedBasic);

        $loanDeduction = $this->loanService->calculateMonthlyDeduction($employee);

        $activeLoans = $this->loanService->getActiveLoans($employee);
        foreach ($activeLoans as $loan) {
            $this->loanService->applyDeduction($loan, $loan->monthly_deduction_cents);
        }

        $totalDeductions = $incomeTax + $pension['employee_cents'] + $loanDeduction;
        $netSalary = $grossSalary - $totalDeductions;

        $deductions = [];
        if ($loanDeduction > 0) {
            $deductions[] = ['type' => 'loan', 'amount_cents' => $loanDeduction];
        }

        return PayrollEntry::create([
            'tenant_id' => $employee->tenant_id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'basic_salary_cents' => $proratedBasic,
            'allowances' => $allowances,
            'deductions' => $deductions,
            'gross_cents' => $grossSalary,
            'income_tax_cents' => $incomeTax,
            'employee_pension_cents' => $pension['employee_cents'],
            'employer_pension_cents' => $pension['employer_cents'],
            'other_deductions_cents' => $loanDeduction,
            'net_cents' => $netSalary,
        ]);
    }

    private function getOvertimeMinutes(Employee $employee, Carbon $periodStart, Carbon $periodEnd): int
    {
        return (int) AttendanceRecord::query()
            ->withoutGlobalScope('tenant')
            ->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $periodStart->format('Y-m-d'))
            ->whereDate('date', '<=', $periodEnd->format('Y-m-d'))
            ->whereNotNull('check_in')
            ->whereNotNull('check_out')
            ->get()
            ->sum(fn ($r) => $r->overtimeMinutes());
    }
}
