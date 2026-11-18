<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeBankDetail;
use App\Models\PayrollEntry;
use App\Models\Tenant;
use App\Support\Letterhead;
use App\Support\ReaderDates;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Throwable;

final class PayslipPdfService
{
    /**
     * The payslip written for `$reader`: in their language, with the period
     * and the date in their calendar. Without one, for the organisation. It
     * printed English and the stored Gregorian `period_label` for everyone.
     */
    public function generate(PayrollEntry $entry, ?ReaderDates $reader = null): string
    {
        $data = $this->data($entry, $reader);

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

    /**
     * What the payslip prints.
     *
     * @return array<string, mixed>
     */
    public function data(PayrollEntry $entry, ?ReaderDates $reader = null): array
    {
        $entry->loadMissing(['employee.bankDetails', 'payrollRun']);

        $tenant = Tenant::find($entry->tenant_id);
        $reader ??= ReaderDates::forTenant($tenant);
        $locale = $reader->locale;

        return [
            'locale' => $locale,
            'letterhead' => Letterhead::for($tenant, $locale),
            'tenant_name' => $tenant?->name ?? 'ETHR',
            'period' => $reader->periodOf($entry->payrollRun),
            // `name`: employees have no `full_name` column or accessor, so this
            // printed a blank name — the same defect BankExportService records.
            'employee_name' => $entry->employee->name ?? '',
            'employee_code' => $entry->employee->employee_code ?? '',
            'department' => $entry->employee->department?->name ?? '',
            // `title`: positions have no `name` column, so `->name` printed a blank
            // position on every payslip. Every other reader already uses `title`.
            'position' => $entry->employee->position?->title ?? '',
            'employee_tin' => self::masked(self::tinOf($entry->employee)),
            'bank_name' => self::bankOf($entry->employee)?->bank_name,
            'bank_account' => self::masked(self::accountOf(self::bankOf($entry->employee))),
            'pay_date' => $entry->payrollRun->paid_at === null ? null : $reader->date($entry->payrollRun->paid_at),
            'basic_salary_cents' => $entry->basic_salary_cents,
            'overtime_cents' => self::overtimeCents($entry),
            'allowances' => self::allowanceLines($entry->allowances, $locale),
            'gross_cents' => $entry->gross_cents,
            'income_tax_cents' => $entry->income_tax_cents,
            'employee_pension_cents' => $entry->employee_pension_cents,
            'employer_pension_cents' => $entry->employer_pension_cents,
            // The rates this entry was calculated with, from its own log; an
            // entry from before they were logged prints no rate at all.
            'employee_pension_rate' => self::rate($entry, 'employee_rate'),
            'employer_pension_rate' => self::rate($entry, 'employer_rate'),
            'other_deductions_cents' => $entry->other_deductions_cents,
            'deductions' => self::deductionLines($entry->getAttribute('deductions'), $locale),
            'net_cents' => $entry->net_cents,
            'generated_at' => $reader->dateTime(now()),
            'is_voided' => $entry->payrollRun->status === 'voided',
        ];
    }

    /**
     * Each allowance with a `label`: its own name, else its type in the
     * reader's language. An allowance without a name printed its raw type.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function allowanceLines(mixed $allowances, string $locale): array
    {
        $lines = [];
        foreach (is_array($allowances) ? $allowances : [] as $allowance) {
            if (! is_array($allowance)) {
                continue;
            }
            $name = is_string($allowance['name'] ?? null) ? trim($allowance['name']) : '';
            $type = is_string($allowance['type'] ?? null) ? $allowance['type'] : '';
            $key = 'payslip.allowance_types.'.$type;
            $allowance['label'] = $name !== ''
                ? $name
                : ($type !== '' && Lang::has($key, $locale) ? (string) __($key, [], $locale) : (string) __('payslip.allowance', [], $locale));
            $lines[] = $allowance;
        }

        return $lines;
    }

    /**
     * Only the last four characters of an identifier are printed, as
     * '******1234': a payslip is carried around and photographed.
     */
    public static function masked(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);
        if ($value === '') {
            return null;
        }
        $length = mb_strlen($value);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', $length - 4).mb_substr($value, -4);
    }

    private static function tinOf(Employee $employee): ?string
    {
        try {
            $tin = $employee->getAttribute('tin');
        } catch (Throwable) {
            return null;
        }

        return is_string($tin) ? $tin : null;
    }

    private static function accountOf(?EmployeeBankDetail $detail): ?string
    {
        if ($detail === null) {
            return null;
        }
        try {
            $account = $detail->getAttribute('account_number');
        } catch (Throwable) {
            return null;
        }

        return is_string($account) ? $account : null;
    }

    /** The designated salary account, else the first on record: as the bank export pays it. */
    private static function bankOf(Employee $employee): ?EmployeeBankDetail
    {
        $details = $employee->bankDetails;

        return $details->firstWhere('is_primary', true) ?? $details->first();
    }

    /**
     * Each deduction with a `label` in the reader's language: a loan read
     * `Loan`, and cost sharing `Cost_sharing`, in either language.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function deductionLines(mixed $deductions, string $locale): array
    {
        $lines = [];
        foreach (is_array($deductions) ? $deductions : [] as $deduction) {
            if (! is_array($deduction)) {
                continue;
            }
            $type = is_string($deduction['type'] ?? null) ? $deduction['type'] : '';
            $key = 'payslip.deduction_types.'.$type;
            $deduction['label'] = $type !== '' && Lang::has($key, $locale)
                ? (string) __($key, [], $locale)
                : ucfirst(str_replace('_', ' ', $type));
            $lines[] = $deduction;
        }

        return $lines;
    }

    private static function rate(PayrollEntry $entry, string $key): ?string
    {
        $pension = self::step($entry, 'pension');

        return is_numeric($pension[$key] ?? null) ? RunRegisterService::rateLabel([(float) $pension[$key]]) : null;
    }

    /**
     * The overtime the entry paid. It has no column: gross is basic, overtime
     * and allowances, and only the log holds the overtime, so the payslip
     * printed lines that did not add up to gross (known issue K-48). Read as
     * the run register reads it.
     */
    private static function overtimeCents(PayrollEntry $entry): int
    {
        $overtime = self::step($entry, 'overtime');

        return is_numeric($overtime['overtime_amount_cents'] ?? null) ? (int) $overtime['overtime_amount_cents'] : 0;
    }

    /** @return array<array-key, mixed> one step of the entry's calculation log, empty when it has none */
    private static function step(PayrollEntry $entry, string $name): array
    {
        $log = $entry->getAttribute('calculation_log');
        foreach ((is_array($log) && is_array($log['steps'] ?? null)) ? $log['steps'] : [] as $step) {
            if (is_array($step) && ($step['step'] ?? null) === $name) {
                return $step;
            }
        }

        return [];
    }
}
