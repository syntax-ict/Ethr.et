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
        // Clamped to [0, 1]. Unclamped this produced two money errors that reach
        // customers, both measured in tests/Feature/Billing/PlanChangeProrationTest.php:
        //
        //   Expired period   Carbon 3 returns SIGNED floats from diffInDays, so
        //                    a current_period_end in the past makes daysRemaining
        //                    negative and inverts the whole calculation. An
        //                    upgrade from a 1,000.00 plan to a 3,000.00 plan
        //                    reported a CREDIT of 1,266.67 instead of a charge.
        //                    That state is reachable whenever renewal has not
        //                    run - on shared hosting, whenever the scheduler
        //                    stopped, which is what ethr:queue:check watches for.
        //
        //   Future period    A current_period_start ahead of now makes the factor
        //                    exceed 1, charging 4,066.67 where the entire
        //                    difference between the two plans is 2,000.00.
        //
        // Zero is the honest answer for a period with nothing left in it, and a
        // whole period is the most that can be owed.
        $prorationFactor = $totalDays > 0
            ? max(0.0, min(1.0, $daysRemaining / $totalDays))
            : 0;

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

        // Idempotency. Neither this method nor GenerateMonthlyInvoicesJob used to
        // check, so any second execution billed every active tenant again.
        //
        // That was reachable in practice: until 2026-09-15 the job declared no
        // $timeout, inheriting the worker's 60s default against a database queue
        // retry_after of 90s, so a run over 90 seconds was re-reserved and ran
        // twice. The job's own failed() handler also tells operators to "re-run
        // manually if needed", which without this guard double-bills everyone
        // already invoiced before the failure.
        //
        // Keyed on the calendar month because there is no billing-period column
        // to key on, and the schedule is monthlyOn(1). That is correct for the
        // real failure - a re-run within the same month - and honest about its
        // limit: a run starting at 23:59 on the last day and retrying after
        // midnight would still produce two. A `period` column on invoices is the
        // fuller fix and a schema change; recorded rather than smuggled in here.
        $existing = Invoice::withoutGlobalScope('tenant')
            ->where('subscription_id', $subscription->id)
            ->whereBetween('created_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->first();

        if ($existing) {
            // Returned rather than null so the caller can distinguish "already
            // covered" from "no active subscription" - those need different
            // responses, and null would conflate them.
            return $existing;
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
