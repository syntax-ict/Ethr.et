<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PayrollRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A payroll run crashed and `ProcessPayrollJob::failed()` marked it `failed`.
 *
 * The counterpart to `PayrollProcessed`, and the half that was missing.
 * `docs/CLAUDE.md`'s queue-recovery table specifies "mark payroll run as
 * `failed` with error details, notify tenant admin" and recorded that only the
 * first half existed: the run's status, a `Log::error` and an `AuditLog`
 * `payroll.failed` record, with **no notification on this path at all**.
 *
 * That gap matters more here than on any other queue. `ProcessPayrollJob` sets
 * `$tries = 1` deliberately — a failed run must be inspected and re-submitted
 * by a human rather than silently retried — so the design already assumes
 * someone finds out. Until now the only way they found out was opening the
 * dashboard, which for a monthly run could be days.
 *
 * Not `ShouldBroadcast`, for the same reason `DeviceSyncFailed` is not:
 * broadcasting is deployed on neither target.
 */
class PayrollRunFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PayrollRun $payrollRun,
        public readonly string $reason,
    ) {}
}
