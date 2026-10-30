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
use App\Services\Payroll\RunRegisterService;
use App\Support\Csv;
use App\Support\TenantTime;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ReportEngine
{
    /**
     * The most rows an attendance or payroll report returns. Those two sources
     * grow without bound; the newest rows are kept, and `truncated` says so.
     */
    public const ROW_LIMIT = 1000;

    /** Set by a query that hit ROW_LIMIT; reset by every generate(). */
    private bool $truncated = false;

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
            'filters' => ['from', 'to', 'status', 'department', 'branch'],
        ],
        'timesheet' => [
            'label' => 'Monthly timesheet',
            'fields' => ['employee_name', 'employee_code', 'department', 'branch', 'work_days', 'present_days', 'late_days', 'absent_days', 'leave_days', 'holiday_days', 'worked_hours', 'overtime_hours'],
            'filters' => ['from', 'to', 'department', 'branch'],
        ],
        'absences' => [
            'label' => 'Absences',
            'fields' => ['employee_name', 'employee_code', 'department', 'branch', 'date'],
            'filters' => ['from', 'to', 'department', 'branch'],
        ],
        'leave' => [
            'label' => 'Leave Balances',
            'fields' => ['employee_name', 'employee_code', 'department', 'leave_type', 'year', 'entitled_days', 'used_days', 'remaining_days'],
            'filters' => ['year', 'department', 'branch'],
        ],
        'payroll' => [
            'label' => 'Payroll',
            'fields' => ['employee_name', 'employee_code', 'department', 'branch', 'cost_center', 'period', 'basic_salary_cents', 'overtime_cents', 'allowances_cents', 'gross_cents', 'income_tax_cents', 'employee_pension_cents', 'employer_pension_cents', 'loan_cents', 'cost_sharing_cents', 'net_cents'],
            'filters' => ['from', 'to', 'department', 'branch', 'cost_center'],
        ],
    ];

    /**
     * Column headings for the files a person reads: the PDF, the CSV export
     * and scheduled deliveries. Those printed the API keys, `salary_cents`
     * over `1250000`; the Reports page has its own translated labels.
     */
    private const FIELD_LABELS = [
        'name' => 'Name',
        'email' => 'Email',
        'phone' => 'Phone',
        'employee_code' => 'Employee code',
        'gender' => 'Gender',
        'status' => 'Status',
        'hire_date' => 'Hire date',
        'salary_cents' => 'Salary (ETB)',
        'department' => 'Department',
        'branch' => 'Branch',
        'position' => 'Position',
        'grade' => 'Grade',
        'cost_center' => 'Cost centre',
        'employee_name' => 'Employee',
        'date' => 'Date',
        'check_in' => 'Check-in',
        'check_out' => 'Check-out',
        'source' => 'Source',
        'worked_minutes' => 'Worked (minutes)',
        'overtime_minutes' => 'Overtime (minutes)',
        'work_days' => 'Workdays',
        'present_days' => 'Present',
        'late_days' => 'Late',
        'absent_days' => 'Absent',
        'leave_days' => 'On leave',
        'holiday_days' => 'Holidays',
        'worked_hours' => 'Worked hours',
        'overtime_hours' => 'Overtime hours',
        'leave_type' => 'Leave type',
        'year' => 'Year',
        'entitled_days' => 'Entitled days',
        'used_days' => 'Used days',
        'remaining_days' => 'Remaining days',
        'period' => 'Period',
        'basic_salary_cents' => 'Basic salary (ETB)',
        'overtime_cents' => 'Overtime (ETB)',
        'allowances_cents' => 'Allowances (ETB)',
        'gross_cents' => 'Gross pay (ETB)',
        'income_tax_cents' => 'Income tax (ETB)',
        'employee_pension_cents' => 'Employee pension (ETB)',
        'employer_pension_cents' => 'Employer pension (ETB)',
        'loan_cents' => 'Loan (ETB)',
        'cost_sharing_cents' => 'Cost sharing (ETB)',
        'net_cents' => 'Net pay (ETB)',
    ];

    /** Columns summed per group: money, day counts and durations. */
    private const SUMMED_SUFFIXES = ['_cents', '_days', '_hours', '_minutes'];

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
     * the newest were returned.
     *
     * Rows are limited to what `$viewer` may see, exactly as the screens limit
     * them: their organisation scope, no `salary_cents` without
     * employee.viewFinancial, and no payroll rows without payroll.viewAll.
     * Until 2026-10-20 the engine read the whole tenant for anyone holding
     * report.generate, so an HR Admin, deliberately denied payroll.viewAll
     * (audit N95), could pull every payslip figure here (blueprint D6).
     *
     * @scramble-return array{source: string, total: int, truncated: bool, data: list<array<string, mixed>>, summary: array{grouped_by?: string, groups?: array<string, int>, group_sums?: array<string, array<string, int|float>>}}
     */
    public function generate(int $tenantId, array $config, User $viewer): array
    {
        $source = $config['source'] ?? 'employees';
        $columns = $config['columns'] ?? [];
        $filters = $config['filters'] ?? [];
        $groupBy = $config['group_by'] ?? null;
        $sortBy = $config['sort_by'] ?? null;
        $sortDir = $config['sort_dir'] ?? 'asc';
        $this->truncated = false;

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
        if ($groupBy && ! empty($data)) {
            $grouped = [];
            // Sums any `*_cents`, `*_days`, `*_hours` or `*_minutes` column
            // present in the (already column-filtered) rows per group — e.g.
            // grouping the payroll source by `period` gives the monthly
            // income-tax/pension totals a statutory filing needs, and grouping
            // the timesheet by department gives absent days per department,
            // without a source-specific code path. A count alone ("45 rows")
            // told a compliance officer nothing usable for a remittance filing.
            $sums = [];
            foreach ($data as $row) {
                $key = (string) ($row[$groupBy] ?? 'Unknown');
                $grouped[$key] = ($grouped[$key] ?? 0) + 1;

                // Sums cover the selected columns, as before. Hours are
                // fractional; everything else summed is a whole number.
                foreach ($project($row) as $field => $value) {
                    if (is_numeric($value) && $this->summed((string) $field)) {
                        $sums[$key][$field] = str_ends_with((string) $field, '_hours')
                            ? round(($sums[$key][$field] ?? 0) + (float) $value, 1)
                            : ($sums[$key][$field] ?? 0) + (int) $value;
                    }
                }
            }
            $summary = ['grouped_by' => $groupBy, 'groups' => $grouped];
            if ($sums !== []) {
                $summary['group_sums'] = $sums;
            }
        }

        $data = array_map($project, $data);

        return [
            'source' => $source,
            'total' => count($data),
            'truncated' => $this->truncated,
            'data' => $data,
            'summary' => $summary,
        ];
    }

    /**
     * Shared CSV builder — used by both the on-demand export endpoint and
     * scheduled report delivery, so the two paths can't drift into producing
     * different files for the same config (the bug class the bank-export and
     * OpenAPI-drift fixes elsewhere in this project both turned out to be).
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function toCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $rows = $this->presentable($rows);
        $lines = [Csv::row(array_keys($rows[0]))];
        foreach ($rows as $row) {
            $lines[] = Csv::row($row);
        }

        return implode("\r\n", $lines);
    }

    /**
     * Rows as a person reads them: headings for keys and birr for cents, as
     * the Reports page shows them. For files only; the JSON keeps raw values.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function presentable(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            $out = [];
            foreach ($row as $field => $value) {
                $field = (string) $field;
                $label = self::FIELD_LABELS[$field] ?? ucfirst(str_replace('_', ' ', $field));
                $out[$label] = str_ends_with($field, '_cents') && is_numeric($value)
                    ? number_format(((int) $value) / 100, 2)
                    : $value;
            }

            return $out;
        }, $rows));
    }

    private function summed(string $field): bool
    {
        foreach (self::SUMMED_SUFFIXES as $suffix) {
            if (str_ends_with($field, $suffix)) {
                return true;
            }
        }

        return false;
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
        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
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

        // Newest first, so a limited report keeps the recent months. It took
        // the first 1,000 in no order, which was the oldest, and said nothing
        // about the cut (audit N65).
        $rows = $this->limited($query->orderByDesc('date')->orderByDesc('id'));

        return $rows->map(fn (AttendanceRecord $r) => [
            'employee_name' => $r->employee?->name,
            'employee_code' => $r->employee?->employee_code,
            'department' => $r->employee?->department->name ?? '',
            'branch' => $r->employee?->branch->name ?? '',
            'date' => $r->date instanceof Carbon ? $r->date->format('Y-m-d') : $r->date,
            'check_in' => $r->check_in,
            'check_out' => $r->check_out,
            'status' => $r->status->value,
            'source' => $r->source->value,
            // From the punches and the shift's break. This read a
            // `worked_minutes` attribute that no column or accessor provides,
            // so every row said 0.
            'worked_minutes' => $r->workedMinutes(),
            'overtime_minutes' => $r->overtimeMinutes(),
        ])->toArray();
    }

    /**
     * One row per employee for a month: workdays, days present, late, absent,
     * on leave and on holiday, with hours worked and overtime. Days are
     * classified by ExpectedDays, the rule the absences source, the dashboards
     * and the payroll run use, so the totals agree with them.
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
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function queryTimesheet(int $tenantId, array $filters, User $viewer): array
    {
        $yesterday = Carbon::parse(TenantTime::today(Tenant::query()->find($tenantId))->subDay()->format('Y-m-d'));
        if (! empty($filters['from'])) {
            $from = Carbon::parse((string) $filters['from'])->startOfDay();
            $to = empty($filters['to']) ? $from->copy()->addDays(self::TIMESHEET_MAX_DAYS - 1) : Carbon::parse((string) $filters['to'])->startOfDay();
            if ($from->diffInDays($to) >= self::TIMESHEET_MAX_DAYS) {
                $to = $from->copy()->addDays(self::TIMESHEET_MAX_DAYS - 1);
            }
        } else {
            $month = is_string($filters['month'] ?? null) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $filters['month']) === 1
                ? Carbon::parse($filters['month'].'-01')
                : $yesterday->copy()->startOfMonth();
            $from = $month->copy()->startOfMonth();
            $to = $month->copy()->endOfMonth()->startOfDay();
        }
        if ($to->greaterThan($yesterday)) {
            $to = $yesterday->copy();
        }
        if ($from->greaterThan($to)) {
            return [];
        }

        $employeeQuery = Employee::query()->where('tenant_id', $tenantId);
        $this->filterUnits($employeeQuery, $filters);
        $viewer->scopeAccessibleEmployees($employeeQuery);
        $employees = $employeeQuery->with(['department:id,name', 'branch:id,name'])->orderBy('name')->get();
        if ($employees->isEmpty()) {
            return [];
        }

        // Scoped, not bypassed, as in the absences source: ExpectedDays reads
        // shifts and leave through the tenant scope too.
        $records = AttendanceRecord::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('employee_id', $employees->modelKeys())
            ->whereDate('date', '>=', $from->format('Y-m-d'))
            ->whereDate('date', '<=', $to->format('Y-m-d'))
            ->where('status', '!=', AttendanceStatus::VOIDED)
            ->with('shift')
            ->get();

        $attended = [];
        $lateOn = [];
        $totals = [];
        foreach ($records as $record) {
            $id = $record->employee_id;
            // A day, not a record: a person who punched late twice on one
            // day was counted late twice, more late days than days present.
            if ($record->status === AttendanceStatus::LATE) {
                $lateOn[$id][$record->date->format('Y-m-d')] = true;
            }
            $totals[$id]['worked'] = ($totals[$id]['worked'] ?? 0) + $record->workedMinutes();
            $totals[$id]['overtime'] = ($totals[$id]['overtime'] ?? 0) + $record->overtimeMinutes();
            if ($record->check_in !== null || $record->check_out !== null) {
                $attended[$id][$record->date->format('Y-m-d')] = true;
            }
        }

        $days = [];
        $expectedDays = app(ExpectedDays::class);
        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day = $day->copy()->addDay()) {
            $key = $day->format('Y-m-d');
            foreach ($expectedDays->onDate($employees, $day) as $id => $kind) {
                $counted = match ($kind) {
                    ExpectedDay::WORKDAY => 'work',
                    ExpectedDay::ON_LEAVE => 'leave',
                    ExpectedDay::HOLIDAY => 'holiday',
                    default => null,
                };
                if ($counted !== null) {
                    $days[$id][$counted] = ($days[$id][$counted] ?? 0) + 1;
                }
                if ($kind === ExpectedDay::WORKDAY && $records->isNotEmpty() && ! isset($attended[$id][$key])) {
                    $days[$id]['absent'] = ($days[$id]['absent'] ?? 0) + 1;
                }
            }
        }

        $rows = [];
        foreach ($employees as $employee) {
            $id = $employee->id;
            $present = count($attended[$id] ?? []);
            // Not employed in the month, or rest days only: nothing to report.
            if (($days[$id]['work'] ?? 0) === 0 && $present === 0 && ($days[$id]['leave'] ?? 0) === 0) {
                continue;
            }
            $rows[] = [
                'employee_name' => $employee->name,
                'employee_code' => $employee->employee_code,
                'department' => $employee->department->name ?? '',
                'branch' => $employee->branch->name ?? '',
                'work_days' => $days[$id]['work'] ?? 0,
                'present_days' => $present,
                'late_days' => count($lateOn[$id] ?? []),
                'absent_days' => $days[$id]['absent'] ?? 0,
                'leave_days' => $days[$id]['leave'] ?? 0,
                'holiday_days' => $days[$id]['holiday'] ?? 0,
                'worked_hours' => round(($totals[$id]['worked'] ?? 0) / 60, 1),
                'overtime_hours' => round(($totals[$id]['overtime'] ?? 0) / 60, 1),
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
        // recent days.
        for ($day = $to->copy(); $day->greaterThanOrEqualTo($from); $day = $day->copy()->subDay()) {
            $key = $day->format('Y-m-d');
            foreach ($expectedDays->onDate($employees, $day) as $id => $kind) {
                if ($kind !== ExpectedDay::WORKDAY || isset($attended[$key][$id])) {
                    continue;
                }
                if (count($rows) === self::ROW_LIMIT) {
                    $this->truncated = true;

                    return $rows;
                }
                $employee = $byId[$id];
                $rows[] = [
                    'employee_name' => $employee->name,
                    'employee_code' => $employee->employee_code,
                    'department' => $employee->department->name ?? '',
                    'branch' => $employee->branch->name ?? '',
                    'date' => $key,
                ];
            }
        }

        return $rows;
    }

    private function queryLeave(int $tenantId, array $filters, User $viewer): array
    {
        $query = LeaveBalance::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->with(['employee:id,name,employee_code,department_id', 'employee.department:id,name', 'leaveType:id,name']);
        $this->limitToReach($query, $tenantId, $viewer);
        $this->limitToUnits($query, $tenantId, $filters);

        $year = $filters['year'] ?? Carbon::now()->year;
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
                'payrollRun:id,period_label',
            ]);
        $this->limitToReach($query, $tenantId, $viewer);
        $this->limitToUnits($query, $tenantId, $filters);

        $rows = $this->limited($query->orderByDesc('payroll_run_id')->orderByDesc('id'));

        return $rows->map(function (PayrollEntry $e): array {
            $line = $this->register->row($e);

            return [
                'employee_name' => $e->employee?->name,
                'employee_code' => $e->employee?->employee_code,
                'department' => $e->employee?->department->name ?? '',
                'branch' => $e->employee?->branch->name ?? '',
                'cost_center' => $e->employee?->costCenter->name ?? '',
                'period' => $e->payrollRun?->period_label ?? '',
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
        })->toArray();
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
        $applied = false;
        foreach (['department' => 'department', 'branch' => 'branch', 'cost_center' => 'costCenter'] as $key => $relation) {
            $publicId = $filters[$key] ?? null;
            if (is_string($publicId) && $publicId !== '') {
                $query->whereHas($relation, fn (Builder $unit) => $unit->where('public_id', $publicId));
                $applied = true;
            }
        }

        return $applied;
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
     * At most ROW_LIMIT rows, noting in $truncated whether there were more.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Collection<int, TModel>
     */
    private function limited(Builder $query): Collection
    {
        $rows = $query->limit(self::ROW_LIMIT + 1)->get();

        if ($rows->count() > self::ROW_LIMIT) {
            $this->truncated = true;

            return $rows->take(self::ROW_LIMIT)->values();
        }

        return $rows->values();
    }
}
