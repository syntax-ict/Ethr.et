<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Jobs\GenerateMonthlyInvoicesJob;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingService;

/**
 * Monthly invoicing must not bill a tenant twice for the same month.
 *
 * `GenerateMonthlyInvoicesJob` iterates every active subscription and calls
 * `BillingService::generateMonthlyInvoice()`, which created an `Invoice`
 * unconditionally. Neither side checked whether the tenant had already been
 * invoiced, so any second execution billed every active tenant again.
 *
 * That was not hypothetical. Until 2026-09-15 this job declared no `$timeout`
 * at all, so it inherited the worker's 60s default while the database queue's
 * `retry_after` was 90s. A run over 90 seconds — plausible, since it walks every
 * subscription — was re-reserved and executed a second time while the first was
 * still going. Both fixes landed the same day; this is the half that survives
 * the queue being configured correctly.
 *
 * Re-running by hand is the other route, and the job's own `failed()` handler
 * recommends it: *"re-run manually if needed"*. Without a guard that advice
 * double-bills everyone who was already invoiced before the failure.
 *
 * Duplicate invoices are not a cosmetic defect in an HR product. They reach
 * customers, and they are the kind of error that costs more trust to fix than
 * the money involved.
 */
function activeSubscription(): Subscription
{
    $tenant = createTenant();

    $plan = Plan::factory()->create(['price_cents' => 250000]);

    return Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::ACTIVE,
    ]);
}

it('does not invoice the same tenant twice in one month', function () {
    $subscription = activeSubscription();
    $billing = app(BillingService::class);

    $first = $billing->generateMonthlyInvoice($subscription->tenant);
    $second = $billing->generateMonthlyInvoice($subscription->tenant);

    expect($first)->not->toBeNull();

    // The second call must decline rather than create a duplicate.
    expect(Invoice::withoutGlobalScopes()->where('tenant_id', $subscription->tenant_id)->count())
        ->toBe(1, 'A second run in the same month created a duplicate invoice.');

    // Returning the existing invoice is more useful to a caller than null: it
    // lets the job log what already covered the period.
    expect($second?->id)->toBe($first->id);
});

it('does not double-bill when the whole job runs twice', function () {
    $subscription = activeSubscription();

    // Exactly what a re-reserved job, a manual re-run, or a cron misfire does.
    (new GenerateMonthlyInvoicesJob)->handle(app(BillingService::class));
    (new GenerateMonthlyInvoicesJob)->handle(app(BillingService::class));

    expect(Invoice::withoutGlobalScopes()->where('tenant_id', $subscription->tenant_id)->count())
        ->toBe(1, 'Running the job twice billed the tenant twice.');
});

it('invoices again in the following month', function () {
    $subscription = activeSubscription();
    $billing = app(BillingService::class);

    $billing->generateMonthlyInvoice($subscription->tenant);

    // The guard must not be so broad that it stops billing altogether — which
    // would be the worse failure, and a silent one.
    $this->travel(1)->month();

    $billing->generateMonthlyInvoice($subscription->tenant);

    expect(Invoice::withoutGlobalScopes()->where('tenant_id', $subscription->tenant_id)->count())->toBe(2);
});

it('still declines when there is no active subscription', function () {
    $tenant = createTenant();

    expect(app(BillingService::class)->generateMonthlyInvoice($tenant))->toBeNull()
        ->and(Invoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('bills each tenant separately', function () {
    $alpha = activeSubscription();
    $beta = activeSubscription();

    (new GenerateMonthlyInvoicesJob)->handle(app(BillingService::class));

    // The guard keys on the subscription, so one tenant's invoice must not
    // suppress another's.
    expect(Invoice::withoutGlobalScopes()->where('tenant_id', $alpha->tenant_id)->count())->toBe(1)
        ->and(Invoice::withoutGlobalScopes()->where('tenant_id', $beta->tenant_id)->count())->toBe(1);
});
