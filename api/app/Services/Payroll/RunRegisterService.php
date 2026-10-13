<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Support\Csv;

/**
 * The payroll register: one row per employee with every figure that makes up
 * their pay, and a totals row — what an accountant reconciles a run against.
 *
 * The bank file carries net pay only and the journal carries totals only, so
 * there was no single document showing, per employee, basic → overtime →
 * allowances → gross → taxable → tax → pension (both shares) → other
 * deductions → net. Every figure is read from the stored entry and its
 * immutable calculation_log (convention #11), never recomputed, so the
 * register shows what was actually paid. Its totals equal the run's totals
 * and the accounting journal's lines (PayrollRegisterTest asserts both).
 */
final class RunRegisterService
{
    public const COLUMNS = [
        'basic_cents', 'overtime_cents', 'allowances_cents', 'gross_cents', 'taxable_cents',
        'income_tax_cents', 'employee_pension_cents', 'employer_pension_cents',
        'loan_cents', 'cost_sharing_cents', 'net_cents',
    ];

    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function register(PayrollRun $run): array
    {
        $entries = PayrollEntry::query()
            ->where('payroll_run_id', $run->id)
            ->with('employee')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->row($entry);
        }

        $totals = [];
        foreach (self::COLUMNS as $column) {
            $totals[$column] = (int) array_sum(array_column($rows, $column));
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    public function csv(PayrollRun $run): string
    {
        $register = $this->register($run);

        $lines = [Csv::row([
            'Employee Code', 'Employee Name', 'Basic (ETB)', 'Overtime (ETB)', 'Allowances (ETB)',
            'Gross (ETB)', 'Taxable (ETB)', 'Income Tax (ETB)', 'Employee Pension 7% (ETB)',
            'Employer Pension 11% (ETB)', 'Loan (ETB)', 'Cost Sharing (ETB)', 'Net Pay (ETB)',
        ])];

        foreach ($register['rows'] as $row) {
            $lines[] = Csv::row([
                $row['employee_code'], $row['employee_name'],
                ...array_map(static fn (string $c): string => Csv::amount($row[$c]), self::COLUMNS),
            ]);
        }

        $lines[] = Csv::row([
            'TOTAL', count($register['rows']).' employees',
            ...array_map(static fn (string $c): string => Csv::amount($register['totals'][$c]), self::COLUMNS),
        ]);

        return implode("\r\n", $lines);
    }

    /** @return array<string, mixed> */
    private function row(PayrollEntry $entry): array
    {
        // The `array` cast: read as the loosely-typed attribute so the shape
        // checks below stay honest under analysis.
        $log = $entry->getAttribute('calculation_log');
        $logSteps = is_array($log) && is_array($log['steps'] ?? null) ? $log['steps'] : [];

        /** @var array<string, array<string, mixed>> $steps */
        $steps = [];
        foreach ($logSteps as $step) {
            if (is_array($step) && is_string($step['step'] ?? null)) {
                $steps[$step['step']] = $step;
            }
        }

        /** @var Employee|null $employee */
        $employee = $entry->getRelation('employee');

        $deductions = collect((array) $entry->deductions);
        $allowances = (int) collect((array) $entry->allowances)->sum('amount_cents');

        return [
            'employee_public_id' => $employee?->public_id,
            'employee_code' => $employee === null ? '' : (string) $employee->employee_code,
            'employee_name' => $employee === null ? '' : (string) $employee->name,
            'basic_cents' => (int) $entry->basic_salary_cents,
            'overtime_cents' => (int) ($steps['overtime']['overtime_amount_cents'] ?? 0),
            'allowances_cents' => $allowances,
            'gross_cents' => (int) $entry->gross_cents,
            'taxable_cents' => (int) ($steps['income_tax']['taxable_amount_cents'] ?? $entry->gross_cents),
            'income_tax_cents' => (int) $entry->income_tax_cents,
            'employee_pension_cents' => (int) $entry->employee_pension_cents,
            'employer_pension_cents' => (int) $entry->employer_pension_cents,
            'loan_cents' => (int) $deductions->where('type', 'loan')->sum('amount_cents'),
            'cost_sharing_cents' => (int) $deductions->where('type', 'cost_sharing')->sum('amount_cents'),
            'net_cents' => (int) $entry->net_cents,
        ];
    }
}
