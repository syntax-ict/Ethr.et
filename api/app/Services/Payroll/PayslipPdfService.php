<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollEntry;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;

final class PayslipPdfService
{
    public function generate(PayrollEntry $entry): string
    {
        $entry->loadMissing(['employee', 'payrollRun']);

        $tenant = Tenant::find($entry->tenant_id);

        $data = [
            'tenant_name' => $tenant?->name ?? 'ETHR',
            'period' => $entry->payrollRun->period_label,
            'employee_name' => $entry->employee->full_name ?? '',
            'employee_code' => $entry->employee->employee_code ?? '',
            'department' => $entry->employee->department?->name ?? '',
            'position' => $entry->employee->position?->name ?? '',
            'basic_salary_cents' => $entry->basic_salary_cents,
            'allowances' => $entry->allowances ?? [],
            'gross_cents' => $entry->gross_cents,
            'income_tax_cents' => $entry->income_tax_cents,
            'employee_pension_cents' => $entry->employee_pension_cents,
            'employer_pension_cents' => $entry->employer_pension_cents,
            'other_deductions_cents' => $entry->other_deductions_cents,
            'deductions' => $entry->deductions ?? [],
            'net_cents' => $entry->net_cents,
            'generated_at' => now()->timezone('Africa/Addis_Ababa')->format('d/m/Y H:i'),
        ];

        $pdf = Pdf::loadView('payslip', $data);
        $pdf->setPaper('a5', 'portrait');

        return $pdf->output();
    }
}
