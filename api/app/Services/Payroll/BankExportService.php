<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Exceptions\ImpersonationRestricted;
use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\PayrollRun;
use App\Support\Csv;

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
    /**
     * The bank file is an instruction to pay: the organisation builds it, an
     * operator in a support session does not.
     */
    public function assertAvailable(): void
    {
        ImpersonationRestricted::unlessOrganisation('BankExportService');
    }

    public function generateCsv(PayrollRun $run): string
    {
        $this->assertAvailable();

        $entries = $run->entries()->with(['employee.bankDetails'])->get();

        $lines = [];
        $lines[] = Csv::row([
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

            $lines[] = Csv::row([
                $employee->employee_code ?? '',
                $employee->name ?? '',
                $bank?->bank_name ?? '',
                $bank?->account_number ?? '',
                Csv::amount($entry->net_cents),
                'ETB',
            ]);
        }

        return implode("\r\n", $lines);
    }

    /**
     * The employee's designated salary account. Falls back to the first bank
     * detail on record if none is explicitly marked primary, rather than
     * dropping the employee from the file entirely.
     *
     * Public because PayrollController::bankExport() builds the same file as
     * JSON for the run page, and must pay into the same account as this CSV.
     * It used `bankDetails->first()`, which ignores `is_primary` and has no
     * ordering, so an employee with two accounts could be paid into either.
     */
    public function primaryBankDetail(Employee $employee): ?EmployeeBankDetail
    {
        $this->assertAvailable();

        $details = $employee->bankDetails;

        foreach ($details as $detail) {
            if ($detail->is_primary) {
                return $detail;
            }
        }

        return $details->isEmpty() ? null : $details->first();
    }
}
