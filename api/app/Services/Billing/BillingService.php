<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use Carbon\Carbon;

final class BillingService
{
    public function changePlan(Tenant $tenant, Plan $newPlan): array
    {
        $subscription = Subscription::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('status', SubscriptionStatus::ACTIVE)
            ->first();

        if (! $subscription) {
            return ['error' => 'No active subscription'];
        }

        $oldPlan = $subscription->plan;
        $daysRemaining = Carbon::now()->diffInDays($subscription->current_period_end);
        $totalDays = $subscription->current_period_start->diffInDays($subscription->current_period_end);
        $prorationFactor = $totalDays > 0 ? $daysRemaining / $totalDays : 0;

        $oldRemaining = (int) round(($oldPlan->price_cents ?? 0) * $prorationFactor);
        $newRemaining = (int) round(($newPlan->price_cents ?? 0) * $prorationFactor);
        $prorationAmount = $newRemaining - $oldRemaining;

        $subscription->update(['plan_id' => $newPlan->id]);

        return [
            'old_plan' => $oldPlan->name ?? 'Unknown',
            'new_plan' => $newPlan->name,
            'proration_cents' => $prorationAmount,
            'effective_immediately' => true,
        ];
    }

    public function generateMonthlyInvoice(Tenant $tenant): ?Invoice
    {
        $subscription = Subscription::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('status', SubscriptionStatus::ACTIVE)
            ->with('plan')
            ->first();

        if (! $subscription || ! $subscription->plan) {
            return null;
        }

        $plan = $subscription->plan;
        $amount = $plan->price_cents ?? 0;

        $lineItems = [
            ['description' => $plan->name.' — Monthly', 'amount_cents' => $amount],
        ];

        return Invoice::create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'amount_cents' => $amount,
            'tax_cents' => 0,
            'total_cents' => $amount,
            'status' => 'draft',
            'line_items' => $lineItems,
            'due_date' => Carbon::now()->addDays(15),
        ]);
    }

    public function dashboard(Tenant $tenant): array
    {
        $subscription = Subscription::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->with('plan')
            ->latest()
            ->first();

        $invoices = Invoice::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn (Invoice $i) => [
                'public_id' => $i->public_id,
                'total_cents' => $i->total_cents,
                'status' => $i->status,
                'due_date' => $i->due_date?->format('Y-m-d'),
                'paid_at' => $i->paid_at,
            ]);

        return [
            'plan' => $subscription?->plan?->name,
            'plan_price_cents' => $subscription?->plan?->price_cents,
            'subscription_status' => $subscription?->status?->value,
            'current_period_end' => $subscription?->current_period_end,
            'invoices' => $invoices,
        ];
    }
}
