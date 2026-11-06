<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\PayrollEntry;
use App\Models\PayrollRun;

final class AccountingExportService
{
    /**
     * Default account codes and names (can be overridden per tenant via ChartOfAccount).
     *
     * Public because these keys are also the only ones a tenant may override:
     * `UpdateChartOfAccountsRequest` validates `accounts.*.key` against them, so
     * a row saved under any other key — which the journal would never read —
     * is refused instead of stored (audit N11).
     */
    public const DEFAULTS = [
        'salary_expense' => ['code' => '5100', 'name' => 'Salary Expense'],
        'pension_expense' => ['code' => '5200', 'name' => 'Pension Expense (Employer)'],
        'tax_payable' => ['code' => '2100', 'name' => 'Income Tax Payable'],
        'pension_payable_employee' => ['code' => '2200', 'name' => 'Pension Payable (Employee)'],
        'pension_payable_employer' => ['code' => '2201', 'name' => 'Pension Payable (Employer)'],
        'net_salary_payable' => ['code' => '2300', 'name' => 'Net Salary Payable'],
        'loans_receivable' => ['code' => '1400', 'name' => 'Employee Loans Receivable'],
        'cost_sharing_payable' => ['code' => '2400', 'name' => 'Cost Sharing Payable'],
    ];

    /**
     * @param  array<string, array{code: string, name: string}|string>  $accountMapping
     *                                                                                   Each entry can be:
     *                                                                                   - new format: ['code' => '...', 'name' => '...']
     *                                                                                   - legacy format: just a code string (backwards compat)
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

        // Sums of `integer` casts, so integers; the casts say so to the contract,
        // which cannot type a collection `sum()` and published them as strings.
        $totalGross = (int) $entries->sum('gross_cents');
        $totalTax = (int) $entries->sum('income_tax_cents');
        $totalEmployeePension = (int) $entries->sum('employee_pension_cents');
        $totalEmployerPension = (int) $entries->sum('employer_pension_cents');
        $totalNet = (int) $entries->sum('net_cents');
        $totalOtherDeductions = (int) $entries->sum('other_deductions_cents');

        // What payslips withheld besides tax and pension, by kind, from each
        // entry's itemised deductions. Net Salary Payable is what employees are
        // paid, which is what the bank file pays; until 2026-11-03 it was
        // credited with net plus these, so a run with loans or cost sharing
        // told the books the company owed its people money it had withheld,
        // and showed nothing owed to the cost-sharing scheme (A-Z review F-03).
        $withheld = ['loan' => 0, 'cost_sharing' => 0];
        foreach ($entries as $entry) {
            foreach ((array) $entry->getAttribute('deductions') as $line) {
                $type = is_array($line) ? ($line['type'] ?? null) : null;
                if (is_string($type) && array_key_exists($type, $withheld)) {
                    $withheld[$type] += (int) ($line['amount_cents'] ?? 0);
                }
            }
        }
        // Anything itemised under another name, or an entry from before
        // deductions were itemised, stays with net pay, as it always did, so
        // the journal still balances.
        $unitemised = $totalOtherDeductions - $withheld['loan'] - $withheld['cost_sharing'];

        $journal = [
            'period' => $run->period_label,
            'date' => $run->period_end?->format('Y-m-d'),
            'reference' => 'PAYROLL-'.$run->public_id,
            'entries' => [],
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['salary_expense']['code'],
            'account_name' => $accounts['salary_expense']['name'],
            'debit_cents' => $totalGross,
            'credit_cents' => 0,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['pension_expense']['code'],
            'account_name' => $accounts['pension_expense']['name'],
            'debit_cents' => $totalEmployerPension,
            'credit_cents' => 0,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['tax_payable']['code'],
            'account_name' => $accounts['tax_payable']['name'],
            'debit_cents' => 0,
            'credit_cents' => $totalTax,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['pension_payable_employee']['code'],
            'account_name' => $accounts['pension_payable_employee']['name'],
            'debit_cents' => 0,
            'credit_cents' => $totalEmployeePension,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['pension_payable_employer']['code'],
            'account_name' => $accounts['pension_payable_employer']['name'],
            'debit_cents' => 0,
            'credit_cents' => $totalEmployerPension,
        ];

        $journal['entries'][] = [
            'account_code' => $accounts['net_salary_payable']['code'],
            'account_name' => $accounts['net_salary_payable']['name'],
            'debit_cents' => 0,
            'credit_cents' => (int) ($totalNet + $unitemised),
        ];

        // Repayments reduce what employees owe the company; cost sharing is
        // owed on to the scheme. A run with neither keeps its six lines.
        foreach (['loan' => 'loans_receivable', 'cost_sharing' => 'cost_sharing_payable'] as $type => $key) {
            if ($withheld[$type] > 0) {
                $journal['entries'][] = [
                    'account_code' => $accounts[$key]['code'],
                    'account_name' => $accounts[$key]['name'],
                    'debit_cents' => 0,
                    'credit_cents' => (int) $withheld[$type],
                ];
            }
        }

        $totalDebits = (int) collect($journal['entries'])->sum('debit_cents');
        $totalCredits = (int) collect($journal['entries'])->sum('credit_cents');

        $journal['total_debits_cents'] = $totalDebits;
        $journal['total_credits_cents'] = $totalCredits;
        $journal['is_balanced'] = $totalDebits === $totalCredits;

        return $journal;
    }
}
