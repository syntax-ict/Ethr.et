<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\PayrollEntry;
use App\Models\PayrollRun;

final class AccountingExportService
{
    private const DEFAULT_ACCOUNTS = [
        'salary_expense' => '5100',
        'tax_payable' => '2100',
        'pension_payable_employee' => '2200',
        'pension_payable_employer' => '2201',
        'net_salary_payable' => '2300',
        'pension_expense' => '5200',
    ];

    public function journalEntries(PayrollRun $run, array $accountMapping = []): array
    {
        $accounts = array_merge(self::DEFAULT_ACCOUNTS, $accountMapping);

        $entries = PayrollEntry::withoutGlobalScope('tenant')
            ->where('payroll_run_id', $run->id)
            ->with('employee:id,name,employee_code')
            ->get();

        $totalGross = $entries->sum('gross_cents');
        $totalTax = $entries->sum('income_tax_cents');
        $totalEmployeePension = $entries->sum('employee_pension_cents');
        $totalEmployerPension = $entries->sum('employer_pension_cents');
        $totalNet = $entries->sum('net_cents');
        $totalOtherDeductions = $entries->sum('other_deductions_cents');

        $journal = [
            'period' => $run->period_label,
            'date' => $run->period_end?->format('Y-m-d'),
            'reference' => 'PAYROLL-' . $run->public_id,
            'entries' => [],
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['salary_expense'],
            'account_name' => 'Salary Expense',
            'debit_cents' => $totalGross,
            'credit_cents' => 0,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['pension_expense'],
            'account_name' => 'Pension Expense (Employer)',
            'debit_cents' => $totalEmployerPension,
            'credit_cents' => 0,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['tax_payable'],
            'account_name' => 'Income Tax Payable',
            'debit_cents' => 0,
            'credit_cents' => $totalTax,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['pension_payable_employee'],
            'account_name' => 'Pension Payable (Employee)',
            'debit_cents' => 0,
            'credit_cents' => $totalEmployeePension,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['pension_payable_employer'],
            'account_name' => 'Pension Payable (Employer)',
            'debit_cents' => 0,
            'credit_cents' => $totalEmployerPension,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['net_salary_payable'],
            'account_name' => 'Net Salary Payable',
            'debit_cents' => 0,
            'credit_cents' => $totalNet + $totalOtherDeductions,
        ];

        $totalDebits = collect($journal['entries'])->sum('debit_cents');
        $totalCredits = collect($journal['entries'])->sum('credit_cents');

        $journal['total_debits_cents'] = $totalDebits;
        $journal['total_credits_cents'] = $totalCredits;
        $journal['is_balanced'] = $totalDebits === $totalCredits;

        return $journal;
    }
}
