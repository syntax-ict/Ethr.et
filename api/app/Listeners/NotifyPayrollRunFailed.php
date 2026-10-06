<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\PayrollRunFailed;
use App\Models\User;
use App\Notifications\PayrollRunFailedNotification;
use App\Traits\SendsNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifyPayrollRunFailed implements ShouldQueue
{
    use SendsNotifications;

    public string $queue = 'notifications';

    public function handle(PayrollRunFailed $event): void
    {
        $run = $event->payrollRun;

        // Same bypass and same justification as `NotifyPayrollProcessed` and
        // `NotifyDeviceSyncFailed`: the listener is queued, so no tenant is
        // resolved and `BelongsToTenant` would add `whereRaw('0 = 1')` on top of
        // the predicate below, matching nobody.
        //
        // The predicate is re-applied explicitly from `$run->tenant_id`, and the
        // run is itself tenant-owned. This raises the pinned inventory from
        // 158/56 to 159/57.
        //
        // The audience mirrors the success path exactly — finance and tenant
        // admins, active only — because the people told that payroll ran are the
        // people who need telling when it did not.
        $admins = User::withoutGlobalScopes()
            ->where('tenant_id', $run->tenant_id)
            ->whereIn('role', [UserRole::FINANCE_ADMIN, UserRole::TENANT_ADMIN])
            ->where('status', 'active')
            ->get();

        $this->notify($admins, new PayrollRunFailedNotification($run, $event->reason));
    }
}
