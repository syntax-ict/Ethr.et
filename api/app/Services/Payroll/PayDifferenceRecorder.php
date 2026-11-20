<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Jobs\ComputePayDifferencesJob;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Models\PayrollPayDifference;
use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records what a change to someone's pay, reaching back into a month already
 * released, would have changed in that month's payslip.
 *
 * flag() is the trigger: cheap, run inside the caller's transaction, it only
 * marks the pair (released run, employee) `pending` and queues the work.
 * computePending() does the arithmetic through PayrollEngine::counterfactual()
 * and leaves the row `open`, or removes it when nothing differs. No money
 * moves: finance settles or dismisses an open row, and approving the next run
 * requires acknowledging the open ones (PayrollController::approve).
 *
 * One live row (pending or open) per run and employee, kept here under a row
 * lock because MariaDB has no partial unique index. The baseline a new row is
 * measured from is what the employee has effectively been paid for that month:
 * the `due` side of the latest settled or dismissed row, else the `paid` side
 * of the recalculation, so a settled difference is never counted twice.
 */
final class PayDifferenceRecorder
{
    public function __construct(private readonly PayrollEngine $engine) {}

    /**
     * Statements here state tenant_id themselves and use the query builder, so
     * they do not depend on a resolved tenant (a save from a queued job or the
     * console has none) and add no tenant-scope bypass.
     */
    public function flag(Employee $employee, string $fromDate): void
    {
        $tenantId = (int) $employee->tenant_id;
        $from = substr($fromDate, 0, 10);

        $entries = DB::table('payroll_entries as e')
            ->join('payroll_runs as r', 'r.id', '=', 'e.payroll_run_id')
            ->where('e.tenant_id', $tenantId)
            ->where('r.tenant_id', $tenantId)
            ->where('e.employee_id', $employee->id)
            ->whereIn('r.status', PayrollRun::RELEASED_STATUSES)
            ->whereDate('r.period_end', '>=', $from)
            ->select(['e.id as entry_id', 'r.id as run_id'])
            ->get();

        if ($entries->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($entries, $tenantId, $employee, $from): void {
            foreach ($entries as $row) {
                // The run row first, so two flags for one pair queue up even
                // when no difference row exists yet to lock.
                DB::table('payroll_runs')->where('tenant_id', $tenantId)->where('id', $row->run_id)->lockForUpdate()->value('id');

                $live = DB::table('payroll_pay_differences')
                    ->where('tenant_id', $tenantId)
                    ->where('payroll_run_id', $row->run_id)
                    ->where('employee_id', $employee->id)
                    ->whereIn('status', PayrollPayDifference::LIVE)
                    ->lockForUpdate()
                    ->first();

                if ($live === null) {
                    DB::table('payroll_pay_differences')->insert([
                        'public_id' => (string) Str::ulid(),
                        'tenant_id' => $tenantId,
                        'employee_id' => $employee->id,
                        'payroll_run_id' => $row->run_id,
                        'payroll_entry_id' => $row->entry_id,
                        'status' => PayrollPayDifference::PENDING,
                        'changed_from' => $from,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    continue;
                }

                DB::table('payroll_pay_differences')->where('id', $live->id)->update([
                    'status' => PayrollPayDifference::PENDING,
                    'kind' => null,
                    'gross_cents' => null,
                    'net_cents' => null,
                    'paid_gross_cents' => null,
                    'paid_net_cents' => null,
                    'due_gross_cents' => null,
                    'due_net_cents' => null,
                    'changed_from' => min(substr((string) $live->changed_from, 0, 10), $from),
                    'updated_at' => now(),
                ]);
            }
        });

        ComputePayDifferencesJob::dispatch($tenantId, (int) $employee->id)->afterCommit();
    }

    /**
     * Works out the pending rows of the tenant (of `$employeeIds` when given),
     * at most `$limit` of them, oldest first. The caller has the tenant
     * resolved. Returns how many are still pending.
     *
     * @param  list<int>|null  $employeeIds
     */
    public function computePending(int $tenantId, ?array $employeeIds = null, int $limit = PHP_INT_MAX): int
    {
        $chunks = $employeeIds === null ? [null] : array_chunk(array_values(array_unique($employeeIds)), 500);
        $left = $limit;

        foreach ($chunks as $chunk) {
            if ($left <= 0) {
                break;
            }

            $ids = $this->pendingQuery($tenantId, $chunk)->orderBy('id')->limit($left)->pluck('id');

            foreach ($ids as $id) {
                $this->compute($tenantId, (int) $id);
                $left--;
            }
        }

        $remaining = 0;
        foreach ($chunks as $chunk) {
            $remaining += $this->pendingQuery($tenantId, $chunk)->count();
        }

        return $remaining;
    }

    /**
     * @param  list<int>|null  $employeeIds
     * @return Builder<PayrollPayDifference>
     */
    private function pendingQuery(int $tenantId, ?array $employeeIds): Builder
    {
        return PayrollPayDifference::query()
            ->where('tenant_id', $tenantId)
            ->where('status', PayrollPayDifference::PENDING)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds));
    }

    private function compute(int $tenantId, int $id): void
    {
        DB::transaction(function () use ($tenantId, $id): void {
            $row = PayrollPayDifference::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            // Computed by someone else since it was listed, or superseded.
            if ($row === null || $row->status !== PayrollPayDifference::PENDING) {
                return;
            }

            $entry = PayrollEntry::query()->where('tenant_id', $tenantId)->find($row->payroll_entry_id);
            if ($entry === null) {
                return;
            }

            $result = $this->engine->counterfactual($entry);
            $paid = $result['paid'];
            $due = $result['due'];

            $baseline = PayrollPayDifference::query()
                ->where('tenant_id', $tenantId)
                ->where('payroll_run_id', $row->payroll_run_id)
                ->where('employee_id', $row->employee_id)
                ->whereIn('status', [PayrollPayDifference::SETTLED, PayrollPayDifference::DISMISSED])
                ->whereNotNull('due_gross_cents')
                ->orderByDesc('id')
                ->first();

            $baseGross = $baseline !== null ? (int) $baseline->due_gross_cents : (int) $paid['gross_cents'];
            $baseNet = $baseline !== null ? (int) $baseline->due_net_cents : (int) $paid['net_cents'];
            $gross = (int) $due['gross_cents'] - $baseGross;
            $net = (int) $due['net_cents'] - $baseNet;

            if ($gross === 0 && $net === 0) {
                $row->delete();

                return;
            }

            $log = $entry->getAttribute('calculation_log');
            $version = is_array($log) ? (string) ($log['version'] ?? '0') : '0';

            $row->forceFill([
                'status' => PayrollPayDifference::OPEN,
                'kind' => ($net !== 0 ? $net : $gross) > 0 ? 'underpaid' : 'overpaid',
                'gross_cents' => $gross,
                'net_cents' => $net,
                'paid_gross_cents' => $baseGross,
                'paid_net_cents' => $baseNet,
                'due_gross_cents' => (int) $due['gross_cents'],
                'due_net_cents' => (int) $due['net_cents'],
                'detail' => [
                    'paid_side' => $paid,
                    'due_side' => $due,
                    'baseline' => $baseline !== null ? $baseline->status : 'recalculation',
                    'entry_log_version' => $version,
                    'trail_rebuilt_at_release' => version_compare($version, '1.9', '<'),
                ],
            ])->save();
        });
    }
}
