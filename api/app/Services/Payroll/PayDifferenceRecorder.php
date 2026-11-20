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
use Throwable;

/**
 * Records what a change to someone's pay, reaching back into a month already
 * released, would have changed in that month's payslip.
 *
 * flag() is the trigger: cheap, run inside the caller's transaction, it only
 * marks the pair (released run, employee) `pending` and queues the work.
 * computePending() does the arithmetic through PayrollEngine::counterfactual()
 * and leaves the row `open`, removes it when nothing differs, or marks it
 * `failed` when it cannot be worked out. No money moves: finance settles or
 * dismisses an open row, and approving the next run requires acknowledging
 * the open and failed ones (PayrollController::approve).
 *
 * One live row (pending, open or failed) per run and employee, kept here under
 * a row lock because MariaDB has no partial unique index. What finance has
 * already handled for the month is a sum taken off the difference as it stands
 * today (see handledSums()), across the runs a void and reprocess replaced.
 */
class PayDifferenceRecorder
{
    /** How many runs back a reprocess chain is followed. */
    private const CHAIN_LIMIT = 50;

    /** @var array<int, array{employee: Employee, from: string}>|null employee id => the earliest date, while an Employee save is buffering */
    private static ?array $buffer = null;

    private int $failedInLastCall = 0;

    public function __construct(private readonly PayrollEngine $engine) {}

    /**
     * Opens a buffer for the duration of one Employee save: a save that
     * changes both salary and leaving date flags once, from the earliest date,
     * so released runs are locked in one order. Returns whether this call
     * opened it (a nested save leaves the outer one to flush).
     */
    public static function beginBuffer(): bool
    {
        if (self::$buffer !== null) {
            return false;
        }

        self::$buffer = [];

        return true;
    }

    public function flushBuffer(): void
    {
        $buffered = self::$buffer ?? [];
        self::$buffer = [];

        foreach ($buffered as $item) {
            $this->flagNow($item['employee'], $item['from']);
        }
    }

    public static function endBuffer(): void
    {
        self::$buffer = null;
    }

    /**
     * Statements here state tenant_id themselves and use the query builder, so
     * they do not depend on a resolved tenant (a save from a queued job or the
     * console has none) and add no tenant-scope bypass. Inside an Employee save
     * the call is held and flushed once at the end of the save.
     */
    public function flag(Employee $employee, string $fromDate): void
    {
        $from = substr($fromDate, 0, 10);

        if (self::$buffer !== null) {
            $held = self::$buffer[$employee->id]['from'] ?? null;
            self::$buffer[$employee->id] = ['employee' => $employee, 'from' => $held === null ? $from : min($held, $from)];

            return;
        }

        $this->flagNow($employee, $from);
    }

    private function flagNow(Employee $employee, string $from): void
    {
        $tenantId = (int) $employee->tenant_id;

        $entries = DB::table('payroll_entries as e')
            ->join('payroll_runs as r', 'r.id', '=', 'e.payroll_run_id')
            ->where('e.tenant_id', $tenantId)
            ->where('r.tenant_id', $tenantId)
            ->where('e.employee_id', $employee->id)
            ->whereIn('r.status', PayrollRun::RELEASED_STATUSES)
            ->whereDate('r.period_end', '>=', $from)
            ->orderBy('r.id')
            ->select(['e.id as entry_id', 'r.id as run_id'])
            ->get();

        if ($entries->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($entries, $tenantId, $employee, $from): void {
            foreach ($entries as $row) {
                // The run row first, so two flags for one pair queue up even
                // when no difference row exists yet to lock. Settle, dismiss
                // and void take the same lock first.
                DB::table('payroll_runs')->where('tenant_id', $tenantId)->where('id', $row->run_id)->lockForUpdate()->value('id');

                $live = DB::table('payroll_pay_differences')
                    ->where('tenant_id', $tenantId)
                    ->where('payroll_run_id', $row->run_id)
                    ->where('employee_id', $employee->id)
                    ->whereIn('status', PayrollPayDifference::LIVE)
                    ->orderBy('id')
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
                    'detail' => null,
                    'changed_from' => min(substr((string) $live->changed_from, 0, 10), $from),
                    'updated_at' => now(),
                ]);
            }
        });

        ComputePayDifferencesJob::dispatch($tenantId, (int) $employee->id)->afterCommit();
    }

    /**
     * Works out the pending rows of the tenant (of `$employeeIds` when given),
     * at most `$limit` of them, oldest first, stopping early once `$deadline`
     * (a `microtime(true)` value) has passed; at least one row is worked on
     * per call. `$includeFailed` takes failed rows again too (the queued job's
     * retry). The caller has the tenant resolved. Returns how many are still
     * pending; how many could not be worked out is failedInLastCall().
     *
     * @param  list<int>|null  $employeeIds
     */
    public function computePending(int $tenantId, ?array $employeeIds = null, int $limit = PHP_INT_MAX, ?float $deadline = null, bool $includeFailed = false): int
    {
        $chunks = $employeeIds === null ? [null] : array_chunk(array_values(array_unique($employeeIds)), 500);
        $left = $limit;
        $prepared = [];
        $this->failedInLastCall = 0;
        $stop = false;

        foreach ($chunks as $chunk) {
            if ($left <= 0 || $stop) {
                break;
            }

            $ids = $this->workQuery($tenantId, $chunk, $includeFailed)->orderBy('id')->limit($left)->pluck('id');

            foreach ($ids as $id) {
                $left--;

                // One row that cannot be worked out is marked failed and
                // reported; it must not stop the rest, or an approval.
                try {
                    $this->compute($tenantId, (int) $id, $prepared, $includeFailed);
                } catch (Throwable $e) {
                    report($e);
                    $this->markFailed($tenantId, (int) $id, $e);
                    $this->failedInLastCall++;
                }

                if ($deadline !== null && microtime(true) >= $deadline) {
                    $stop = true;

                    break;
                }
            }
        }

        $remaining = 0;
        foreach ($chunks as $chunk) {
            $remaining += $this->pendingQuery($tenantId, $chunk)->count();
        }

        return $remaining;
    }

    /** How many rows the last computePending() call could not work out. */
    public function failedInLastCall(): int
    {
        return $this->failedInLastCall;
    }

    /**
     * The one place the engine is asked, so a test can make a single row fail.
     *
     * @param  array<int, array<string, mixed>>  $prepared
     * @return array{paid: array<string, mixed>, due: array<string, mixed>, net_is_estimate: bool}
     */
    protected function counterfactualFor(PayrollEntry $entry, array &$prepared): array
    {
        $prepared[$entry->payroll_run_id] ??= $this->engine->prepareForEntry($entry);

        return $this->engine->counterfactual($entry, $prepared[$entry->payroll_run_id]);
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

    /**
     * @param  list<int>|null  $employeeIds
     * @return Builder<PayrollPayDifference>
     */
    private function workQuery(int $tenantId, ?array $employeeIds, bool $includeFailed): Builder
    {
        return PayrollPayDifference::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', $includeFailed ? [PayrollPayDifference::PENDING, PayrollPayDifference::FAILED] : [PayrollPayDifference::PENDING])
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds));
    }

    /** Short and free of stack traces: the class and a clipped message. */
    private function markFailed(int $tenantId, int $id, Throwable $e, ?string $message = null): void
    {
        $row = DB::table('payroll_pay_differences')->where('tenant_id', $tenantId)->where('id', $id)->first();
        if ($row === null || ! in_array($row->status, [PayrollPayDifference::PENDING, PayrollPayDifference::FAILED], true)) {
            return;
        }

        $detail = is_string($row->detail) ? (json_decode($row->detail, true) ?: []) : [];

        DB::table('payroll_pay_differences')->where('tenant_id', $tenantId)->where('id', $id)->update([
            'status' => PayrollPayDifference::FAILED,
            'detail' => json_encode([
                'error' => ['class' => class_basename($e), 'message' => mb_substr($message ?? $e->getMessage(), 0, 200)],
                'attempts' => (int) ($detail['attempts'] ?? 0) + 1,
            ]),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $prepared  run id => the engine's prepare() for it, filled as rows are worked out
     */
    private function compute(int $tenantId, int $id, array &$prepared, bool $includeFailed): void
    {
        $missing = DB::transaction(function () use ($tenantId, $id, &$prepared, $includeFailed): bool {
            $row = PayrollPayDifference::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            // Computed by someone else since it was listed, or superseded.
            $workable = $includeFailed ? [PayrollPayDifference::PENDING, PayrollPayDifference::FAILED] : [PayrollPayDifference::PENDING];
            if ($row === null || ! in_array($row->status, $workable, true)) {
                return false;
            }

            $entry = PayrollEntry::query()->where('tenant_id', $tenantId)->find($row->payroll_entry_id);
            if ($entry === null) {
                return true;
            }

            $result = $this->counterfactualFor($entry, $prepared);
            $paid = $result['paid'];
            $due = $result['due'];

            ['gross' => $handledGross, 'net' => $handledNet, 'rows' => $handledRows] = $this->handledSums(
                $tenantId,
                (int) $row->payroll_run_id,
                (int) $row->employee_id,
                (int) $due['gross_cents'] - (int) $paid['gross_cents'],
                (int) $due['net_cents'] - (int) $paid['net_cents'],
            );

            $gross = (int) $due['gross_cents'] - (int) $paid['gross_cents'] - $handledGross;
            $net = (int) $due['net_cents'] - (int) $paid['net_cents'] - $handledNet;

            if ($gross === 0 && $net === 0) {
                $row->delete();

                return false;
            }

            $log = $entry->getAttribute('calculation_log');
            $version = is_array($log) ? (string) ($log['version'] ?? '0') : '0';

            $row->forceFill([
                'status' => PayrollPayDifference::OPEN,
                'kind' => ($net !== 0 ? $net : $gross) > 0 ? 'underpaid' : 'overpaid',
                'gross_cents' => $gross,
                'net_cents' => $net,
                'paid_gross_cents' => (int) $paid['gross_cents'],
                'paid_net_cents' => (int) $paid['net_cents'],
                'due_gross_cents' => (int) $due['gross_cents'],
                'due_net_cents' => (int) $due['net_cents'],
                'detail' => [
                    'paid_side' => $paid,
                    'due_side' => $due,
                    'handled_gross_cents' => $handledGross,
                    'handled_net_cents' => $handledNet,
                    'handled_rows' => $handledRows,
                    'loan_deductions_cents' => $paid['loan_deductions_cents'] ?? null,
                    'cost_sharing_cents' => $paid['cost_sharing_cents'] ?? null,
                    'net_is_estimate' => $result['net_is_estimate'],
                    'entry_log_version' => $version,
                    'trail_rebuilt_at_release' => version_compare($version, '1.9', '<'),
                ],
            ])->save();

            return false;
        });

        if ($missing) {
            $this->markFailed($tenantId, $id, new \RuntimeException('entry missing'), 'The payslip this difference belongs to no longer exists.');
            $this->failedInLastCall++;
        }
    }

    /**
     * What finance has handled for this month, to take off the difference as
     * it stands today: every settled row, and a dismissed row only when it
     * points the same way as what is left (a dismissed underpayment does not
     * hide a later overpayment, and one that was put back leaves nothing).
     * Rows of the run itself and of every run it replaced by void and
     * reprocess count, so a month is not paid twice. Sums, not today's
     * recalculation, so drift since a row was written is not counted again.
     *
     * @return array{gross: int, net: int, rows: int}
     */
    private function handledSums(int $tenantId, int $runId, int $employeeId, int $rawGross, int $rawNet): array
    {
        $rows = PayrollPayDifference::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('payroll_run_id', $this->chain($tenantId, $runId))
            ->where('employee_id', $employeeId)
            ->whereIn('status', [PayrollPayDifference::SETTLED, PayrollPayDifference::DISMISSED])
            ->get(['status', 'gross_cents', 'net_cents']);

        $gross = 0;
        $net = 0;
        $counted = 0;
        foreach ($rows->where('status', PayrollPayDifference::SETTLED) as $row) {
            $gross += (int) $row->gross_cents;
            $net += (int) $row->net_cents;
            $counted++;
        }

        $sign = fn (int $n, int $g): int => ($n !== 0 ? $n <=> 0 : $g <=> 0);
        $left = $sign($rawNet - $net, $rawGross - $gross);

        foreach ($rows->where('status', PayrollPayDifference::DISMISSED) as $row) {
            if ($left !== 0 && $sign((int) $row->net_cents, (int) $row->gross_cents) === $left) {
                $gross += (int) $row->gross_cents;
                $net += (int) $row->net_cents;
                $counted++;
            }
        }

        return ['gross' => $gross, 'net' => $net, 'rows' => $counted];
    }

    /**
     * The run and the runs it replaced, following `reprocessed_from_id`,
     * bounded and with the tenant stated.
     *
     * @return list<int>
     */
    public function chain(int $tenantId, int $runId): array
    {
        $ids = [$runId];
        $current = $runId;

        for ($i = 0; $i < self::CHAIN_LIMIT; $i++) {
            $from = DB::table('payroll_runs')->where('tenant_id', $tenantId)->where('id', $current)->value('reprocessed_from_id');
            if ($from === null || in_array((int) $from, $ids, true)) {
                break;
            }
            $ids[] = $current = (int) $from;
        }

        return $ids;
    }
}
