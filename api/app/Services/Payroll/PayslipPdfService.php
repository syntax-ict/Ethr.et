<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollEntry;
use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

final class PayslipPdfService
{
    public function generate(PayrollEntry $entry): string
    {
        $entry->loadMissing(['employee', 'payrollRun']);

        $tenant = Tenant::find($entry->tenant_id);

        $data = [
            'tenant_name' => $tenant?->name ?? 'ETHR',
            'period' => $entry->payrollRun->period_label,
            // `name`: employees have no `full_name` column or accessor, so this
            // printed a blank name — the same defect BankExportService records.
            'employee_name' => $entry->employee->name ?? '',
            'employee_code' => $entry->employee->employee_code ?? '',
            'department' => $entry->employee->department?->name ?? '',
            // `title`: positions have no `name` column, so `->name` printed a blank
            // position on every payslip. Every other reader already uses `title`.
            'position' => $entry->employee->position?->title ?? '',
            'basic_salary_cents' => $entry->basic_salary_cents,
            'allowances' => $entry->allowances ?? [],
            'gross_cents' => $entry->gross_cents,
            'income_tax_cents' => $entry->income_tax_cents,
            'employee_pension_cents' => $entry->employee_pension_cents,
            'employer_pension_cents' => $entry->employer_pension_cents,
            // The rates this entry was calculated with, from its own log; an
            // entry from before they were logged prints no rate at all.
            'employee_pension_rate' => self::rate($entry, 'employee_rate'),
            'employer_pension_rate' => self::rate($entry, 'employer_rate'),
            'other_deductions_cents' => $entry->other_deductions_cents,
            'deductions' => $entry->deductions ?? [],
            'net_cents' => $entry->net_cents,
            'generated_at' => now()->timezone('Africa/Addis_Ababa')->format('d/m/Y H:i'),
            'is_voided' => $entry->payrollRun->status === 'voided',
        ];

        // Ethiopic glyphs come from the bundled Noto Sans Ethiopic: DejaVu has
        // none, so every Amharic name printed as empty boxes (audit N79).
        // dompdf caches font metrics here and does not create the directory.
        File::ensureDirectoryExists(storage_path('fonts'));

        $pdf = Pdf::loadView('payslip', $data);
        $pdf->setPaper('a5', 'portrait');
        // Embed only the glyphs used: whole DejaVu and Ethiopic faces made
        // each payslip over a megabyte.
        $pdf->setOption('isFontSubsettingEnabled', true);

        return $pdf->output();
    }

    private static function rate(PayrollEntry $entry, string $key): ?string
    {
        $log = $entry->getAttribute('calculation_log');
        foreach ((is_array($log) && is_array($log['steps'] ?? null)) ? $log['steps'] : [] as $step) {
            if (is_array($step) && ($step['step'] ?? null) === 'pension' && is_numeric($step[$key] ?? null)) {
                return RunRegisterService::rateLabel([(float) $step[$key]]);
            }
        }

        return null;
    }
}
