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

    public function process(
        int $tenantId,
        Carbon $periodStart,
        Carbon $periodEnd,
        int $processedBy,
        ?string $idempotencyKey = null,
    ): PayrollProcessResult {
        if ($idempotencyKey) {
            $existing = PayrollRun::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return new PayrollProcessResult($existing, wasDuplicate: true);
            }
        }

        $run = PayrollRun::create([
            'tenant_id' => $tenantId,
            'period_label' => $periodStart->format('F Y'),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'idempotency_key' => $idempotencyKey,
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

        return new PayrollProcessResult($run);
    }

    public function void(PayrollRun $run, int $voidedBy, string $reason): PayrollRun
    {
        $run->update([
            'status' => 'voided',
            'voided_at' => now(),
            'voided_by' => $voidedBy,
            'void_reason' => $reason,
        ]);

        return $run->fresh();
    }

    public function reprocess(PayrollRun $voidedRun, int $processedBy, ?string $idempotencyKey = null): PayrollProcessResult
    {
        $result = $this->process(
            $voidedRun->tenant_id,
            Carbon::parse($voidedRun->period_start),
            Carbon::parse($voidedRun->period_end),
            $processedBy,
            $idempotencyKey,
        );

        if (! $result->wasDuplicate) {
            $result->run->update(['reprocessed_from_id' => $voidedRun->id]);
        }

        return $result;
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

        $log = [
            'version' => '1.0',
            'calculated_at' => now()->toIso8601String(),
            'inputs' => [
                'employee_id' => $employee->public_id,
                'basic_salary_cents' => $basicSalary,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'hire_date' => Carbon::parse($employee->hire_date)->toDateString(),
                'status' => $employee->status,
            ],
            'steps' => [],
        ];

        $hireDate = $employee->hire_date;
        $prorationFactor = 1.0;
        if ($hireDate && $hireDate->gt($periodStart)) {
            $totalDays = $periodStart->diffInDays($periodEnd) + 1;
            $workedDays = $hireDate->diffInDays($periodEnd) + 1;
            $prorationFactor = $workedDays / $totalDays;
        }

        $proratedBasic = (int) round($basicSalary * $prorationFactor);

        $log['steps'][] = [
            'step' => 'proration',
            'proration_factor' => $prorationFactor,
            'original_basic_cents' => $basicSalary,
            'prorated_basic_cents' => $proratedBasic,
        ];

        $overtimeMinutes = $this->getOvertimeMinutes($employee, $periodStart, $periodEnd);
        $overtimeAmount = $this->overtimeCalculator->calculate(
            $proratedBasic,
            22,
            8,
            $overtimeMinutes,
        );

        $log['steps'][] = [
            'step' => 'overtime',
            'overtime_minutes' => $overtimeMinutes,
            'overtime_amount_cents' => $overtimeAmount,
        ];

        $allowances = [];
        $totalAllowances = 0;

        $grossSalary = $proratedBasic + $overtimeAmount + $totalAllowances;

        $log['steps'][] = [
            'step' => 'gross',
            'prorated_basic_cents' => $proratedBasic,
            'overtime_cents' => $overtimeAmount,
            'allowances_cents' => $totalAllowances,
            'gross_cents' => $grossSalary,
        ];

        $taxableAmount = $grossSalary;
        $incomeTax = $this->taxCalculator->calculate($taxableAmount);

        $log['steps'][] = [
            'step' => 'income_tax',
            'taxable_amount_cents' => $taxableAmount,
            'income_tax_cents' => $incomeTax,
        ];

        $pension = $this->pensionCalculator->calculate($proratedBasic);

        $log['steps'][] = [
            'step' => 'pension',
            'pensionable_basic_cents' => $proratedBasic,
            'employee_pension_cents' => $pension['employee_cents'],
            'employer_pension_cents' => $pension['employer_cents'],
        ];

        $loanDeduction = $this->loanService->calculateMonthlyDeduction($employee);

        $activeLoans = $this->loanService->getActiveLoans($employee);
        foreach ($activeLoans as $loan) {
            $this->loanService->applyDeduction($loan, $loan->monthly_deduction_cents);
        }

        $log['steps'][] = [
            'step' => 'loan_deductions',
            'loan_deduction_cents' => $loanDeduction,
        ];

        $totalDeductions = $incomeTax + $pension['employee_cents'] + $loanDeduction;
        $netSalary = $grossSalary - $totalDeductions;

        $log['steps'][] = [
            'step' => 'net_calculation',
            'gross_cents' => $grossSalary,
            'total_deductions_cents' => $totalDeductions,
            'net_cents' => $netSalary,
        ];

        $log['outputs'] = [
            'gross_cents' => $grossSalary,
            'income_tax_cents' => $incomeTax,
            'employee_pension_cents' => $pension['employee_cents'],
            'employer_pension_cents' => $pension['employer_cents'],
            'loan_deduction_cents' => $loanDeduction,
            'net_cents' => $netSalary,
        ];

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
            'calculation_log' => $log,
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
