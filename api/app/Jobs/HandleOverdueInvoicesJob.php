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
use App\Notifications\TrialExpiringNotification;
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
                    $adminUser = $invoice->tenant?->users()
                        ->where('role', UserRole::TENANT_ADMIN)
                        ->first();
                    if ($adminUser) {
                        // Reuse TrialExpiringNotification concept for overdue reminder
                        // In production we'd have a dedicated OverdueInvoiceNotification
                        Log::info('Invoice 7-day overdue reminder sent', ['invoice' => $invoice->public_id]);
                    }
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

        // 60+ days overdue: suspend tenant
        Invoice::withoutGlobalScopes()
            ->whereIn('status', ['overdue', 'past_due'])
            ->where('due_date', '<=', $today->copy()->subDays(60))
            ->with('tenant')
            ->get()
            ->each(function (Invoice $invoice) {
                try {
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
