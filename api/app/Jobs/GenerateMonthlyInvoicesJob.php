<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SystemAlertNotification;
use App\Services\Billing\BillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateMonthlyInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(BillingService $billing): void
    {
        $subscriptions = Subscription::withoutGlobalScopes()
            ->where('status', SubscriptionStatus::ACTIVE)
            ->with('tenant')
            ->get();

        foreach ($subscriptions as $subscription) {
            try {
                if ($subscription->tenant) {
                    $billing->generateMonthlyInvoice($subscription->tenant);
                }
            } catch (Throwable $e) {
                Log::error('Failed to generate invoice for tenant', [
                    'tenant_id' => $subscription->tenant_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('GenerateMonthlyInvoicesJob failed entirely — monthly invoices may be missing', [
            'error' => $exception->getMessage(),
        ]);

        $superAdmins = User::withoutGlobalScopes()
            ->where('role', UserRole::SUPER_ADMIN->value)
            ->get();

        foreach ($superAdmins as $admin) {
            $admin->notify(new SystemAlertNotification(
                'Monthly invoice generation failed',
                "GenerateMonthlyInvoicesJob failed after exhausting retries: {$exception->getMessage()}. Some tenants may not have received their monthly invoice — check the failed jobs dashboard and re-run manually if needed.",
                ['error' => $exception->getMessage()],
            ));
        }
    }
}
