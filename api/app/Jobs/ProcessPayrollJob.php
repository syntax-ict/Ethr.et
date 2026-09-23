<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\PayrollProcessed;
use App\Models\AuditLog;
use App\Models\PayrollRun;
use App\Services\Payroll\PayrollEngine;
use App\Traits\DispatchesWebhooks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Computes a payroll run that PayrollController reserved.
 *
 * Payroll used to run inline in the HTTP request: PayrollEngine chunks over
 * every active employee, computing tax, pension, overtime, loans, allowances and
 * cost-sharing per row, with no job wrapper and no set_time_limit.
 *
 * The project's own budget says that is already too slow. docs/CLAUDE.md:858
 * targets "payroll calculation (500 employees) < 30s" — on dedicated hardware.
 * A shared host's max_execution_time is typically 30–120s with a FastCGI read
 * timeout behind it, so the documented best case sits at or past the limit
 * before any contention. The failure mode was a 504 with the run left mid-flight
 * at status `processing` and no way to tell whether the entries were written.
 *
 * Master plan §20: request creates the job and returns a status ID, cron worker
 * processes it, client polls. §20 also says preserve idempotency, calculation
 * logs, auditability and retry safety — which is why this wraps the engine
 * rather than reimplementing any of it.
 */
class ProcessPayrollJob implements ShouldQueue
{
    use Dispatchable, DispatchesWebhooks, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * One attempt, deliberately.
     *
     * Idempotency (convention 10) protects against a *client* replaying the
     * request: PayrollEngine::begin() returns the existing run for a known key.
     * It does not protect against this job running twice on the same run, which
     * would append a second set of entries to a run that already has them.
     *
     * A failed payroll run should be inspected and re-submitted by a human, not
     * silently retried by a worker.
     */
    public int $tries = 1;

    /**
     * Longer than every other job except BackupTenantJob, because this one
     * scales with headcount.
     *
     * tests/Feature/QueueRetryAfterInvariantTest.php asserts the queue's
     * retry_after exceeds this — otherwise a run still computing at the
     * retry_after mark is re-reserved and executed a second time, which is
     * exactly the duplication $tries = 1 exists to prevent.
     */
    public int $timeout = 900;

    /**
     * `$tenantId` is nullable and trailing so runs already queued when this
     * deployed still unserialize — an absent property takes the declared
     * default instead of staying uninitialized. Every dispatch since supplies
     * it, and it can be tightened to a required `int` once a drain has passed.
     */
    public function __construct(
        public readonly int $payrollRunId,
        public ?int $tenantId = null,
    ) {}

    public function handle(PayrollEngine $engine): void
    {
        // Jobs carry no HTTP tenant context, so the global scope would resolve
        // to `whereRaw('0 = 1')` and find nothing. The predicate now comes from
        // the dispatcher rather than from the row this query is fetching: the
        // old comment said "scoped by the run's own tenant_id", but a predicate
        // read off the row you just fetched proves nothing about which row you
        // were entitled to fetch. This job computes and writes payroll, so the
        // wrong run is the most expensive mistake in the codebase.
        $query = PayrollRun::withoutGlobalScopes();

        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        $run = $query->find($this->payrollRunId);

        if ($run === null) {
            Log::warning('ProcessPayrollJob: run no longer exists', ['id' => $this->payrollRunId]);

            return;
        }

        if ($run->status !== 'processing') {
            // Already finished, voided, or picked up elsewhere. Re-computing
            // would append a duplicate set of entries.
            Log::info('ProcessPayrollJob: run is not processing, skipping', [
                'id' => $run->id,
                'status' => $run->status,
            ]);

            return;
        }

        $engine->runEntries($run);

        $run->refresh();

        AuditLog::record('payroll.processed', $run, [
            'period' => $run->period_label,
            'employees' => $run->employee_count,
        ]);

        $this->webhook($run->tenant_id, 'payroll.processed', [
            'public_id' => $run->public_id,
            'period' => $run->period_label,
            'employee_count' => $run->employee_count,
        ]);

        PayrollProcessed::dispatch($run);
    }

    /**
     * Mark the run failed so the UI can say so.
     *
     * Without this a crashed run sits at `processing` forever and is
     * indistinguishable from one still working — the same ambiguity the 504 used
     * to produce, which is half the reason this job exists.
     */
    public function failed(Throwable $e): void
    {
        // Same predicate as handle(), deliberately: the two halves of a job
        // that disagree about how much to state is the shape root CLAUDE.md
        // records for DispatchWebhookJob. This one marks a run `failed`, so
        // picking the wrong row would write a wrong status into another
        // tenant's payroll.
        $query = PayrollRun::withoutGlobalScopes();

        if ($this->tenantId !== null) {
            $query->where('tenant_id', $this->tenantId);
        }

        $run = $query->find($this->payrollRunId);

        if ($run === null || $run->status !== 'processing') {
            return;
        }

        $run->update(['status' => 'failed']);

        Log::error('Payroll run failed', [
            'payroll_run_id' => $run->id,
            'tenant_id' => $run->tenant_id,
            'period' => $run->period_label,
            'error' => $e->getMessage(),
        ]);

        AuditLog::record('payroll.failed', $run, [
            'period' => $run->period_label,
            'error' => $e->getMessage(),
        ]);
    }
}
