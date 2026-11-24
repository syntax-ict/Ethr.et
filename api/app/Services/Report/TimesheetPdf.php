<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\TimesheetCode;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Calendar\EthiopianCalendar;
use App\Support\Letterhead;
use App\Support\PdfPages;
use App\Support\ReaderDates;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;

/**
 * The monthly timesheet on paper: A4 landscape, in the reader's language and
 * calendar, with the title in English and Amharic, the grid, the summary, the
 * totals, the legend, the rules applied and room to sign.
 *
 * It prints what `TimesheetReport::grid()` returns, which is what the screen
 * shows, so the paper cannot say what the screen does not. A month of more
 * people than a request can lay out is refused with the way to narrow it,
 * rather than cut: a printed sheet that silently leaves people off is worse
 * than none. Excel and a queued file wait for Reports step 4.
 */
final class TimesheetPdf
{
    /** People on one printed timesheet; measured in BASELINE 25bb. */
    public const MAX_PEOPLE = 50;

    /** Print-safe tints: a code is a letter first, a tint second. */
    private const TONES = [
        'present' => '#D1FAE5',
        'late' => '#FEF3C7',
        'early_leave' => '#FEF3C7',
        'missing_punch' => '#FDE68A',
        'absent' => '#FEE2E2',
        'annual_leave' => '#DBEAFE',
        'sick_leave' => '#DBEAFE',
        'maternity_leave' => '#DBEAFE',
        'other_leave' => '#DBEAFE',
        'field_duty' => '#DBEAFE',
        'half_day_leave' => '#DBEAFE',
        'holiday' => '#E2E8F0',
        'rest_day' => '#F1F5F9',
        'not_employed' => '#FFFFFF',
    ];

    /** The summary columns, in reading order; `payable_days` joins them only for a viewer who may see pay. */
    private const SUMMARY = [
        'work_days', 'present_days', 'late_days', 'absent_days', 'leave_days', 'holiday_days', 'rest_days',
        'missing_days', 'early_days', 'late_minutes', 'early_minutes', 'scheduled_hours', 'worked_hours', 'short_hours',
    ];

    public function __construct(
        private readonly TimesheetReport $report,
        private readonly EthiopianCalendar $calendar,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function render(Tenant $tenant, User $viewer, array $filters, ReaderDates $reader, bool $withPay): string
    {
        File::ensureDirectoryExists(storage_path('fonts'));

        $pdf = Pdf::loadView('reports.timesheet', $this->data($tenant, $viewer, $filters, $reader, $withPay));
        $pdf->setOption('isFontSubsettingEnabled', true);
        $pdf->setPaper('a4', 'landscape');

        return PdfPages::render($pdf);
    }

    /**
     * What the view prints. Public so a test can read the words without
     * unpicking a PDF.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function data(Tenant $tenant, User $viewer, array $filters, ReaderDates $reader, bool $withPay): array
    {
        $grid = $this->report->grid($tenant, $viewer, $filters, 1, self::MAX_PEOPLE, $withPay);
        $locale = $reader->locale;

        if (($grid['total'] ?? 0) > self::MAX_PEOPLE) {
            throw ValidationException::withMessages([
                'department' => (string) __('report.timesheet.too_many_for_pdf', ['max' => self::MAX_PEOPLE], $locale),
            ]);
        }

        $t = fn (string $key, array $replace = []): string => (string) __('report.timesheet.'.$key, $replace, $locale);

        $days = array_map(fn (array $day): array => [
            'date' => $day['date'],
            'number' => $this->dayNumber($day['date'], $reader),
            'weekend' => $day['iso_weekday'] >= 6,
        ], $grid['days'] ?? []);

        $columns = array_map(fn (string $key): array => ['key' => $key, 'label' => self::label($key, $locale)], self::SUMMARY);
        if ($withPay) {
            array_splice($columns, 7, 0, [['key' => 'payable_days', 'label' => $t('payable_days')]]);
        }
        $rates = $grid['rate_columns'] ?? [];
        foreach ($rates as $rate) {
            $columns[] = ['key' => 'rate:'.$rate['rate'], 'label' => $t('overtime_rate', ['rate' => $rate['rate']])];
        }
        $columns[] = ['key' => 'overtime_unapproved_hours', 'label' => self::label('overtime_unapproved_hours', $locale)];
        $columns[] = ['key' => 'overtime_limit_days', 'label' => self::label('overtime_limit_days', $locale)];

        $rows = [];
        foreach (($grid['data'] ?? []) as $index => $row) {
            $employee = $row['employee'];
            $rows[] = [
                'no' => $index + 1,
                'code' => $employee->employee_code,
                'name' => $employee->name,
                'name_am' => $employee->name_am,
                'department' => $employee->department?->name,
                'position' => $employee->position?->title,
                'shift' => $row['shift']?->name,
                'device' => $employee->badge_number,
                'cells' => array_values(array_map(fn (array $cell): array => [
                    'short' => $cell['code']?->short() ?? '·',
                    'tone' => $cell['code'] === null ? '#FFFFFF' : (self::TONES[$cell['code']->value] ?? '#FFFFFF'),
                    'locked' => $cell['locked'],
                ], $row['cells'])),
                'figures' => $this->figures($row['totals'], $columns, $rates),
                'check_ok' => $row['check_ok'],
            ];
        }

        $totals = $grid['totals'] ?? [];

        return [
            'locale' => $locale,
            'letterhead' => Letterhead::for($tenant, $locale),
            'titles' => [
                'en' => (string) __('report.timesheet.title', [], 'en'),
                'am' => (string) __('report.timesheet.title', [], 'am'),
            ],
            'period' => (string) __('report.period_range', [
                'from' => $reader->date($grid['period']['from'] ?? null),
                'to' => $reader->date($grid['period']['to'] ?? null),
            ], $locale),
            'generated' => $t('generated_by', ['date' => $reader->dateTime(now()), 'user' => $viewer->name]),
            'status' => isset($grid['status']['code'])
                ? (string) __('report.timesheet.status.'.$grid['status']['code'], [], $locale)
                  .($grid['status']['code'] === 'exceptions' ? ' · '.$grid['status']['blocking'] : '')
                : null,
            'filters' => $this->filterLines($tenant, $filters, $locale),
            'days' => $days,
            'columns' => $columns,
            'rows' => $rows,
            'totals' => $this->figures($totals, $columns, $rates),
            'departments' => array_map(fn (array $group): array => [
                'name' => $group['department'] === null ? $t('no_department') : $group['department']->name,
                'employees' => $group['employees'],
                'figures' => $this->figures($group['totals'], $columns, $rates),
            ], $grid['department_subtotals'] ?? []),
            'people' => $grid['total'] ?? 0,
            'legend' => array_map(fn (TimesheetCode $code): array => [
                'short' => $code->short(),
                'label' => $t('code.'.$code->value),
                'tone' => self::TONES[$code->value],
            ], [
                TimesheetCode::PRESENT, TimesheetCode::LATE, TimesheetCode::EARLY_LEAVE, TimesheetCode::MISSING_PUNCH,
                TimesheetCode::ABSENT, TimesheetCode::ANNUAL_LEAVE, TimesheetCode::SICK_LEAVE, TimesheetCode::MATERNITY_LEAVE,
                TimesheetCode::OTHER_LEAVE, TimesheetCode::FIELD_DUTY, TimesheetCode::HALF_DAY_LEAVE, TimesheetCode::HOLIDAY,
                TimesheetCode::REST_DAY,
            ]),
            'rules' => $this->rules($grid['rules_applied'] ?? [], $reader, $withPay),
            'signatures' => [
                (string) __('report.signatures.prepared', [], $locale),
                (string) __('report.signatures.checked', [], $locale),
                (string) __('report.signatures.approved', [], $locale),
            ],
            'stamp' => (string) __('report.signatures.stamp', [], $locale),
            'labels' => [
                'no' => $t('no'), 'id' => $t('id'), 'name' => $t('name'), 'department' => $t('department'),
                'position' => $t('position'), 'shift' => $t('shift'),
                'device' => $t('device'), 'status' => $t('status_line'), 'filters' => $t('filters'),
                'days' => $t('section_days'), 'summary' => $t('section_summary'), 'legend' => $t('legend'),
                'rules' => $t('rules'), 'totals' => $t('totals'), 'grand_total' => $t('grand_total'),
                'people' => $t('people_short'), 'check' => $t('check_failed'),
            ],
        ];
    }

    /**
     * One row's figures in the order of `$columns`, as the page prints them.
     *
     * @param  array<string, mixed>  $totals
     * @param  list<array{key: string, label: string}>  $columns
     * @param  list<array{rate: float, types: list<string>}>  $rates
     * @return list<string>
     */
    private function figures(array $totals, array $columns, array $rates): array
    {
        $byRate = (array) ($totals['overtime_by_rate'] ?? []);
        $out = [];

        foreach ($columns as $column) {
            if (str_starts_with($column['key'], 'rate:')) {
                $position = array_search((float) substr($column['key'], 5), array_column($rates, 'rate'), true);
                $value = $position === false ? 0 : ($byRate[$position] ?? 0);
            } else {
                $value = $totals[$column['key']] ?? 0;
            }
            $out[] = is_float($value) || is_int($value) ? rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.') : (string) $value;
        }

        return $out;
    }

    private function dayNumber(string $date, ReaderDates $reader): int
    {
        $day = Carbon::parse($date);

        return $reader->calendar === 'ethiopian'
            ? $this->calendar->gregorianToEthiopian($day)['day']
            : $day->day;
    }

    /**
     * The filters this printout was run with, in words.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function filterLines(Tenant $tenant, array $filters, string $locale): array
    {
        $lines = [];

        foreach ([
            'department' => [Department::class, 'department'],
            'branch' => [Branch::class, 'branch'],
            'shift' => [Shift::class, 'shift'],
        ] as $key => [$model, $label]) {
            $id = $filters[$key] ?? null;
            if (is_string($id) && $id !== '') {
                $name = $model::query()->where('tenant_id', $tenant->id)->where('public_id', $id)->value('name');
                if ($name !== null) {
                    $lines[] = ($label === 'shift' ? (string) __('report.timesheet.shift', [], $locale) : self::label($label, $locale)).': '.$name;
                }
            }
        }

        $status = $filters['employment_status'] ?? null;
        if (is_string($status) && $status !== '') {
            $lines[] = (string) __('report.timesheet.status_line', [], $locale).': '.$status;
        }

        return $lines;
    }

    /**
     * What the timesheet counted by, as sentences: from the rules the grid
     * says it applied, so the page and the screen cannot differ.
     *
     * @param  array<string, mixed>  $rules
     * @return list<string>
     */
    private function rules(array $rules, ReaderDates $reader, bool $withPay): array
    {
        $locale = $reader->locale;
        $t = fn (string $key, array $replace = []): string => (string) __('report.timesheet.'.$key, $replace, $locale);
        $out = [];

        foreach ((array) ($rules['shifts'] ?? []) as $shift) {
            $out[] = $t('rule_shift', [
                'name' => $locale === 'am' && ! empty($shift['name_am']) ? $shift['name_am'] : $shift['name'],
                'start' => $reader->timeOfDay($shift['start_time']),
                'end' => $reader->timeOfDay($shift['end_time']),
                'grace' => $shift['grace_minutes'],
                'early' => $shift['early_departure_minutes'],
                'break' => $shift['break_minutes'],
            ]);
        }

        $out[] = $t('rule_pairing', ['minutes' => (int) ($rules['merge_window_minutes'] ?? 5)]);
        $out[] = $t('rule_night');

        $auto = (array) ($rules['auto_checkout'] ?? []);
        if (! empty($auto['enabled'])) {
            $out[] = $t('rule_auto_checkout', ['minutes' => (int) ($auto['after_minutes'] ?? 0)]);
        }

        $overtime = (array) ($rules['overtime'] ?? []);
        $window = (array) ($overtime['night_window'] ?? []);
        $out[] = $t('rule_overtime', [
            'start' => $window['start_hour'] ?? 22,
            'end' => $window['end_hour'] ?? 6,
            'daily' => ($overtime['legal_daily_limit_minutes'] ?? 240) / 60,
            'weekly' => ($overtime['legal_weekly_limit_minutes'] ?? 720) / 60,
        ]);
        $out[] = ! empty($overtime['requires_approval']) ? $t('rule_approval_on') : $t('rule_approval_off');
        $out[] = $t('rule_absence');
        if ($withPay) {
            $out[] = $t('payable_note');
        }

        return $out;
    }

    /** A column's name in the reader's language, as every report file prints it. */
    private static function label(string $field, string $locale, ?string $fallback = null): string
    {
        $key = 'report.fields.'.$field;

        return Lang::has($key, $locale)
            ? (string) __($key, [], $locale)
            : ($fallback ?? ucfirst(str_replace('_', ' ', $field)));
    }
}
