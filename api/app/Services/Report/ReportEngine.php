<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Enums\AttendanceStatus;
use App\Enums\ExpectedDay;
use App\Enums\OrgScope;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\PayrollEntry;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\ExpectedDays;
use App\Services\Leave\LeaveYear;
use App\Services\Payroll\RunRegisterService;
use App\Support\Csv;
use App\Support\ReaderDates;
use App\Support\RequestBudget;
use App\Support\TenantTime;
use App\Support\XlsxDate;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Lang;

final class ReportEngine
{
    /**
     * The most rows an attendance or payroll report returns. Those two sources
     * grow without bound; the newest rows are kept, and `truncated` says so.
     */
    public const ROW_LIMIT = 1000;

    /** Set by a query that hit ROW_LIMIT; reset by every generate(). */
    private bool $truncated = false;

    /** Who the rows are written for; set by every generate(). */
    private ?ReaderDates $reader = null;

    /** Rows matching before ROW_LIMIT cut them, or null for a source that is never cut; reset by every generate(). */
    private ?int $matched = null;

    /** Counts and sums for a grouped report, or null when ungrouped; reset by every generate(). */
    private ?GroupTally $tally = null;

    /** Whether a query already fed every matching row to the tally, not only those it returned. */
    private bool $tallied = false;

    /** False when the pass over every matching row stopped at the request's deadline. */
    private bool $complete = true;

    /** The dates a source applied, when it takes dates; reset by every generate(). */
    private ?array $period = null;

    /**
     * Each source's columns and the `filters` keys it applies. `department`,
     * `branch` and `cost_center` take the unit's `public_id`; `from` and `to`
     * are dates and `month` is `YYYY-MM`. Any other filter key is ignored.
     */
    private const SOURCES = [
        'employees' => [
            'label' => 'Employees',
            'fields' => ['name', 'email', 'phone', 'employee_code', 'gender', 'status', 'hire_date', 'salary_cents', 'department', 'branch', 'position', 'grade', 'cost_center'],
            'filters' => ['status', 'department', 'branch', 'cost_center'],
        ],
        'attendance' => [
            'label' => 'Attendance',
            'fields' => ['employee_name', 'employee_code', 'department', 'branch', 'date', 'check_in', 'check_out', 'status', 'source', 'worked_minutes', 'overtime_minutes'],
            'filters' => ['period', 'from', 'to', 'status', 'department', 'branch'],
        ],
        'timesheet' => [
            'label' => 'Monthly timesheet',
            'fields' => ['employee_name', 'employee_code', 'department', 'branch', 'work_days', 'present_days', 'late_days', 'absent_days', 'leave_days', 'holiday_days', 'worked_hours', 'overtime_hours', 'rest_days', 'missing_days', 'early_days', 'late_minutes', 'early_minutes', 'lateness_deduct_minutes', 'scheduled_hours', 'short_hours', 'overtime_normal_hours', 'overtime_night_hours', 'overtime_rest_day_hours', 'overtime_holiday_hours', 'overtime_holiday_night_hours', 'overtime_unapproved_hours', 'overtime_limit_days'],
            'filters' => ['period', 'from', 'to', 'department', 'branch'],
        ],
        'absences' => [
            'label' => 'Absences',
            'fields' => ['employee_name', 'employee_code', 'department', 'branch', 'date'],
            'filters' => ['period', 'from', 'to', 'department', 'branch'],
        ],
        'leave' => [
            'label' => 'Leave Balances',
            'fields' => ['employee_name', 'employee_code', 'department', 'leave_type', 'year', 'entitled_days', 'used_days', 'remaining_days'],
            'filters' => ['year', 'department', 'branch'],
        ],
        'payroll' => [
            'label' => 'Payroll',
            'fields' => ['employee_name', 'employee_code', 'department', 'branch', 'cost_center', 'period', 'basic_salary_cents', 'overtime_cents', 'allowances_cents', 'gross_cents', 'income_tax_cents', 'employee_pension_cents', 'employer_pension_cents', 'loan_cents', 'cost_sharing_cents', 'net_cents'],
            'filters' => ['period', 'from', 'to', 'department', 'branch', 'cost_center'],
        ],
    ];

    /** The sources that take `from` and `to`, and so a `period`. */
    private const DATED_SOURCES = ['attendance', 'timesheet', 'absences', 'payroll'];

    /** Date-only fields, written in the reader's calendar in files. */
    private const DATE_FIELDS = ['hire_date', 'date'];

    /** Instants, written in the organisation's zone in files. */
    private const TIME_FIELDS = ['check_in', 'check_out'];

    public function __construct(private readonly RunRegisterService $register) {}

    public function sources(): array
    {
        return self::SOURCES;
    }

    /**
     * Whether `$viewer` may run a report on `$source` at all. The payroll
     * source is every payslip figure, so it needs what the payroll screens
     * need.
     */
    public function permits(User $viewer, string $source): bool
    {
        return $viewer->hasPermission('report.generate')
            && ($source !== 'payroll' || $viewer->hasPermission('payroll.viewAll'));
    }

    /**
     * Rows are the source's column map. `summary` is empty when ungrouped, else
     * per-group row counts and, when any summed column is present, per-group
     * sums. Stated for the API contract, which cannot follow maps built by key.
     *
     * `truncated` is true when the source had more rows than ROW_LIMIT and only
     * the newest were returned; `matched` is how many there were. A grouped
     * report counts and sums every matching row, not only those returned:
     * `summary.complete` is false when the request's time ran out first, and
     * the totals then cover the rows read up to that point. `period` is the
     * dates the report applied, from a `filters.period` key or from `from` and
     * `to`, for the sources that take dates. A file or an email needs no
     * totals: with `$withSummary` false the summary is empty and no row beyond
     * the cut is read, though `matched` still counts them.
     *
     * Rows are limited to what `$viewer` may see, exactly as the screens limit
     * them: their organisation scope, no `salary_cents` without
     * employee.viewFinancial, and no payroll rows without payroll.viewAll.
     * Until 2026-10-20 the engine read the whole tenant for anyone holding
     * report.generate, so an HR Admin, deliberately denied payroll.viewAll
     * (audit N95), could pull every payslip figure here (blueprint D6).
     *
     * @scramble-return array{source: string, total: int, matched: int, truncated: bool, period: array{from: string, to: string}|null, data: list<array<string, mixed>>, summary: array{grouped_by?: string, groups?: array<string, int>, group_sums?: array<string, array<string, int|float>>, complete?: bool}}
     */
    public function generate(int $tenantId, array $config, User $viewer, ?ReaderDates $reader = null, bool $withSummary = true): array
    {
        $this->reader = $reader ?? ReaderDates::for($viewer);
        $source = $config['source'] ?? 'employees';
        $columns = $config['columns'] ?? [];
        $filters = $config['filters'] ?? [];
        $groupBy = $config['group_by'] ?? null;
        $sortBy = $config['sort_by'] ?? null;
        $sortDir = $config['sort_dir'] ?? 'asc';
        $this->truncated = false;
        $this->matched = null;
        $this->tallied = false;
        $this->complete = true;
        $this->period = null;
        $this->tally = $groupBy && $withSummary ? new GroupTally((string) $groupBy, $columns) : null;

        $filters = $this->withPeriod($source, $filters, $tenantId);

        $data = match ($source) {
            'employees' => $this->queryEmployees($tenantId, $filters, $viewer),
            'attendance' => $this->queryAttendance($tenantId, $filters, $viewer),
            'timesheet' => $this->queryTimesheet($tenantId, $filters, $viewer),
            'absences' => $this->queryAbsences($tenantId, $filters, $viewer),
            'leave' => $this->queryLeave($tenantId, $filters, $viewer),
            'payroll' => $this->permits($viewer, 'payroll') ? $this->queryPayroll($tenantId, $filters, $viewer) : [],
            default => [],
        };

        // Sort and group on the full rows, and only then cut them down to the
        // selected columns. Cutting first meant grouping or sorting by a field
        // that was not selected found it missing on every row: one "Unknown"
        // group, and a sort that did nothing (audit N64).
        $project = fn (array $row): array => empty($columns)
            ? $row
            : array_intersect_key($row, array_flip($columns));

        if ($sortBy && ! empty($data)) {
            usort($data, function ($a, $b) use ($sortBy, $sortDir) {
                $valA = $a[$sortBy] ?? '';
                $valB = $b[$sortBy] ?? '';
                $cmp = $valA <=> $valB;

                return $sortDir === 'desc' ? -$cmp : $cmp;
            });
        }

        $summary = [];
        if ($this->tally !== null && ! empty($data)) {
            // A source that returns every row is tallied from them; one that
            // was cut has already tallied them all, and the groups keep the
            // order the returned rows first show them.
            $order = [];
            foreach ($data as $row) {
                $order[(string) ($row[$groupBy] ?? 'Unknown')] = true;
                if ($this->returnedRowsNeedTallying()) {
                    $this->tally->add($row);
                }
            }
            $order = array_keys($order);
            $summary = ['grouped_by' => $groupBy, 'groups' => $this->tally->groups($order)];
            if (($sums = $this->tally->sums($order)) !== []) {
                $summary['group_sums'] = $sums;
            }
            $summary['complete'] = $this->complete;
        }

        $matched = $this->matched ?? count($data);
        $data = array_map($project, $data);

        return [
            'source' => $source,
            'total' => count($data),
            'matched' => max($matched, count($data)),
            'truncated' => $this->truncated,
            'period' => $this->period,
            'data' => $data,
            'summary' => $summary,
        ];
    }

    /** Whether the query left the tally to the rows it returned, as one that was not cut does. */
    private function returnedRowsNeedTallying(): bool
    {
        return ! $this->tallied;
    }

    /**
     * `filters.period`, resolved today, in place of `from` and `to` for the
     * sources that take dates. The saved report keeps the key and every run
     * reads its own dates.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function withPeriod(string $source, array $filters, int $tenantId): array
    {
        $key = $filters['period'] ?? null;
        if (! in_array($source, self::DATED_SOURCES, true) || ! is_string($key) || ! in_array($key, ReportPeriod::KEYS, true)) {
            return $filters;
        }
        // A timesheet covers a month at most; a longer period would be cut to
        // its first 31 days. Saved configs reach here without the request.
        if ($source === 'timesheet' && ! in_array($key, ReportPeriod::SHORT_KEYS, true)) {
            return $filters;
        }

        [$from, $to] = ReportPeriod::resolve($key, Tenant::query()->find($tenantId));
        $filters['from'] = $from->format('Y-m-d');
        $filters['to'] = $to->format('Y-m-d');

        return $filters;
    }

    /**
     * Shared CSV builder — used by both the on-demand export endpoint and
     * scheduled report delivery, so the two paths can't drift into producing
     * different files for the same config (the bug class the bank-export and
     * OpenAPI-drift fixes elsewhere in this project both turned out to be).
     *
     * A `$note` (a report that was cut says so) ends the file after an empty
     * line, in one cell, so the file says what it holds.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function toCsv(array $rows, ?ReaderDates $reader = null, ?string $note = null): string
    {
        if ($rows === []) {
            return '';
        }

        $rows = $this->presentable($rows, $reader, spreadsheet: true);
        $lines = [Csv::row(array_keys($rows[0]))];
        foreach ($rows as $row) {
            $lines[] = Csv::row($row);
        }
        if ($note !== null && $note !== '') {
            $lines[] = '';
            $lines[] = Csv::row([$note]);
        }

        return implode("\r\n", $lines);
    }

    /**
     * What a file or an email says when the report was cut: how many rows it
     * shows of how many matched. Null when it was not.
     *
     * @param  array{total?: int, matched?: int, truncated?: bool}  $result
     */
    public function truncationNote(array $result, ReaderDates $reader): ?string
    {
        if (! ($result['truncated'] ?? false)) {
            return null;
        }

        if ((int) ($result['matched'] ?? 0) <= (int) ($result['total'] ?? 0)) {
            return (string) __('report.truncated_note_unknown', ['shown' => number_format((int) ($result['total'] ?? 0))], $reader->locale);
        }

        return (string) __('report.truncated_note', [
            'shown' => number_format((int) ($result['total'] ?? 0)),
            'matched' => number_format((int) ($result['matched'] ?? 0)),
        ], $reader->locale);
    }

    /**
     * The dates a report covers, in the reader's calendar, or null when it
     * covers no stated span.
     *
     * @param  array{period?: array{from: string, to: string}|null}  $result
     */
    public function periodText(array $result, ReaderDates $reader): ?string
    {
        $period = $result['period'] ?? null;
        if (! is_array($period)) {
            return null;
        }

        return (string) __('report.period_range', [
            'from' => $reader->date($period['from']),
            'to' => $reader->date($period['to']),
        ], $reader->locale);
    }

    /**
     * Rows as a person reads them: headings in their language for keys and
     * birr for cents, as the Reports page shows them. For files only; the
     * JSON keeps raw values.
     *
     * Dates follow the reader's calendar and times their clock, in the
     * organisation's zone. A spreadsheet keeps what sorts: an ISO date, with
     * the Ethiopian one in the next column for a reader on that calendar, and
     * a local 24-hour time. Check-in and check-out were raw UTC instants.
     * `$numeric` leaves birr as a number, for a workbook, not as grouped text.
     * `$realDates` makes the Gregorian date and the check-in and check-out
     * columns real Excel dates, for a workbook only; the Ethiopian column
     * stays text, as Excel has no such calendar.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function presentable(array $rows, ?ReaderDates $reader = null, bool $spreadsheet = false, bool $numeric = false, bool $realDates = false): array
    {
        $reader ??= ReaderDates::forTenant(null);

        return array_values(array_map(function (array $row) use ($reader, $spreadsheet, $numeric, $realDates): array {
            $day = is_string($row['date'] ?? null) ? $row['date'] : null;
            $out = [];
            foreach ($row as $field => $value) {
                $field = (string) $field;
                $label = self::label($field, $reader->locale);
                $text = is_string($value) && $value !== '' ? $value : null;

                if (str_ends_with($field, '_cents') && is_numeric($value)) {
                    // A workbook keeps birr as a number a column can add up.
                    $out[$label] = $numeric ? round(((int) $value) / 100, 2) : number_format(((int) $value) / 100, 2);
                } elseif (in_array($field, self::DATE_FIELDS, true)) {
                    $out[$label] = match (true) {
                        $spreadsheet && $realDates && $text !== null => XlsxDate::fromDate($text),
                        $spreadsheet => $text ?? '',
                        default => $text === null ? '' : $reader->date($text),
                    };
                    if ($spreadsheet && $reader->calendar === 'ethiopian') {
                        $out[(string) __('report.ethiopian_column', ['label' => $label], $reader->locale)] = $text === null ? '' : $reader->date($text);
                    }
                } elseif (in_array($field, self::TIME_FIELDS, true)) {
                    $local = $text === null ? '' : $reader->localStamp($text);
                    $out[$label] = match (true) {
                        $text === null => '',
                        $spreadsheet && $realDates => XlsxDate::fromStamp($local),
                        $spreadsheet => $local,
                        // A check-out after midnight says which day it fell on.
                        $day !== null && str_starts_with($local, $day) => $reader->time($text),
                        default => $reader->dateTime($text),
                    };
                } else {
                    $out[$label] = $value;
                }
            }

            return $out;
        }, $rows));
    }

    private static function label(string $field, string $locale): string
    {
        $key = 'report.fields.'.$field;

        return Lang::has($key, $locale) ? (string) __($key, [], $locale) : ucfirst(str_replace('_', ' ', $field));
    }

    private function queryEmployees(int $tenantId, array $filters, User $viewer): array
    {
        $query = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            // `positions` has no `name` column (it is `title`). Naming it here both
            // errors on a real driver and fails to load the column the mapping
            // below actually reads, so the eager load must select `title` too.
            ->with(['department:id,name', 'branch:id,name', 'position:id,title', 'grade:id,name', 'costCenter:id,name']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $this->filterUnits($query, $filters);
        $viewer->scopeAccessibleEmployees($query);
        $showPay = $viewer->hasPermission('employee.viewFinancial');

        return $query->get()->map(fn ($e) => array_filter([
            'name' => $e->name,
            'email' => $e->email,
            'phone' => $e->phone,
            'employee_code' => $e->employee_code,
            'gender' => $e->gender,
            'status' => $e->status->value,
            'hire_date' => $e->hire_date?->format('Y-m-d'),
            'salary_cents' => $e->salary_cents,
            'department' => $e->department?->name ?? '',
            'branch' => $e->branch?->name ?? '',
            // `positions.title`, not `name` — this column was empty in every export.
            'position' => $e->position?->title ?? '',
            'grade' => $e->grade->name ?? '',
            'cost_center' => $e->costCenter->name ?? '',
        ], fn (string $field) => $showPay || $field !== 'salary_cents', ARRAY_FILTER_USE_KEY))->toArray();
    }

    private function queryAttendance(int $tenantId, array $filters, User $viewer): array
    {
        $query = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->with([
                'employee:id,name,employee_code,department_id,branch_id',
                'employee.department:id,name',
                'employee.branch:id,name',
                'shift',
            ]);
        $this->limitToReach($query, $tenantId, $viewer);
        $this->limitToUnits($query, $tenantId, $filters);

        if (! empty($filters['from'])) {
            $query->whereDate('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('date', '<=', $filters['to']);
        }
        if (! empty($filters['status']) && is_string($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['from']) && ! empty($filters['to'])) {
            $this->period = ['from' => (string) $filters['from'], 'to' => (string) $filters['to']];
        }

        // Newest first, so a limited report keeps the recent months. It took
        // the first 1,000 in no order, which was the oldest, and said nothing
        // about the cut (audit N65).
        return $this->limited($query, ['date', 'id'], $this->attendanceRow(...));
    }

    /** @return array<string, mixed> */
    private function attendanceRow(AttendanceRecord $r): array
    {
        return [
            'employee_name' => $r->employee?->name,
            'employee_code' => $r->employee?->employee_code,
            'department' => $r->employee?->department->name ?? '',
            'branch' => $r->employee?->branch->name ?? '',
            'date' => $r->date instanceof Carbon ? $r->date->format('Y-m-d') : $r->date,
            'check_in' => $r->check_in?->toIso8601String(),
            'check_out' => $r->check_out?->toIso8601String(),
            'status' => $r->status->value,
            'source' => $r->source->value,
            // From the punches and the shift's break. This read a
            // `worked_minutes` attribute that no column or accessor provides,
            // so every row said 0.
            'worked_minutes' => $r->workedMinutes(),
            'overtime_minutes' => $r->overtimeMinutes(),
        ];
    }

    /**
     * One row per employee for a month: workdays, days present, late, absent,
     * on leave and on holiday, with the hours worked and scheduled, overtime
     * by rate, and the late and early minutes. Every figure is the
     * TimesheetLedger's, which reads the functions payroll reads (ExpectedDays,
     * the engine that rated each punch, the overtime payroll pays, the same
     * absence guard), so the report cannot show a number payroll will not pay.
     *
     * `from` and `to` choose the days, at most TIMESHEET_MAX_DAYS of them, so
     * the month can be a Gregorian one or an Ethiopian one (Tikimt runs from
     * 11 October to 9 November): the Reports page sends the bounds of the
     * month in the viewer's calendar. `month` (`YYYY-MM`) still works for API
     * callers and saved reports. With neither, the month of yesterday. The
     * span stops at yesterday, since today is not over. An organisation that
     * recorded no attendance in the span is not using attendance, so its
     * absent days are 0 rather than every workday, as on the payroll run.
     *
     * The pay-moving figures (payable days, unpaid leave) are read one person
     * at a time and are on the grid, not here.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function queryTimesheet(int $tenantId, array $filters, User $viewer): array
    {
        $tenant = Tenant::query()->find($tenantId);
        $report = app(TimesheetReport::class);
        $yesterday = Carbon::parse(TenantTime::today($tenant)->subDay()->format('Y-m-d'));

        $span = $report->span($filters, $yesterday, self::TIMESHEET_MAX_DAYS, $yesterday);
        if ($span === null || $tenant === null) {
            return [];
        }
        [$from, $to] = $span;
        $this->period = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];

        $employees = $report->employees($tenantId, $filters, $viewer, $from, $to)
            ->with(['department:id,name', 'branch:id,name'])
            ->orderBy('name')
            ->get();

        // Past the limit the rows are the first people by name, and the report
        // says it was cut, as every source does at its own limit.
        $this->matched = $employees->count();
        if ($employees->count() > TimesheetReport::MAX_PEOPLE) {
            $this->truncated = true;
            $this->complete = false;
            $employees = $employees->take(TimesheetReport::MAX_PEOPLE)->values();
        }

        $rows = [];
        foreach ($report->rows($employees, $from, $to, $report->rules($tenant, $from), withPay: false) as $row) {
            $totals = $row['totals'];
            $attended = collect($row['cells'])->contains(fn (array $cell): bool => $cell['worked']);

            // Not employed in the month, or rest days only: nothing to report.
            if ($totals['scheduled_days'] === 0 && ! $attended && $totals['leave_days'] === 0) {
                continue;
            }

            $employee = $row['employee'];
            $rows[] = [
                'employee_name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'department' => $employee->department->name ?? '',
                'branch' => $employee->branch->name ?? '',
                ...$report->figures($totals, $row['limits']),
            ];
        }

        return $rows;
    }

    /** The longest span a timesheet covers: a Gregorian or an Ethiopian month. */
    public const TIMESHEET_MAX_DAYS = 31;

    /**
     * The longest span an absences report covers. Each day is worked out for
     * every employee in reach, a few queries a day, so the span is bounded
     * rather than the rows.
     */
    public const ABSENCE_MAX_DAYS = 92;

    /**
     * One row per workday missed with no attendance, leave or holiday on
     * record, by the same rule as the dashboards and the payroll run
     * (ExpectedDays). Attendance rows are only written for days someone
     * punched, so the attendance source cannot show an absence at all
     * (blueprint RP6).
     *
     * `from` and `to` default to the 30 days before today and are clipped to
     * yesterday, since today is not over, and to ABSENCE_MAX_DAYS. An
     * organisation that recorded no attendance in the span is not using
     * attendance and gets no rows, as on the payroll run, rather than every
     * workday of every person.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function queryAbsences(int $tenantId, array $filters, User $viewer): array
    {
        $yesterday = TenantTime::today(Tenant::query()->find($tenantId))->subDay()->format('Y-m-d');
        $to = Carbon::parse(min(empty($filters['to']) ? $yesterday : (string) $filters['to'], $yesterday));
        $from = empty($filters['from']) ? $to->copy()->subDays(29) : Carbon::parse((string) $filters['from']);
        if ($from->diffInDays($to) >= self::ABSENCE_MAX_DAYS) {
            $from = $to->copy()->subDays(self::ABSENCE_MAX_DAYS - 1);
        }
        if ($from->greaterThan($to)) {
            return [];
        }
        $this->period = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];

        $employeeQuery = Employee::query()->where('tenant_id', $tenantId);
        $this->filterUnits($employeeQuery, $filters);
        $viewer->scopeAccessibleEmployees($employeeQuery);
        $employees = $employeeQuery->with(['department:id,name', 'branch:id,name'])->get();

        // Scoped, not bypassed: ExpectedDays reads shifts and leave through
        // the tenant scope too, so this source needs the tenant resolved
        // (the request does it, and RunScheduledReportsJob sets it first).
        $records = AttendanceRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereDate('date', '>=', $from->format('Y-m-d'))
            ->whereDate('date', '<=', $to->format('Y-m-d'))
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->where(fn ($q) => $q->whereNotNull('check_in')->orWhereNotNull('check_out'))
            ->get(['employee_id', 'date']);

        if ($employees->isEmpty() || $records->isEmpty()) {
            return [];
        }

        $attended = [];
        foreach ($records as $record) {
            $attended[$record->date->format('Y-m-d')][$record->employee_id] = true;
        }

        $expectedDays = app(ExpectedDays::class);
        $byId = $employees->keyBy('id');
        $rows = [];

        // Newest first, as the attendance source orders, so a cut keeps the
        // recent days. With totals to give, the days past the cut are still
        // worked out and counted, until the request's deadline; with none,
        // the loop stops at the cut and no count is claimed.
        $matched = 0;
        $this->tallied = $this->tally !== null;
        for ($day = $to->copy(); $day->greaterThanOrEqualTo($from); $day = $day->copy()->subDay()) {
            if ($matched >= self::ROW_LIMIT && $this->pastDeadline()) {
                $this->complete = false;
                $this->truncated = true;

                return $rows;
            }
            $key = $day->format('Y-m-d');
            foreach ($expectedDays->onDate($employees, $day) as $id => $kind) {
                if ($kind !== ExpectedDay::WORKDAY || isset($attended[$key][$id])) {
                    continue;
                }
                if ($matched >= self::ROW_LIMIT) {
                    $this->truncated = true;
                    if ($this->tally === null) {
                        return $rows;
                    }
                }
                $employee = $byId[$id];
                $row = [
                    'employee_name' => $employee->name,
                    'employee_code' => $employee->employee_code,
                    'department' => $employee->department->name ?? '',
                    'branch' => $employee->branch->name ?? '',
                    'date' => $key,
                ];
                $matched++;
                $this->tally?->add($row);
                if ($matched <= self::ROW_LIMIT) {
                    $rows[] = $row;
                }
            }
        }
        $this->matched = $matched;

        return $rows;
    }

    private function queryLeave(int $tenantId, array $filters, User $viewer): array
    {
        $query = LeaveBalance::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->with(['employee:id,name,employee_code,department_id', 'employee.department:id,name', 'leaveType:id,name']);
        $this->limitToReach($query, $tenantId, $viewer);
        $this->limitToUnits($query, $tenantId, $filters);

        $year = $filters['year'] ?? LeaveYear::forTenantId($tenantId)->current();
        $query->where('year', $year);

        return $query->get()->map(fn ($b) => [
            'employee_name' => $b->employee?->name,
            'employee_code' => $b->employee?->employee_code,
            'department' => $b->employee?->department->name ?? '',
            'leave_type' => $b->leaveType?->name,
            'year' => $b->year,
            'entitled_days' => $b->entitled_days,
            'used_days' => $b->used_days,
            'remaining_days' => $b->remainingDays(),
        ])->toArray();
    }

    /**
     * One row per payslip, with the register's breakdown (overtime,
     * allowances, employer pension, loan and cost-sharing deductions) read
     * from the same calculation log. Voided runs are left out: a voided run
     * and its reprocessed replacement counted every payslip of the month
     * twice. `from` and `to` select the runs whose period's midpoint falls
     * between them, so a month picked in either calendar finds the run meant
     * for it, whether the organisation cuts periods on Gregorian or on
     * Ethiopian month boundaries (the rule `localizedPeriodLabel()` uses).
     */
    private function queryPayroll(int $tenantId, array $filters, User $viewer): array
    {
        $runs = PayrollRun::query()->where('tenant_id', $tenantId)->where('status', '!=', 'voided');
        if (! empty($filters['from']) || ! empty($filters['to'])) {
            $from = empty($filters['from']) ? null : Carbon::parse((string) $filters['from'])->startOfDay();
            $to = empty($filters['to']) ? null : Carbon::parse((string) $filters['to'])->startOfDay();
            $inRange = (clone $runs)
                ->when($to, fn ($q) => $q->whereDate('period_start', '<=', $to->format('Y-m-d')))
                ->when($from, fn ($q) => $q->whereDate('period_end', '>=', $from->format('Y-m-d')))
                ->get(['id', 'period_start', 'period_end'])
                ->filter(function (PayrollRun $run) use ($from, $to): bool {
                    $start = Carbon::parse($run->period_start->format('Y-m-d'));
                    $end = Carbon::parse(($run->period_end ?? $run->period_start)->format('Y-m-d'));
                    $midpoint = $start->copy()->addDays(intdiv((int) $start->diffInDays($end), 2));

                    return ($from === null || $midpoint->greaterThanOrEqualTo($from))
                        && ($to === null || $midpoint->lessThanOrEqualTo($to));
                })
                ->modelKeys();
            $runs->whereIn('id', $inRange === [] ? [0] : $inRange);
        }

        $query = PayrollEntry::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereIn('payroll_run_id', $runs->select('id'))
            ->with([
                'employee:id,public_id,name,employee_code,department_id,branch_id,cost_center_id',
                'employee.department:id,name',
                'employee.branch:id,name',
                'employee.costCenter:id,name',
                'payrollRun:id,period_label,period_start,period_end',
            ]);
        $this->limitToReach($query, $tenantId, $viewer);
        $this->limitToUnits($query, $tenantId, $filters);

        if (! empty($filters['from']) && ! empty($filters['to'])) {
            $this->period = ['from' => (string) $filters['from'], 'to' => (string) $filters['to']];
        }

        return $this->limited($query, ['payroll_run_id', 'id'], $this->payrollRow(...));
    }

    /** @return array<string, mixed> */
    private function payrollRow(PayrollEntry $e): array
    {
        $line = $this->register->row($e);

        return [
            'employee_name' => $e->employee?->name,
            'employee_code' => $e->employee?->employee_code,
            'department' => $e->employee?->department->name ?? '',
            'branch' => $e->employee?->branch->name ?? '',
            'cost_center' => $e->employee?->costCenter->name ?? '',
            // The month the run means, in the reader's calendar: the stored
            // label is English and Gregorian.
            'period' => $this->reader?->periodOf($e->payrollRun) ?? (string) $e->payrollRun?->period_label,
            'basic_salary_cents' => $e->basic_salary_cents,
            'overtime_cents' => $line['overtime_cents'],
            'allowances_cents' => $line['allowances_cents'],
            'gross_cents' => $e->gross_cents,
            'income_tax_cents' => $e->income_tax_cents,
            'employee_pension_cents' => $e->employee_pension_cents,
            'employer_pension_cents' => $e->employer_pension_cents,
            'loan_cents' => $line['loan_cents'],
            'cost_sharing_cents' => $line['cost_sharing_cents'],
            'net_cents' => $e->net_cents,
        ];
    }

    /**
     * Keep only rows about employees `$viewer` can reach, by the same rule
     * as every employee screen (ScopesEmployeeAccess). An organisation-wide
     * viewer adds no predicate.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function limitToReach(Builder $query, int $tenantId, User $viewer): void
    {
        if ($viewer->orgScope() === OrgScope::ALL) {
            return;
        }

        $query->whereIn(
            'employee_id',
            $viewer->scopeAccessibleEmployees(Employee::query()->where('tenant_id', $tenantId))->select('id'),
        );
    }

    /**
     * The department, branch and cost-centre filters, each a unit's
     * `public_id`, on an employee query.
     *
     * @param  Builder<Employee>  $query
     * @param  array<string, mixed>  $filters
     */
    private function filterUnits(Builder $query, array $filters): bool
    {
        return UnitFilter::apply($query, $filters);
    }

    /**
     * The unit filters for a source keyed by `employee_id`.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filters
     */
    private function limitToUnits(Builder $query, int $tenantId, array $filters): void
    {
        $employees = Employee::query()->where('tenant_id', $tenantId);

        if ($this->filterUnits($employees, $filters)) {
            $query->whereIn('employee_id', $employees->select('id'));
        }
    }

    /**
     * The newest ROW_LIMIT rows, mapped, noting in $truncated whether there
     * were more and in $matched how many. A grouped report then reads every
     * matching row once more, in id order and a chunk at a time, so its
     * totals are not the totals of the rows kept.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query  Unordered and unlimited.
     * @param  list<string>  $newestBy  Columns that put the newest row first, descending.
     * @param  Closure(TModel): array<string, mixed>  $map
     * @return list<array<string, mixed>>
     */
    private function limited(Builder $query, array $newestBy, Closure $map): array
    {
        $this->matched = (clone $query)->count();

        $ordered = clone $query;
        foreach ($newestBy as $column) {
            $ordered->orderByDesc($column);
        }
        $rows = $ordered->limit(self::ROW_LIMIT)->get()->map($map)->values()->all();

        if ($this->matched > self::ROW_LIMIT) {
            $this->truncated = true;
            if ($this->tally !== null) {
                $this->tallyEvery($query, $map);
            }
        }

        return $rows;
    }

    /** Whether the request's budget has run out; none on the command line or in a queued job. */
    private function pastDeadline(): bool
    {
        $deadline = RequestBudget::deadline();

        return $deadline !== null && microtime(true) >= $deadline;
    }

    /**
     * Every matching row into the tally, stopping at the request's deadline
     * (none on the command line or in a queued job).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): array<string, mixed>  $map
     */
    private function tallyEvery(Builder $query, Closure $map): void
    {
        $this->tallied = true;

        foreach ($query->lazyById(500) as $model) {
            if ($this->pastDeadline()) {
                $this->complete = false;

                return;
            }
            $this->tally?->add($map($model));
        }
    }
}
