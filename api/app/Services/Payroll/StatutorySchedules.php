<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\StatutoryLayout;
use App\Models\Tenant;
use App\Support\Csv;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * The two monthly schedules an Ethiopian employer files from a payroll run:
 * employment income tax (Ministry of Revenues) and pension contributions
 * (POESSA for private employers, PSSSA for public bodies).
 *
 * Nothing statutory is written here. The figures come from the run register,
 * which reads the stored entries and their calculation logs; the rates, the
 * exemptions and the law each run applied come from those logs; and the
 * agency, title and columns come from the dated `statutory_layouts` row in
 * force for the period. So a change in the law is a new pension rule, and a
 * new form version is a new layout — neither is a deploy.
 *
 * **The layouts are ETHR's, not yet the authorities'** (research F1–F3; owner
 * action O3). Every schedule says so, and says which rules it was built on.
 */
final class StatutorySchedules
{
    public const INCOME_TAX = 'income-tax';

    public const PENSION = 'pension';

    public const SCHEDULES = [self::INCOME_TAX, self::PENSION];

    /** Columns a layout may use, and the register field each reads. Money fields are cents. */
    public const COLUMN_FIELDS = [
        'no' => null,
        'tin' => null,
        'employee_name' => null,
        'employee_code' => null,
        'start_date' => null,
        'basic' => 'basic_cents',
        'overtime' => 'overtime_cents',
        'allowances' => 'allowances_cents',
        'taxable' => 'taxable_cents',
        'income_tax' => 'income_tax_cents',
        'pensionable' => 'pensionable_cents',
        'employee_pension' => 'employee_pension_cents',
        'employer_pension' => 'employer_pension_cents',
        'total_pension' => 'total_pension_cents',
    ];

    public function __construct(private readonly RunRegisterService $register) {}

    /**
     * @return array{
     *     schedule: string,
     *     header: array<string, string|null|bool>,
     *     columns: list<array{key: string, label: string, money: bool}>,
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, int>,
     *     missing_tin: list<string>,
     *     basis: array{pension: list<array<string, mixed>>, tax: list<array<string, mixed>>},
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
        foreach ($register['rows'] as $line) {
            if ($schedule === self::PENSION && ! self::onPensionSchedule($line)) {
                continue;
            }

            /** @var Employee|null $employee */
            $employee = $employees->get($line['employee_public_id']);
            $tin = $this->tin($employee);
            if ($tin === null) {
                $missingTin[] = (string) $line['employee_name'];
            }

            $rows[] = [
                'no' => count($rows) + 1,
                'tin' => $tin,
                'employee_name' => $line['employee_name'],
                'employee_code' => $line['employee_code'],
                'start_date' => $employee?->hire_date?->format('Y-m-d'),
                'basic_cents' => $line['basic_cents'],
                'overtime_cents' => $line['overtime_cents'],
                'allowances_cents' => $line['allowances_cents'],
                'taxable_cents' => $line['taxable_cents'],
                'income_tax_cents' => $line['income_tax_cents'],
                'pensionable_cents' => $line['pensionable_cents'],
                'employee_pension_cents' => $line['employee_pension_cents'],
                'employer_pension_cents' => $line['employer_pension_cents'],
                'total_pension_cents' => $line['employee_pension_cents'] + $line['employer_pension_cents'],
            ];
        }

        $tenant = Tenant::query()->find($run->tenant_id);
        $scheme = $this->scheme($register['basis'], $register['rows'], $tenant);
        $layout = $this->layout($schedule, $schedule === self::PENSION ? $scheme : null, Carbon::parse($run->period_start));
        $columns = $this->columns($layout['columns'], $register['pension_rates']);

        $totals = [];
        foreach ($columns as $column) {
            $field = self::COLUMN_FIELDS[$column['key']] ?? null;
            if ($column['money'] && $field !== null) {
                $totals[$field] = (int) array_sum(array_column($rows, $field));
            }
        }

        return [
            'schedule' => $schedule,
            'header' => $this->header($run, $tenant, $layout, $register['basis']),
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $totals,
            'missing_tin' => $missingTin,
            'basis' => $register['basis'],
        ];
    }

    public function csv(PayrollRun $run, string $schedule): string
    {
        $data = $this->build($run, $schedule);
        $h = $data['header'];

        $lines = [
            Csv::row([$h['title']]),
            Csv::row(['Agency', $h['agency']]),
            Csv::row(['Employer', $h['employer_name']]),
            Csv::row(['Employer TIN', $h['employer_tin'] ?? 'NOT SET']),
            Csv::row(['Tax centre', $h['tax_center'] ?? '']),
            Csv::row(['Period', $h['period']]),
            Csv::row(['Status', $h['status']]),
            Csv::row(['Rules applied', $h['basis']]),
            Csv::row([$h['notice']]),
            '',
            Csv::row(array_column($data['columns'], 'label')),
        ];

        foreach ($data['rows'] as $row) {
            $lines[] = Csv::row(array_map(fn (array $c) => $this->cell($row, $c, true), $data['columns']));
        }

        $lines[] = Csv::row($this->totalsRow($data, true));

        return implode("\r\n", $lines);
    }

    public function pdf(PayrollRun $run, string $schedule): string
    {
        // Ethiopic names need the bundled Noto face (see PayslipPdfService).
        File::ensureDirectoryExists(storage_path('fonts'));

        $data = $this->build($run, $schedule);
        $data['cells'] = array_map(
            fn (array $row) => array_map(fn (array $c) => $this->cell($row, $c, false), $data['columns']),
            $data['rows'],
        );
        $data['total_cells'] = $this->totalsRow($data, false);

        $pdf = Pdf::loadView('payroll.statutory-schedule', $data);
        $pdf->setPaper('a4', 'landscape');
        $pdf->setOption('isFontSubsettingEnabled', true);

        return $pdf->output();
    }

    /**
     * The layout in force for a schedule (and pension scheme) on a date: the
     * dated `statutory_layouts` row, or ETHR's built-in layout when the
     * database has none — the same last resort the rate lookups keep.
     *
     * @return array{agency: string, title: string, note: string|null, columns: list<array{key: string, label: string}>, effective_from: string|null}
     */
    public function layout(string $schedule, ?string $scheme, CarbonInterface $asOf): array
    {
        $on = $asOf->format('Y-m-d');
        $row = StatutoryLayout::query()
            ->where('schedule', $schedule)
            ->when($scheme === null, fn ($q) => $q->whereNull('scheme'), fn ($q) => $q->where('scheme', $scheme))
            ->whereDate('effective_from', '<=', $on)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $on))
            ->orderByDesc('effective_from')
            ->first();

        if ($row !== null) {
            return [
                'agency' => $row->agency,
                'title' => $row->title,
                'note' => $row->note,
                'columns' => array_values(array_filter($row->columns, fn ($c) => is_array($c) && array_key_exists((string) ($c['key'] ?? ''), self::COLUMN_FIELDS))),
                'effective_from' => $row->effective_from->format('Y-m-d'),
            ];
        }

        return self::builtInLayout($schedule);
    }

    /**
     * Who is on the pension schedule: the rule's verdict as the engine logged
     * it. An entry calculated before exemptions were logged (2026-10-14) falls
     * back to whether any pension was taken.
     *
     * @param  array<string, mixed>  $line
     */
    public static function onPensionSchedule(array $line): bool
    {
        if (is_bool($line['pension_exempt'] ?? null)) {
            return ! $line['pension_exempt'];
        }

        return ($line['employee_pension_cents'] ?? 0) > 0 || ($line['employer_pension_cents'] ?? 0) > 0;
    }

    /**
     * "Pension: Proclamation No. 1268/2022, Art. 10, effective 18/03/2022;
     * Tax: Income Tax (Amendment) Proclamation No. 1395/2025, effective 07/07/2025"
     *
     * @param  array{pension: list<array<string, mixed>>, tax: list<array<string, mixed>>}  $basis
     */
    public static function basisLine(array $basis): string
    {
        $describe = static function (array $rules): string {
            if ($rules === []) {
                return 'not recorded (run calculated before 2026-10-14)';
            }

            return implode(' and ', array_map(static function (array $r): string {
                $text = (string) $r['legal_reference'];
                if (! empty($r['effective_from'])) {
                    $text .= ', effective '.Carbon::parse($r['effective_from'])->format('d/m/Y');
                }
                if (($r['source'] ?? null) === 'tenant') {
                    $text .= ' (organisation rule)';
                }

                return $text;
            }, $rules));
        };

        return 'Pension: '.$describe($basis['pension']).'; Tax: '.$describe($basis['tax']);
    }

    /**
     * @param  list<array{key: string, label: string}>  $columns
     * @param  array{employee: list<float>, employer: list<float>}  $rates
     * @return list<array{key: string, label: string, money: bool}>
     */
    private function columns(array $columns, array $rates): array
    {
        $total = [];
        if (count($rates['employee']) === 1 && count($rates['employer']) === 1) {
            $total = [$rates['employee'][0] + $rates['employer'][0]];
        }

        $replace = [
            '{employee_rate}%' => RunRegisterService::rateLabel($rates['employee']),
            '{employer_rate}%' => RunRegisterService::rateLabel($rates['employer']),
            '{total_rate}%' => RunRegisterService::rateLabel($total),
        ];

        return array_map(static function (array $c) use ($replace): array {
            // A rate the run did not record leaves the label without one,
            // rather than printing a number nobody applied.
            $label = preg_replace('/\s{2,}/', ' ', trim(strtr((string) $c['label'], $replace))) ?? (string) $c['label'];

            return [
                'key' => (string) $c['key'],
                'label' => $label,
                'money' => self::COLUMN_FIELDS[$c['key']] !== null,
            ];
        }, $columns);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{key: string, label: string, money: bool}  $column
     */
    private function cell(array $row, array $column, bool $csv): string
    {
        $field = self::COLUMN_FIELDS[$column['key']] ?? null;
        if ($field !== null) {
            $cents = (int) ($row[$field] ?? 0);

            return $csv ? Csv::amount($cents) : number_format($cents / 100, 2);
        }

        $value = $row[$column['key']] ?? null;

        return $value === null ? ($column['key'] === 'tin' && ! $csv ? 'missing' : '') : (string) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function totalsRow(array $data, bool $csv): array
    {
        $cells = [];
        $labelled = false;
        foreach ($data['columns'] as $column) {
            $field = self::COLUMN_FIELDS[$column['key']] ?? null;
            if ($field !== null) {
                $cents = (int) ($data['totals'][$field] ?? 0);
                $cells[] = $csv ? Csv::amount($cents) : number_format($cents / 100, 2);
            } elseif (! $labelled) {
                $cells[] = 'TOTAL';
                $labelled = true;
            } elseif ($column['key'] === 'employee_name') {
                $cells[] = count($data['rows']).' employees';
            } else {
                $cells[] = '';
            }
        }

        return $cells;
    }

    /**
     * @param  array{agency: string, title: string, note: string|null, columns: list<array{key: string, label: string}>, effective_from: string|null}  $layout
     * @param  array{pension: list<array<string, mixed>>, tax: list<array<string, mixed>>}  $basis
     * @return array<string, string|null|bool>
     */
    private function header(PayrollRun $run, ?Tenant $tenant, array $layout, array $basis): array
    {
        return [
            'title' => $layout['title'],
            'agency' => $layout['agency'],
            'employer_name' => $tenant === null ? '' : (string) $tenant->name,
            'employer_tin' => $tenant === null ? null : self::setting($tenant, 'employer_tin'),
            'tax_center' => $tenant === null ? null : self::setting($tenant, 'tax_center'),
            'period' => (string) $run->period_label,
            'status' => match ($run->status) {
                'paid' => 'Approved and paid',
                'approved' => 'Approved',
                default => 'NOT APPROVED — draft figures',
            },
            'approved' => $run->isReleased(),
            'basis' => self::basisLine($basis),
            'notice' => $layout['note']
                ?? 'Layout prepared by ETHR; to be confirmed with the agency before filing (owner action O3).',
            'layout_effective_from' => $layout['effective_from'],
            'generated_at' => now('Africa/Addis_Ababa')->format('d/m/Y H:i'),
        ];
    }

    /**
     * The pension scheme the run applied, from its logs; the scheme in force
     * for the organisation today only for a run that did not record one.
     *
     * @param  array{pension: list<array<string, mixed>>, tax: list<array<string, mixed>>}  $basis
     * @param  list<array<string, mixed>>  $rows
     */
    private function scheme(array $basis, array $rows, ?Tenant $tenant): string
    {
        foreach ($rows as $row) {
            $scheme = $row['pension_rule']['scheme'] ?? null;
            if (is_string($scheme) && $scheme !== '') {
                return $scheme;
            }
        }

        $rules = app(StatutoryRules::class);
        $today = Carbon::today();

        return ($tenant === null ? $rules->platform($today) : $rules->for($tenant, $today))->string('pension.default_scheme');
    }

    /**
     * @return array{agency: string, title: string, note: string|null, columns: list<array{key: string, label: string}>, effective_from: string|null}
     */
    private static function builtInLayout(string $schedule): array
    {
        $note = 'Layout prepared by ETHR; to be confirmed with the agency before filing (owner action O3).';

        return $schedule === self::INCOME_TAX
            ? [
                'agency' => 'Ministry of Revenues',
                'title' => 'Employment Income Tax Schedule',
                'note' => $note,
                'columns' => [
                    ['key' => 'no', 'label' => 'No'], ['key' => 'tin', 'label' => 'Employee TIN'],
                    ['key' => 'employee_name', 'label' => 'Employee Name'], ['key' => 'employee_code', 'label' => 'Employee Code'],
                    ['key' => 'start_date', 'label' => 'Start Date'], ['key' => 'basic', 'label' => 'Basic Salary (ETB)'],
                    ['key' => 'overtime', 'label' => 'Overtime (ETB)'], ['key' => 'allowances', 'label' => 'Allowances (ETB)'],
                    ['key' => 'taxable', 'label' => 'Taxable Income (ETB)'], ['key' => 'income_tax', 'label' => 'Tax Withheld (ETB)'],
                ],
                'effective_from' => null,
            ]
            : [
                'agency' => 'Pension agency',
                'title' => 'Pension Contribution Schedule',
                'note' => $note,
                'columns' => [
                    ['key' => 'no', 'label' => 'No'], ['key' => 'tin', 'label' => 'Employee TIN'],
                    ['key' => 'employee_name', 'label' => 'Employee Name'], ['key' => 'employee_code', 'label' => 'Employee Code'],
                    ['key' => 'pensionable', 'label' => 'Pensionable Salary (ETB)'],
                    ['key' => 'employee_pension', 'label' => 'Employee {employee_rate}% (ETB)'],
                    ['key' => 'employer_pension', 'label' => 'Employer {employer_rate}% (ETB)'],
                    ['key' => 'total_pension', 'label' => 'Total {total_rate}% (ETB)'],
                ],
                'effective_from' => null,
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
