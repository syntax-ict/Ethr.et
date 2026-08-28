<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\PayrollRun;

/**
 * Bank name / account number live on `EmployeeBankDetail` (an employee can
 * have several; `is_primary` marks the one salary goes to), never on
 * `Employee` itself — a prior version of this service read `$employee->
 * full_name` / `->bank_name` / `->bank_account`, none of which exist on
 * that model, so every export ever produced shipped with a blank name, bank
 * and account column. Fixed by resolving the primary bank detail per entry.
 */
final class BankExportService
{
    public function generateCsv(PayrollRun $run): string
    {
        $entries = $run->entries()->with(['employee.bankDetails'])->get();

        $lines = [];
        $lines[] = implode(',', [
            'Employee Code',
            'Employee Name',
            'Bank Name',
            'Account Number',
            'Net Pay (ETB)',
            'Currency',
        ]);

        foreach ($entries as $entry) {
            $employee = $entry->employee;
            if (! $employee) {
                continue;
            }

            $bank = $this->primaryBankDetail($employee);

            $lines[] = implode(',', [
                $this->csvEscape($employee->employee_code ?? ''),
                $this->csvEscape($employee->name ?? ''),
                $this->csvEscape($bank?->bank_name ?? ''),
                $this->csvEscape($bank?->account_number ?? ''),
                number_format($entry->net_cents / 100, 2, '.', ''),
                'ETB',
            ]);
        }

        return implode("\r\n", $lines);
    }

    public function generateCbeFormat(PayrollRun $run): string
    {
        $entries = $run->entries()->with(['employee.bankDetails'])->get();

        $lines = [];
        foreach ($entries as $entry) {
            $employee = $entry->employee;
            if (! $employee) {
                continue;
            }

            $bank = $this->primaryBankDetail($employee);
            if (! $bank?->account_number) {
                continue;
            }

            $lines[] = implode('|', [
                str_pad($bank->account_number, 13, '0', STR_PAD_LEFT),
                number_format($entry->net_cents / 100, 2, '.', ''),
                $employee->name ?? '',
                $run->period_label,
                'SALARY',
            ]);
        }

        return implode("\r\n", $lines);
    }

    /**
     * The employee's designated salary account. Falls back to the first bank
     * detail on record if none is explicitly marked primary, rather than
     * dropping the employee from the file entirely.
     */
    private function primaryBankDetail(Employee $employee): ?EmployeeBankDetail
    {
        $details = $employee->bankDetails;

        foreach ($details as $detail) {
            if ($detail->is_primary) {
                return $detail;
            }
        }

        return $details->isEmpty() ? null : $details->first();
    }

    private function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
