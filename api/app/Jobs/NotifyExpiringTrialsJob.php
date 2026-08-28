<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TrialExpiringNotification;
use App\Traits\SendsNotifications;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PHASE_05 S26: "TrialExpiringNotification → tenant admin at 30/7/1 days".
 *
 * `TrialExpiringNotification` was imported by `HandleOverdueInvoicesJob` and
 * never constructed — that job logged "reminder sent" next to a comment saying
 * "in production we'd have a dedicated OverdueInvoiceNotification". So the log
 * claimed a notification that no code path could produce, and trials expired
 * with no warning to the people who pay for them.
 */
class NotifyExpiringTrialsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SendsNotifications, SerializesModels;

    public int $tries = 2;

    /** Days before expiry at which the tenant admin is warned. */
    public const MILESTONES = [30, 7, 1];

    public function handle(): void
    {
        $sent = 0;

        foreach (self::MILESTONES as $days) {
            $target = now()->addDays($days)->toDateString();

            $tenants = Tenant::where('status', TenantStatus::TRIAL)
                ->whereNotNull('trial_ends_at')
                ->whereDate('trial_ends_at', $target)
                ->get();

            foreach ($tenants as $tenant) {
                // One warning per tenant per milestone. Without this a scheduler
                // that runs more than once a day — or a retry — would mail the
                // same admin repeatedly for the same milestone.
                $key = "trial_warned:{$tenant->public_id}:{$days}";

                if (Cache::has($key)) {
                    continue;
                }

                $admins = User::withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('role', UserRole::TENANT_ADMIN)
                    ->where('status', 'active')
                    ->get();

                if ($admins->isEmpty()) {
                    continue;
                }

                $this->notify($admins, new TrialExpiringNotification(
                    $days,
                    Carbon::parse($tenant->trial_ends_at)->toDateString(),
                ));

                Cache::put($key, true, now()->addDays(2));
                $sent++;
            }
        }

        if ($sent > 0) {
            Log::info('Trial expiry warnings dispatched', ['tenants' => $sent]);
        }
    }
}
