<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\PayrollEntry;
use Carbon\Carbon;

final class ReportEngine
{
    private const SOURCES = [
        'employees' => [
            'label' => 'Employees',
            'fields' => ['name', 'email', 'phone', 'employee_code', 'gender', 'status', 'hire_date', 'salary_cents', 'department', 'branch', 'position'],
        ],
        'attendance' => [
            'label' => 'Attendance',
            'fields' => ['employee_name', 'date', 'check_in', 'check_out', 'status', 'source', 'worked_minutes'],
        ],
        'leave' => [
            'label' => 'Leave Balances',
            'fields' => ['employee_name', 'leave_type', 'year', 'entitled_days', 'used_days', 'remaining_days'],
        ],
        'payroll' => [
            'label' => 'Payroll',
            'fields' => ['employee_name', 'period', 'basic_salary_cents', 'gross_cents', 'income_tax_cents', 'employee_pension_cents', 'net_cents'],
        ],
    ];

    public function sources(): array
    {
        return self::SOURCES;
    }

    public function generate(int $tenantId, array $config): array
    {
        $source = $config['source'] ?? 'employees';
        $columns = $config['columns'] ?? [];
        $filters = $config['filters'] ?? [];
        $groupBy = $config['group_by'] ?? null;
        $sortBy = $config['sort_by'] ?? null;
        $sortDir = $config['sort_dir'] ?? 'asc';

        $data = match ($source) {
            'employees' => $this->queryEmployees($tenantId, $filters),
            'attendance' => $this->queryAttendance($tenantId, $filters),
            'leave' => $this->queryLeave($tenantId, $filters),
            'payroll' => $this->queryPayroll($tenantId, $filters),
            default => [],
        };

        if (! empty($columns)) {
            $data = array_map(function ($row) use ($columns) {
                return array_intersect_key($row, array_flip($columns));
            }, $data);
        }

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
            // Sums any `*_cents` column present in the (already column-filtered)
            // rows per group — e.g. grouping the payroll source by `period` gives
            // the monthly income-tax/pension totals a statutory filing needs,
            // without a payroll-specific code path. A count alone ("45 rows")
            // told a compliance officer nothing usable for a remittance filing.
            $sums = [];
            foreach ($data as $row) {
                $key = (string) ($row[$groupBy] ?? 'Unknown');
                $grouped[$key] = ($grouped[$key] ?? 0) + 1;

                foreach ($row as $field => $value) {
                    if (str_ends_with($field, '_cents') && is_numeric($value)) {
                        $sums[$key][$field] = ($sums[$key][$field] ?? 0) + (int) $value;
                    }
                }
            }
            $summary = ['grouped_by' => $groupBy, 'groups' => $grouped];
            if ($sums !== []) {
                $summary['group_sums'] = $sums;
            }
        }

        return [
            'source' => $source,
            'total' => count($data),
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

        $lines = [implode(',', array_keys($rows[0]))];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                fn ($value) => '"'.str_replace('"', '""', (string) $value).'"',
                $row
            ));
        }

        return implode("\r\n", $lines);
    }

    private function queryEmployees(int $tenantId, array $filters): array
    {
        $query = Employee::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            // `positions` has no `name` column (it is `title`). Naming it here both
            // errors on a real driver and fails to load the column the mapping
            // below actually reads, so the eager load must select `title` too.
            ->with(['department:id,name', 'branch:id,name', 'position:id,title']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        return $query->get()->map(fn ($e) => [
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
        ])->toArray();
    }

    private function queryAttendance(int $tenantId, array $filters): array
    {
        $query = AttendanceRecord::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->with('employee:id,name');

        if (! empty($filters['from'])) {
            $query->whereDate('date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('date', '<=', $filters['to']);
        }

        return $query->limit(1000)->get()->map(fn ($r) => [
            'employee_name' => $r->employee?->name,
            'date' => $r->date instanceof Carbon ? $r->date->format('Y-m-d') : $r->date,
            'check_in' => $r->check_in,
            'check_out' => $r->check_out,
            'status' => $r->status->value,
            'source' => $r->source->value,
            'worked_minutes' => $r->worked_minutes ?? 0,
        ])->toArray();
    }

    private function queryLeave(int $tenantId, array $filters): array
    {
        $query = LeaveBalance::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->with(['employee:id,name', 'leaveType:id,name']);

        $year = $filters['year'] ?? Carbon::now()->year;
        $query->where('year', $year);

        return $query->get()->map(fn ($b) => [
            'employee_name' => $b->employee?->name,
            'leave_type' => $b->leaveType?->name,
            'year' => $b->year,
            'entitled_days' => $b->entitled_days,
            'used_days' => $b->used_days,
            'remaining_days' => $b->remainingDays(),
        ])->toArray();
    }

    private function queryPayroll(int $tenantId, array $filters): array
    {
        $query = PayrollEntry::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->with(['employee:id,name', 'payrollRun:id,period_label']);

        return $query->limit(1000)->get()->map(fn ($e) => [
            'employee_name' => $e->employee?->name,
            'period' => $e->payrollRun?->period_label ?? '',
            'basic_salary_cents' => $e->basic_salary_cents,
            'gross_cents' => $e->gross_cents,
            'income_tax_cents' => $e->income_tax_cents,
            'employee_pension_cents' => $e->employee_pension_cents,
            'net_cents' => $e->net_cents,
        ])->toArray();
    }
}
