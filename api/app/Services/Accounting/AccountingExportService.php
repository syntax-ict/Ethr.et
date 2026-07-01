<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\PayrollEntry;
use App\Models\PayrollRun;

final class AccountingExportService
{
    /** Default account codes and names (can be overridden per tenant via ChartOfAccount) */
    private const DEFAULTS = [
        'salary_expense'          => ['code' => '5100', 'name' => 'Salary Expense'],
        'pension_expense'         => ['code' => '5200', 'name' => 'Pension Expense (Employer)'],
        'tax_payable'             => ['code' => '2100', 'name' => 'Income Tax Payable'],
        'pension_payable_employee'=> ['code' => '2200', 'name' => 'Pension Payable (Employee)'],
        'pension_payable_employer'=> ['code' => '2201', 'name' => 'Pension Payable (Employer)'],
        'net_salary_payable'      => ['code' => '2300', 'name' => 'Net Salary Payable'],
    ];

    /**
     * @param array<string, array{code: string, name: string}|string> $accountMapping
     *   Each entry can be:
     *   - new format: ['code' => '...', 'name' => '...']
     *   - legacy format: just a code string (backwards compat)
     */
    public function journalEntries(PayrollRun $run, array $accountMapping = []): array
    {
        // Merge defaults with tenant overrides
        $accounts = self::DEFAULTS;
        foreach ($accountMapping as $key => $override) {
            if (is_array($override)) {
                $accounts[$key] = $override;
            } else {
                // Legacy: only code provided
                $accounts[$key]['code'] = $override;
            }
        }

        $entries = PayrollEntry::withoutGlobalScope('tenant')
            ->where('payroll_run_id', $run->id)
            ->with('employee:id,name,employee_code')
            ->get();

        $totalGross             = $entries->sum('gross_cents');
        $totalTax               = $entries->sum('income_tax_cents');
        $totalEmployeePension   = $entries->sum('employee_pension_cents');
        $totalEmployerPension   = $entries->sum('employer_pension_cents');
        $totalNet               = $entries->sum('net_cents');
        $totalOtherDeductions   = $entries->sum('other_deductions_cents');

        $journal = [
            'period'    => $run->period_label,
            'date'      => $run->period_end?->format('Y-m-d'),
            'reference' => 'PAYROLL-' . $run->public_id,
            'entries'   => [],
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['salary_expense']['code'],
            'account_name' => $accounts['salary_expense']['name'],
            'debit_cents'  => $totalGross,
            'credit_cents' => 0,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['pension_expense']['code'],
            'account_name' => $accounts['pension_expense']['name'],
            'debit_cents'  => $totalEmployerPension,
            'credit_cents' => 0,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['tax_payable']['code'],
            'account_name' => $accounts['tax_payable']['name'],
            'debit_cents'  => 0,
            'credit_cents' => $totalTax,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['pension_payable_employee']['code'],
            'account_name' => $accounts['pension_payable_employee']['name'],
            'debit_cents'  => 0,
            'credit_cents' => $totalEmployeePension,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['pension_payable_employer']['code'],
            'account_name' => $accounts['pension_payable_employer']['name'],
            'debit_cents'  => 0,
            'credit_cents' => $totalEmployerPension,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['net_salary_payable']['code'],
            'account_name' => $accounts['net_salary_payable']['name'],
            'debit_cents'  => 0,
            'credit_cents' => $totalNet + $totalOtherDeductions,
        ];

        $totalDebits  = collect($journal['entries'])->sum('debit_cents');
        $totalCredits = collect($journal['entries'])->sum('credit_cents');

        $journal['total_debits_cents']  = $totalDebits;
        $journal['total_credits_cents'] = $totalCredits;
        $journal['is_balanced']         = $totalDebits === $totalCredits;

        return $journal;
    }
}
