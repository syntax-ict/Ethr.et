<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\PayrollProcessed;
use App\Models\PayrollEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PayrollProcessedNotification;
use App\Notifications\PayslipAvailableNotification;
use App\Services\CurrentTenant;
use App\Traits\SendsNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * PHASE_05 S26: "PayrollProcessedNotification → finance team" and
 * "PayslipAvailableNotification → employee".
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

        $run->loadMissing('entries.employee.user');

        foreach ($run->entries as $entry) {
            if (! $entry instanceof PayrollEntry) {
                continue;
            }

            $user = $this->userOf($entry->employee);

            if ($user instanceof User) {
                // Per-entry rather than a bulk send: each payslip notification
                // carries that employee's own net pay.
                $this->notify($user, new PayslipAvailableNotification($entry));
            }
        }

        Log::info('Payroll notifications dispatched', [
            'payroll_run' => $run->public_id,
            'finance_recipients' => $financeUsers->count(),
            'payslips' => $run->entries->count(),
        ]);
    }
}
