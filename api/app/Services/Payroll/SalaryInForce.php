<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeSalaryHistory;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The salary an employee had on a given day: the history row with the latest
 * effective date on or before it, the later row winning a tie. A superseded
 * row (one a later-recorded, earlier-dated change replaced, see
 * SalaryHistoryWriter) is never read.
 *
 * A run reads it for its period's last day, so the whole period is paid one
 * salary however many changes landed inside it. Reads the eager-loaded
 * `salaryHistory` when there is one, so a run costs no query per person.
 */
final class SalaryInForce
{
    public static function on(Employee $employee, CarbonInterface $date): ?EmployeeSalaryHistory
    {
        $day = $date->toDateString();

        if ($employee->relationLoaded('salaryHistory')) {
            $sorted = self::live($employee)
                ->sort(fn (EmployeeSalaryHistory $a, EmployeeSalaryHistory $b): int => [$a->effective_date->toDateString(), $a->id] <=> [$b->effective_date->toDateString(), $b->id]);

            // Before the first row, the first row: a hire date corrected to an
            // earlier day does not leave months with no salary.
            return $sorted->filter(fn (EmployeeSalaryHistory $row): bool => $row->effective_date->toDateString() <= $day)->last()
                ?? $sorted->first();
        }

        $rows = fn () => EmployeeSalaryHistory::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereNull('superseded_by');

        return $rows()
            ->whereDate('effective_date', '<=', $day)
            ->orderByRaw('date(effective_date) desc')
            ->orderByDesc('id')
            ->first()
            ?? $rows()->orderByRaw('date(effective_date) asc')->orderBy('id')->first();
    }

    /** Whether the employee has any row that still counts. Only such an employee is not read from the column. */
    public static function hasRows(Employee $employee): bool
    {
        if ($employee->relationLoaded('salaryHistory')) {
            return self::live($employee)->isNotEmpty();
        }

        return EmployeeSalaryHistory::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereNull('superseded_by')
            ->exists();
    }

    /**
     * Whether a row for the employee takes effect after the start and on or
     * before the end. The earliest row is the salary the employee began with,
     * not a change, so a mid-period hire does not count.
     */
    public static function changedWithin(Employee $employee, CarbonInterface $start, CarbonInterface $end): bool
    {
        $from = $start->toDateString();
        $to = $end->toDateString();

        if ($employee->relationLoaded('salaryHistory')) {
            $rows = self::live($employee);
            $first = $rows->sortBy([['effective_date', 'asc'], ['id', 'asc']])->first();

            return $rows->contains(function (EmployeeSalaryHistory $row) use ($from, $to, $first): bool {
                if ($row->id === $first?->id) {
                    return false;
                }

                $day = $row->effective_date->toDateString();

                return $day > $from && $day <= $to;
            });
        }

        $firstId = EmployeeSalaryHistory::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereNull('superseded_by')
            ->orderByRaw('date(effective_date) asc')
            ->orderBy('id')
            ->value('id') ?? 0;

        return EmployeeSalaryHistory::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereNull('superseded_by')
            ->whereDate('effective_date', '>', $from)
            ->whereDate('effective_date', '<=', $to)
            ->where('id', '!=', $firstId)
            ->exists();
    }

    /** @return Collection<int, EmployeeSalaryHistory> */
    private static function live(Employee $employee): Collection
    {
        return $employee->salaryHistory->whereNull('superseded_by');
    }
}
