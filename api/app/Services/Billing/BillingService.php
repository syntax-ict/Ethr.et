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
    /**
     * Move a tenant to a different plan, pricing the switch.
     *
     * Accepts TRIAL as well as ACTIVE, and that is not a convenience. Every
     * tenant is created on a `trial` subscription by `AuthService`, and nothing
     * anywhere transitioned one to `active` — not a job, not a command, not a
     * listener. `GenerateMonthlyInvoicesJob` bills `status = 'active'` only, so
     * with no path into that status the billing pipeline could never charge
     * anybody: not a funnel with a gap in it, a funnel with no exit. This method
     * is that exit, which is why the conversion branch below sets the status
     * rather than leaving it to something else.
     */
    public function changePlan(Tenant $tenant, Plan $newPlan): array
    {
        $subscription = Subscription::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [SubscriptionStatus::ACTIVE, SubscriptionStatus::TRIAL])
            ->first();

        if (! $subscription) {
            // Wording left exactly as it was. Scramble derives the public
            // OpenAPI response from this literal — the generated contract
            // carries `error: "No active subscription"` as a constant — so
            // rephrasing it is an API change. It is still accurate: what is
            // missing now is a subscription that is live at all.
            return ['error' => 'No active subscription'];
        }

        // A trial is converting to a paid subscription. It keeps the shared
        // arithmetic and the shared return below rather than branching into its
        // own: Scramble derives the public OpenAPI response from the literal
        // array at the end of this method — the generated contract already
        // carries `error: "No active subscription"` as a constant — so a second
        // return site risks emitting a second response shape and drifting
        // `generated.ts`. Only the database write differs.
        //
        // Compared against the stored value rather than `$subscription->status`
        // because Larastan resolves cast properties to their raw backing type:
        // it reads this one as `string`, calls `=== SubscriptionStatus::TRIAL`
        // always false, and then both branches below dead code. That is a false
        // positive — the cast is real at runtime, and TrialConversionTest
        // exercises the branch — but the gate fails on it, and
        // phpstan-baseline.neon already records the same limitation for this
        // file. getAttributes() returns the uncast value, which is `mixed`, so
        // the comparison is analysable and still exactly what the database
        // holds.
        $isTrialConversion = ($subscription->getAttributes()['status'] ?? null) === SubscriptionStatus::TRIAL->value;

        $oldPlan = $subscription->plan;

        if ($isTrialConversion) {
            // Nothing was charged for the trial, so no part of it is unused in
            // the sense proration means, and the factor the existing comment
            // calls "the honest answer for a period with nothing left in it" is
            // the honest answer here too.
            //
            // The alternative is worse than untidy: a trial period runs SIX
            // MONTHS, so running a day-one conversion through the calculation
            // below scales the new plan by a factor near 1.0 and bills most of a
            // full period for pressing Upgrade.
            $prorationFactor = 0.0;
        } else {
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
        }

        // The credit side is what this subscriber actually pays, not what their
        // old plan costs today. Those are the same number until someone edits
        // the catalog, and after that the plan's price is a stranger's quote:
        // crediting it would refund money the customer never paid, or withhold
        // money they did.
        $oldRemaining = (int) round($subscription->effectivePriceCents() * $prorationFactor);
        $newRemaining = (int) round($newPlan->price_cents * $prorationFactor);
        $prorationAmount = $newRemaining - $oldRemaining;

        // price_cents is captured here, so a later catalog edit cannot reach
        // this subscriber. It is the same write as plan_id and must not drift
        // from it — a row whose plan and price disagree bills the wrong amount
        // and looks correct in the UI.
        $attributes = [
            'plan_id' => $newPlan->id,
            'price_cents' => $newPlan->price_cents,
        ];

        if ($isTrialConversion) {
            // ACTIVE is the whole point: GenerateMonthlyInvoicesJob bills that
            // status and no other, so a conversion that left the row on `trial`
            // would take the customer's decision and still never invoice.
            //
            // The period restarts rather than carrying over, because a trial's
            // current_period_end is trial_ends_at — half a year out — and
            // inheriting it would delay the first bill by five months and skew
            // every proration in between.
            $attributes['status'] = SubscriptionStatus::ACTIVE;
            $attributes['current_period_start'] = Carbon::now();
            $attributes['current_period_end'] = Carbon::now()->addMonth();
        }

        $subscription->update($attributes);

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

        // The subscriber's captured price, NOT $plan->price_cents. This one line
        // is what makes the plan catalog editable: with the plan read live, an
        // admin changing a marketing number re-billed every existing subscriber
        // at the next monthly run, and dunning escalated the shortfall to tenant
        // suspension at sixty days.
        $amount = $subscription->effectivePriceCents();

        // Nothing owed, nothing issued.
        //
        // Not tidiness — HandleOverdueInvoicesJob filters on `status` and
        // `due_date` and never looks at the amount, so a 0.00 invoice created
        // as `sent` walks the full dunning ladder: reminder at 7 days, subscription
        // marked past_due at 30, TENANT SUSPENDED at 60, for failing to pay
        // nothing. Starter is a free plan, so this is the ordinary case for it,
        // not an edge.
        //
        // It was unreachable until this change: no subscription could ever
        // become ACTIVE (see changePlan), so this method never ran for anyone.
        // Opening the conversion path opens this with it, which is why the
        // guard lands in the same change rather than after it.
        if ($amount <= 0) {
            return null;
        }

        $lineItems = [
            ['description' => $plan->name.' — Monthly', 'amount_cents' => $amount],
        ];

        return Invoice::create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'amount_cents' => $amount,
            'tax_cents' => 0,
            'total_cents' => $amount,

            // `sent`, not `draft` - owner decision, 2026-09-15.
            //
            // This is what starts dunning. HandleOverdueInvoicesJob tier 1
            // matches `status = 'sent'`, and nothing anywhere transitioned an
            // invoice out of `draft`, so before this change no invoice ever
            // entered dunning: no reminders, no past-due subscriptions, and no
            // tenant was ever suspended for non-payment. The whole escalation
            // chain was inert end to end.
            //
            // Worth knowing what is now live: there is still no email step. An
            // invoice is `sent` in the sense that it exists and is visible in
            // the product, not in the sense that anything delivered it. Dunning
            // therefore escalates on a bill the tenant has only seen in-app, and
            // tier 3 SUSPENDS them at 60 days. See DECISIONS.md D-010.
            'status' => 'sent',
            'line_items' => $lineItems,
            // startOfDay(), not a bare addDays(). `due_date` is declared
            // $table->date(), and MySQL truncates the time on insert - so this
            // worked there by accident, not by intent.
            //
            // SQLite does not enforce column types, so the same code stored
            // "2026-09-30 15:59:20". HandleOverdueInvoicesJob compares against
            // Carbon::today()->subDays(7), which binds midnight, and
            // "15:59:20 <= 00:00:00" is false - so an invoice was not picked up
            // on the day it fell 7 days overdue, but on the day after.
            //
            // A whole-day difference in when a customer is reminded, marked past
            // due, and suspended, present in tests and absent in production. The
            // production target is MySQL and the suite runs on SQLite, so this
            // is exactly the divergence worth removing rather than relying on.
            'due_date' => Carbon::now()->addDays(15)->startOfDay(),
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

        // `plan_price_cents` reads the subscription, not the plan: after a
        // catalog edit those differ, and the billing page is the one screen
        // where showing someone else's price is a support ticket at best.
        //
        // This note lives here for the same reason as the one below it. Written
        // beside the array key it became the field's public description in
        // `generated.ts` and failed the contract gate — the exact trap the next
        // paragraph documents, walked into one commit later.
        //
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
            'plan_price_cents' => $subscription?->effectivePriceCents(),
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
