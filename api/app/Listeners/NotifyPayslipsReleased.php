<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PayrollApproved;
use App\Models\PayrollEntry;
use App\Models\Tenant;
use App\Notifications\PayslipAvailableNotification;
use App\Services\CurrentTenant;
use App\Traits\SendsNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * "Your payslip is available" — sent when the run is approved.
 *
 * It used to go out from NotifyPayrollProcessed, the moment a run was
 * *calculated* and before anyone in finance had approved it, carrying the
 * employee's net pay. A run voided and reprocessed told the same employees a
 * second, different figure. Finance still hears at processing — that is their
 * cue to review; employees hear once the numbers are final.
 *
 * Queued, like NotifyPayrollProcessed: a run can cover hundreds of employees.
 */
class NotifyPayslipsReleased implements ShouldQueue
{
    use SendsNotifications;

    public string $queue = 'notifications';

    public function handle(PayrollApproved $event): void
    {
        $run = $event->payrollRun;

        // A queue worker has no request to resolve the tenant from, so the
        // tenant-scoped relations below would otherwise read nothing.
        $tenant = $run->tenant;
        if ($tenant instanceof Tenant) {
            app(CurrentTenant::class)->set($tenant);
        }

        $run->loadMissing('entries.employee.user');

        foreach ($run->entries as $entry) {
            if (! $entry instanceof PayrollEntry) {
                continue;
            }

            // The notification reads `$entry->payrollRun` for the period label.
            // Lazy loading is disabled application-wide, so without the relation
            // set that read threw inside Notification::send(), notify() logged
            // the throw as a warning, and the run was still logged as released:
            // no employee ever received a payslip notification on any channel
            // (QA pass 2026-10-08). The run is in hand; hand it to each entry.
            $entry->setRelation('payrollRun', $run);

            $user = $this->userOf($entry->employee);
            if ($user !== null) {
                // Per entry: each notification carries that employee's net pay.
                $this->notify($user, new PayslipAvailableNotification($entry));
            }
        }

        Log::info('Payslips released', [
            'payroll_run' => $run->public_id,
            'payslips' => $run->entries->count(),
        ]);
    }
}
