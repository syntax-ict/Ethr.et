<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Support\Csv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;

/**
 * The two monthly schedules an Ethiopian employer files from a payroll run:
 *
 * - **Employment income tax** (Ministry of Revenues): per employee, TIN,
 *   taxable income and the tax withheld.
 * - **Pension contributions** (POESSA for private employers, PSSSA for public
 *   bodies): per employee, the pensionable basic salary, the employee's 7 %
 *   and the employer's 11 %.
 *
 * Every figure comes from the run register (RunRegisterService), which reads
 * the stored entries and their calculation logs — never a recomputation — so
 * a schedule shows what was actually withheld and its totals equal the run's.
 *
 * **The column layout is ETHR's, not yet the authorities'.** Neither MoR nor
 * the pension agencies publish a machine format we could find; the letters
 * asking for one are drafted (owner action O3). The schedules carry every
 * figure those forms ask for and say on their face that the format is to be
 * confirmed, so nobody files them believing otherwise.
 */
final class StatutorySchedules
{
    public const INCOME_TAX = 'income-tax';

    public const PENSION = 'pension';

    public const SCHEDULES = [self::INCOME_TAX, self::PENSION];

    public const PENSION_SCHEMES = [
        'private' => "Private Organization Employees' Social Security Agency (POESSA)",
        'public' => "Public Servants' Social Security Agency (PSSSA)",
    ];

    public function __construct(private readonly RunRegisterService $register) {}

    /**
     * @return array{
     *     schedule: string,
     *     header: array<string, string|null|bool>,
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, int>,
     *     missing_tin: list<string>,
     * }
     */
    public function build(PayrollRun $run, string $schedule): array
    {
        $register = $this->register->register($run);
        $employees = Employee::withTrashed()
            ->whereIn('public_id', array_filter(array_column($register['rows'], 'employee_public_id')))
            ->get()
            ->keyBy('public_id');

        $rows = [];
        $missingTin = [];
        foreach ($register['rows'] as $i => $line) {
            /** @var Employee|null $employee */
            $employee = $employees->get($line['employee_public_id']);
            $tin = $this->tin($employee);
            if ($tin === null) {
                $missingTin[] = (string) $line['employee_name'];
            }

            $common = [
                'no' => $i + 1,
                'employee_code' => $line['employee_code'],
                'employee_name' => $line['employee_name'],
                'tin' => $tin,
            ];

            if ($schedule === self::INCOME_TAX) {
                $rows[] = $common + [
                    'start_date' => $employee?->hire_date?->format('Y-m-d'),
                    'basic_cents' => $line['basic_cents'],
                    'overtime_cents' => $line['overtime_cents'],
                    'allowances_cents' => $line['allowances_cents'],
                    'taxable_cents' => $line['taxable_cents'],
                    'income_tax_cents' => $line['income_tax_cents'],
                ];
            } else {
                if ($line['employee_pension_cents'] === 0 && $line['employer_pension_cents'] === 0) {
                    // Not a contributor (a contract or foreign employee the
                    // engine exempted): not on the pension schedule at all.
                    continue;
                }

                $rows[] = $common + [
                    'basic_cents' => $line['basic_cents'],
                    'employee_pension_cents' => $line['employee_pension_cents'],
                    'employer_pension_cents' => $line['employer_pension_cents'],
                    'total_pension_cents' => $line['employee_pension_cents'] + $line['employer_pension_cents'],
                ];
            }
        }

        $columns = $schedule === self::INCOME_TAX
            ? ['basic_cents', 'overtime_cents', 'allowances_cents', 'taxable_cents', 'income_tax_cents']
            : ['basic_cents', 'employee_pension_cents', 'employer_pension_cents', 'total_pension_cents'];

        $totals = [];
        foreach ($columns as $column) {
            $totals[$column] = (int) array_sum(array_column($rows, $column));
        }

        return [
            'schedule' => $schedule,
            'header' => $this->header($run, $schedule),
            'rows' => $rows,
            'totals' => $totals,
            'missing_tin' => $schedule === self::PENSION
                ? array_values(array_intersect($missingTin, array_column($rows, 'employee_name')))
                : $missingTin,
        ];
    }

    public function csv(PayrollRun $run, string $schedule): string
    {
        $data = $this->build($run, $schedule);
        $h = $data['header'];

        $lines = [
            Csv::row([$h['title']]),
            Csv::row(['Employer', $h['employer_name']]),
            Csv::row(['Employer TIN', $h['employer_tin'] ?? 'NOT SET']),
            Csv::row([$schedule === self::INCOME_TAX ? 'Tax centre' : 'Scheme', $schedule === self::INCOME_TAX ? ($h['tax_center'] ?? '') : $h['scheme']]),
            Csv::row(['Period', $h['period']]),
            Csv::row(['Status', $h['status']]),
            Csv::row([$h['notice']]),
            '',
        ];

        if ($schedule === self::INCOME_TAX) {
            $lines[] = Csv::row(['No', 'Employee TIN', 'Employee Name', 'Employee Code', 'Start Date', 'Basic Salary (ETB)', 'Overtime (ETB)', 'Allowances (ETB)', 'Taxable Income (ETB)', 'Tax Withheld (ETB)']);
            foreach ($data['rows'] as $r) {
                $lines[] = Csv::row([$r['no'], $r['tin'] ?? '', $r['employee_name'], $r['employee_code'], $r['start_date'] ?? '',
                    Csv::amount($r['basic_cents']), Csv::amount($r['overtime_cents']), Csv::amount($r['allowances_cents']),
                    Csv::amount($r['taxable_cents']), Csv::amount($r['income_tax_cents'])]);
            }
            $t = $data['totals'];
            $lines[] = Csv::row(['TOTAL', '', count($data['rows']).' employees', '', '', Csv::amount($t['basic_cents']), Csv::amount($t['overtime_cents']),
                Csv::amount($t['allowances_cents']), Csv::amount($t['taxable_cents']), Csv::amount($t['income_tax_cents'])]);
        } else {
            $lines[] = Csv::row(['No', 'Employee TIN', 'Employee Name', 'Employee Code', 'Basic Salary (ETB)', 'Employee 7% (ETB)', 'Employer 11% (ETB)', 'Total 18% (ETB)']);
            foreach ($data['rows'] as $r) {
                $lines[] = Csv::row([$r['no'], $r['tin'] ?? '', $r['employee_name'], $r['employee_code'],
                    Csv::amount($r['basic_cents']), Csv::amount($r['employee_pension_cents']),
                    Csv::amount($r['employer_pension_cents']), Csv::amount($r['total_pension_cents'])]);
            }
            $t = $data['totals'];
            $lines[] = Csv::row(['TOTAL', '', count($data['rows']).' employees', '', Csv::amount($t['basic_cents']),
                Csv::amount($t['employee_pension_cents']), Csv::amount($t['employer_pension_cents']), Csv::amount($t['total_pension_cents'])]);
        }

        return implode("\r\n", $lines);
    }

    public function pdf(PayrollRun $run, string $schedule): string
    {
        // Ethiopic names need the bundled Noto face (see PayslipPdfService).
        File::ensureDirectoryExists(storage_path('fonts'));

        $pdf = Pdf::loadView('payroll.statutory-schedule', $this->build($run, $schedule));
        $pdf->setPaper('a4', 'landscape');
        $pdf->setOption('isFontSubsettingEnabled', true);

        return $pdf->output();
    }

    /** @return array<string, string|null|bool> */
    private function header(PayrollRun $run, string $schedule): array
    {
        $tenant = Tenant::query()->find($run->tenant_id);
        $scheme = $tenant !== null && self::setting($tenant, 'pension_scheme') === 'public' ? 'public' : 'private';

        return [
            'title' => $schedule === self::INCOME_TAX
                ? 'Employment Income Tax Schedule'
                : 'Pension Contribution Schedule',
            'employer_name' => $tenant === null ? '' : (string) $tenant->name,
            'employer_tin' => $tenant === null ? null : self::setting($tenant, 'employer_tin'),
            'tax_center' => $tenant === null ? null : self::setting($tenant, 'tax_center'),
            'scheme' => self::PENSION_SCHEMES[$scheme],
            'period' => (string) $run->period_label,
            'status' => $run->status === 'approved' ? 'Approved' : 'NOT APPROVED — draft figures',
            'approved' => $run->status === 'approved',
            'notice' => 'Prepared by ETHR from the approved payroll. Layout to be confirmed with '
                .($schedule === self::INCOME_TAX ? 'the Ministry of Revenues' : ($scheme === 'public' ? 'PSSSA' : 'POESSA'))
                .' before filing; the figures are the payroll\'s own.',
            'generated_at' => now('Africa/Addis_Ababa')->format('d/m/Y H:i'),
        ];
    }

    /**
     * A string from the organisation settings (Settings → Payroll →
     * Statutory filing), or null when it is unset or blank.
     */
    public static function setting(Tenant $tenant, string $key): ?string
    {
        $settings = $tenant->getAttribute('settings');
        $value = is_array($settings) ? ($settings[$key] ?? null) : null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function tin(?Employee $employee): ?string
    {
        if ($employee === null) {
            return null;
        }

        try {
            $tin = $employee->getAttribute('tin');
        } catch (\Throwable) {
            // Encrypted under another key (a restore without its APP_KEY):
            // say it is missing rather than fail the whole schedule.
            return null;
        }

        return is_string($tin) && trim($tin) !== '' ? trim($tin) : null;
    }
}
