<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\PayrollRunFailed;
use App\Notifications\PayrollRunFailedNotification;
use App\Support\TenantRecipients;
use App\Traits\SendsNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyPayrollRunFailed implements ShouldQueue
{
    use SendsNotifications;

    public string $queue = 'notifications';

    public function handle(PayrollRunFailed $event): void
    {
        $run = $event->payrollRun;

        // The audience mirrors the success path exactly: finance and tenant admins,
        // active only, because the people told that payroll ran are the people who
        // need telling when it did not. The tenant is derived from the run, which
        // is itself tenant-owned.
        $admins = TenantRecipients::withRoles($run->tenant_id, [UserRole::FINANCE_ADMIN, UserRole::TENANT_ADMIN]);

        $this->notify($admins, new PayrollRunFailedNotification($run, $event->reason));
    }
}
