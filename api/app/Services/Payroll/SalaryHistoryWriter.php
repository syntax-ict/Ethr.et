<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeSalaryHistory;
use App\Models\Tenant;
use App\Support\TenantTime;
use Illuminate\Support\Facades\DB;

/**
 * The one place a salary history row is inserted and dated.
 *
 * Dating. A row with a date from its writer (an action's effective date, a
 * contract's start, a form's effective date) keeps it, but never earlier than
 * the hire date. Without one, the first
 * live row an employee gets is dated at the hire date, so a salary set after
 * creation covers the months since the hire, and any later row is dated the
 * later of today and the hire date, so an edit made before a future hire does
 * not fall behind the hire row; a tie goes to the later row.
 *
 * Superseding. A change dated on or before today replaces every
 * earlier-recorded, later-dated row up to today (an edit on 20 November and a
 * promotion recorded on 3 December for 15 November leave the promotion in
 * force). Those rows get `superseded_by`; every reader skips them. A row dated
 * after today is a plan and replaces nothing. The update names the tenant
 * itself and does not depend on a resolved tenant.
 *
 * Formality. A personnel action or a contract may supersede any row. An edit,
 * an import, a hire or a backfill row may supersede only rows of those four
 * sources, never an action or a contract: a casual back-dated edit must not
 * silently erase a formal promotion or contract. The migration that
 * backfills the table repeats this rule (FORMAL_SOURCES) in its own replay.
 */
final class SalaryHistoryWriter
{
    /** Sources that may supersede any row; every other source may not supersede these. */
    public const FORMAL_SOURCES = ['personnel_action', 'contract'];

    public function record(Employee $employee, int $cents, ?string $date, string $source, ?int $sourceId, ?int $recordedBy): EmployeeSalaryHistory
    {
        $today = TenantTime::today(Tenant::query()->find($employee->tenant_id))->toDateString();
        $hired = $employee->hire_date?->toDateString();

        if ($date === null || $date === '') {
            $hasLive = DB::table('employee_salary_history')
                ->where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->whereNull('superseded_by')
                ->exists();

            $date = ! $hasLive
                ? ($hired ?? $today)
                : ($hired !== null && $hired > $today ? $hired : $today);
        }

        // No row is dated before the hire, supplied dates included: a contract
        // or an action that starts earlier does not fall behind the hire row of
        // a later hire.
        $date = substr($date, 0, 10);
        if ($hired !== null && $date < $hired) {
            $date = $hired;
        }

        $row = EmployeeSalaryHistory::query()->create([
            'tenant_id' => $employee->tenant_id,
            'employee_id' => $employee->id,
            'salary_cents' => $cents,
            'effective_date' => $date,
            'source' => $source,
            'source_id' => $sourceId,
            'recorded_by' => $recordedBy,
        ]);

        if ($date <= $today) {
            DB::table('employee_salary_history')
                ->where('tenant_id', $employee->tenant_id)
                ->where('employee_id', $employee->id)
                ->whereNull('superseded_by')
                ->where('id', '!=', $row->id)
                ->whereRaw('date(effective_date) > ?', [$date])
                ->whereRaw('date(effective_date) <= ?', [$today])
                ->when(
                    ! in_array($source, self::FORMAL_SOURCES, true),
                    fn ($query) => $query->whereNotIn('source', self::FORMAL_SOURCES),
                )
                ->update(['superseded_by' => $row->id]);
        }

        // A month already released may have been paid a different figure.
        app(PayDifferenceRecorder::class)->flag($employee, $date);

        return $row;
    }
}
