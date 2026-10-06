<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\PayrollProcessed;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PayrollProcessedNotification;
use App\Notifications\PayslipAvailableNotification;
use App\Services\CurrentTenant;
use App\Traits\SendsNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * PHASE_05 S26: "PayrollProcessedNotification → finance team". The employee
 * half — "PayslipAvailableNotification" — moved to NotifyPayslipsReleased on
 * 2026-10-01: it went out here, before approval, with net pay a void and
 * reprocess could still change.
 *
 * Both classes shipped in Phase 5 and neither was ever constructed — payroll ran
 * and payslips appeared with nobody told. `PayrollProcessed` was already
 * dispatched and already had a listener (cache invalidation), so this hangs off
 * the existing event rather than adding a second notification path in the
 * controller.
 *
 * Queued: a run can cover hundreds of employees, and sending that many
 * notifications inline would hold the HTTP request open for the whole payroll
 * operation.
 */
class NotifyPayrollProcessed implements ShouldQueue
{
    use SendsNotifications;

    public string $queue = 'notifications';

    public function handle(PayrollProcessed $event): void
    {
        $run = $event->payrollRun;
        $tenantId = $run->tenant_id;

        // A queue worker has no request to resolve the tenant from, so the
        // tenant-scoped relations below would otherwise read nothing.
        $tenant = $run->tenant;
        if ($tenant instanceof Tenant) {
            app(CurrentTenant::class)->set($tenant);
        }

        $financeUsers = User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', [UserRole::FINANCE_ADMIN, UserRole::TENANT_ADMIN])
            ->where('status', 'active')
            ->get();

        $this->notify($financeUsers, new PayrollProcessedNotification($run));

        Log::info('Payroll notifications dispatched', [
            'payroll_run' => $run->public_id,
            'finance_recipients' => $financeUsers->count(),
        ]);
    }
}
