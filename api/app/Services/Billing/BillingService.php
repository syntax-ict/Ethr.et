<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlatformSetting;
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

        // The three bank fields below are cast to string rather than passed through.
        // hasPaymentDetails() has already established they are filled, and the cast
        // is what tells Scramble they are non-nullable; left bare, the generated
        // contract declared `string | null` and the frontend would have null-guarded
        // values that cannot be null inside that branch. Kept here rather than beside
        // the array keys because Scramble turns any adjacent comment into the public
        // field description, and this is an implementation note, not API documentation.
        $settings = PlatformSetting::current();

        return [
            'plan' => $subscription?->plan?->name,
            'plan_price_cents' => $subscription?->plan?->price_cents,
            'subscription_status' => $subscription?->status?->value,
            'current_period_end' => $subscription?->current_period_end,
            'invoices' => $invoices,

            // Trial state. Every tenant starts on a trial and most have no
            // subscription row at all, so without these the billing page rendered
            // "No plan" and three em-dashes for the whole trial — on the one screen
            // that is supposed to answer "what am I on, and when do I pay?".
            'tenant_status' => $tenant->status->value,
            'trial_ends_at' => $tenant->trial_ends_at?->format('Y-m-d'),
            'trial_days_remaining' => $this->trialDaysRemaining($tenant),

            // Where to pay. Owned by the super admin, not hardcoded in the client
            // and not a translation key — a payment destination must not vary by
            // UI language.
            'payment_details' => $settings->hasPaymentDetails() ? [
                'bank_name' => (string) $settings->bank_name,
                'account_number' => (string) $settings->bank_account_number,
                'account_name' => (string) $settings->bank_account_name,
                'instructions' => $settings->payment_instructions,
                'instructions_am' => $settings->payment_instructions_am,
            ] : null,
        ];
    }

    /**
     * Whole days until the trial ends, or null when the tenant has no trial.
     *
     * Extracted purely so the return type is declared natively. Scramble infers
     * `?int` from a real signature, but could not read the inline
     * `max(0, (int) …diffInDays(…))` expression and emitted
     * `Record<string, never> | null` into the generated TypeScript — a type the
     * frontend could not do arithmetic on. Same reason `ExposesPhotoUrls` returns
     * `?string` from a method instead of an array shape.
     *
     * Floored at zero so an expired trial reads "0 days left" rather than a
     * negative countdown.
     */
    private function trialDaysRemaining(Tenant $tenant): ?int
    {
        if ($tenant->trial_ends_at === null) {
            return null;
        }

        return max(0, (int) Carbon::today()->diffInDays($tenant->trial_ends_at, false));
    }
}
