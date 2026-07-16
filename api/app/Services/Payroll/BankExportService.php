<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollRun;

final class BankExportService
{
    public function generateCsv(PayrollRun $run): string
    {
        $entries = $run->entries()->with('employee')->get();

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

            $lines[] = implode(',', [
                $this->csvEscape($employee->employee_code ?? ''),
                $this->csvEscape($employee->full_name ?? ''),
                $this->csvEscape($employee->bank_name ?? ''),
                $this->csvEscape($employee->bank_account ?? ''),
                number_format($entry->net_cents / 100, 2, '.', ''),
                'ETB',
            ]);
        }

        return implode("\r\n", $lines);
    }

    public function generateCbeFormat(PayrollRun $run): string
    {
        $entries = $run->entries()->with('employee')->get();

        $lines = [];
        foreach ($entries as $entry) {
            $employee = $entry->employee;
            if (! $employee || ! $employee->bank_account) {
                continue;
            }

            $lines[] = implode('|', [
                str_pad($employee->bank_account, 13, '0', STR_PAD_LEFT),
                number_format($entry->net_cents / 100, 2, '.', ''),
                $employee->full_name ?? '',
                $run->period_label,
                'SALARY',
            ]);
        }

        return implode("\r\n", $lines);
    }

    private function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
