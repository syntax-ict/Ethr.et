<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class HandleOverdueInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Declared rather than inherited: walks overdue invoices at three escalation thresholds.
     *
     * Without this the job silently takes the worker's --timeout (60s by
     * default), and the queue retry_after invariant cannot be computed at all -
     * see tests/Feature/QueueRetryAfterInvariantTest.php.
     */
    public int $timeout = 300;

    public function handle(): void
    {
        $today = Carbon::today();

        // 7+ days overdue: send reminder
        Invoice::withoutGlobalScopes()
            ->where('status', 'sent')
            ->where('due_date', '<=', $today->copy()->subDays(7))
            ->where('due_date', '>', $today->copy()->subDays(30))
            ->with('tenant.users')
            ->get()
            ->each(function (Invoice $invoice) {
                try {
                    $invoice->update(['status' => 'overdue']);
                    // Notify tenant admin (find admin user)
                    // withoutGlobalScopes(): this job runs with no HTTP tenant
                    // context, so BelongsToTenant resolves to whereRaw('0 = 1')
                    // and the relation found nobody, every time. The log below
                    // could therefore never fire at all.
                    $adminUser = User::withoutGlobalScopes()
                        ->where('tenant_id', $invoice->tenant_id)
                        ->where('role', UserRole::TENANT_ADMIN->value)
                        ->first();

                    // Says what actually happened. This previously logged
                    // "Invoice 7-day overdue reminder sent" while sending
                    // nothing - the comment beside it admitted as much ("in
                    // production we'd have a dedicated
                    // OverdueInvoiceNotification").
                    //
                    // That is the fabricated-success pattern this project has
                    // already been bitten by once: AdminTenantController::backup()
                    // told admins "Backup job queued. You will be notified when
                    // the export is ready" without queuing anything. See
                    // BackupTenantJob's header.
                    //
                    // warning, not info: an invoice has gone unpaid for a week
                    // and nobody has been told. The notification itself is not
                    // built - BASELINE §15d.
                    Log::warning('Invoice overdue at 7 days; NO reminder was sent (notification not implemented)', [
                        'invoice' => $invoice->public_id,
                        'tenant_id' => $invoice->tenant_id,
                        'admin_user_id' => $adminUser?->id,
                    ]);
                } catch (Throwable $e) {
                    Log::error('Overdue reminder failed', ['invoice' => $invoice->public_id, 'error' => $e->getMessage()]);
                }
            });

        // 30+ days overdue: mark subscription as past_due
        Invoice::withoutGlobalScopes()
            ->whereIn('status', ['sent', 'overdue'])
            ->where('due_date', '<=', $today->copy()->subDays(30))
            ->where('due_date', '>', $today->copy()->subDays(60))
            ->with('tenant')
            ->get()
            ->each(function (Invoice $invoice) {
                try {
                    $invoice->update(['status' => 'past_due']);

                    Subscription::withoutGlobalScopes()
                        ->where('tenant_id', $invoice->tenant_id)
                        ->where('status', SubscriptionStatus::ACTIVE)
                        ->update(['status' => SubscriptionStatus::PAST_DUE]);
                } catch (Throwable $e) {
                    Log::error('Past-due escalation failed', ['invoice' => $invoice->public_id, 'error' => $e->getMessage()]);
                }
            });

        // 60+ days overdue: suspend tenant.
        //
        // `sent` is in this list deliberately. The earlier tiers are bounded
        // ABOVE - tier 1 stops at 30 days, tier 2 at 60 - so an invoice the job
        // never saw during those windows is still `sent` when it reaches 60 days
        // and matched none of them. Without `sent` here it matched nothing at
        // all, and the tenant was never suspended however long they went without
        // paying.
        //
        // Reachable exactly when the scheduler stopped for a few weeks, which on
        // shared hosting is the failure `ethr:queue:check` exists to catch. This
        // tier is the safety net, so it takes any unpaid status rather than
        // assuming the tiers before it ran.
        Invoice::withoutGlobalScopes()
            ->whereIn('status', ['sent', 'overdue', 'past_due'])
            ->where('due_date', '<=', $today->copy()->subDays(60))
            ->with('tenant')
            ->get()
            ->each(function (Invoice $invoice) {
                try {
                    // Outside the suspension guard on purpose. Nested inside it,
                    // an invoice belonging to an ALREADY-suspended tenant kept
                    // `sent` forever: re-selected every night, and permanently
                    // mislabelled as merely sent while 60+ days overdue. The
                    // invoice's own state does not depend on whether the tenant
                    // still needs suspending.
                    if ($invoice->status === 'sent') {
                        $invoice->update(['status' => 'past_due']);
                    }

                    if ($invoice->tenant && $invoice->tenant->status !== TenantStatus::SUSPENDED) {
                        $invoice->tenant->update(['status' => TenantStatus::SUSPENDED]);
                        Log::warning('Tenant suspended for non-payment', [
                            'tenant_id' => $invoice->tenant_id,
                            'invoice' => $invoice->public_id,
                        ]);
                    }
                } catch (Throwable $e) {
                    Log::error('Suspension failed', ['invoice' => $invoice->public_id, 'error' => $e->getMessage()]);
                }
            });
    }

    public function failed(Throwable $exception): void
    {
        Log::error('HandleOverdueInvoicesJob failed entirely — overdue reminders/escalations/suspensions may not have run', [
            'error' => $exception->getMessage(),
        ]);

        $superAdmins = User::withoutGlobalScopes()
            ->where('role', UserRole::SUPER_ADMIN->value)
            ->get();

        foreach ($superAdmins as $admin) {
            $admin->notify(new SystemAlertNotification(
                'Overdue invoice handling failed',
                "HandleOverdueInvoicesJob failed after exhausting retries: {$exception->getMessage()}. Overdue reminders, past-due escalations, and non-payment suspensions may not have run for this cycle — check the failed jobs dashboard and re-run manually if needed.",
                ['error' => $exception->getMessage()],
            ));
        }
    }
}
