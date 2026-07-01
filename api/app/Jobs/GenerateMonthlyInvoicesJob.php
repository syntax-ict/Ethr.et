<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
            } catch (\Throwable $e) {
                Log::error('Failed to generate invoice for tenant', [
                    'tenant_id' => $subscription->tenant_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
