<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingService;

/**
 * Editing a plan's catalog price must not re-price anyone already on it.
 *
 * This is the test the platform-admin plan catalog is blocked on. Before
 * `subscriptions.price_cents` existed, `generateMonthlyInvoice()` resolved the
 * amount live — `$amount = $plan->price_cents ?? 0` — so the catalog was not a
 * list price at all. It was a standing instruction to bill every subscriber on
 * that plan whatever the number currently said.
 *
 * Shipping an admin price field on top of that means: an admin corrects a
 * marketing figure, every existing subscriber is billed the new amount at the
 * next monthly run with no notice and no grandfathering, and
 * `HandleOverdueInvoicesJob` escalates the resulting non-payment through past
 * due to TENANT SUSPENSION at sixty days. A copy edit locks customers out.
 *
 * Delete the `price_cents` line from `generateMonthlyInvoice()` and the first
 * test here fails with the exact figure that would have been billed.
 */
function subscriberOnPlan(int $priceCents): Subscription
{
    $tenant = createTenant();
    $plan = Plan::factory()->create(['name' => 'Professional', 'price_cents' => $priceCents]);

    return Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'price_cents' => $priceCents,
        'status' => SubscriptionStatus::ACTIVE,
    ]);
}

it('bills the agreed price after the catalog price changes', function () {
    // Signed up at 999.00 ETB.
    $subscription = subscriberOnPlan(99900);

    // The platform admin raises the list price to 2,999.00 — the exact action
    // the admin plan catalog exists to allow.
    $subscription->plan->update(['price_cents' => 299900]);

    $this->travel(1)->month();
    $invoice = app(BillingService::class)->generateMonthlyInvoice($subscription->tenant);

    expect($invoice)->not->toBeNull()
        ->and($invoice->total_cents)->toBe(
            99900,
            'A catalog price edit re-billed an existing subscriber — the invoice read the plan, not the subscription.'
        );
});

it('quotes the new price to the next customer', function () {
    $subscription = subscriberOnPlan(99900);
    $plan = $subscription->plan;

    $plan->update(['price_cents' => 299900]);

    // The other half of the same property: the edit must actually take effect
    // for someone signing up now, or the catalog is not editable, merely inert.
    $newcomer = createTenant();
    Subscription::factory()->create([
        'tenant_id' => $newcomer->id,
        'plan_id' => $plan->id,
        'price_cents' => $plan->price_cents,
        'status' => SubscriptionStatus::ACTIVE,
    ]);

    $invoice = app(BillingService::class)->generateMonthlyInvoice($newcomer);

    expect($invoice?->total_cents)->toBe(299900);
});

it('falls back to the plan for a row that predates price capture', function () {
    $tenant = createTenant();
    $plan = Plan::factory()->create(['price_cents' => 150000]);

    // What the backfill would have found: a subscription with no captured
    // price. The fallback reproduces the old behaviour rather than billing
    // zero, because an invoice that is wrongly zero is the one nobody reports.
    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'price_cents' => null,
        'status' => SubscriptionStatus::ACTIVE,
    ]);

    expect($subscription->effectivePriceCents())->toBe(150000);
    expect(app(BillingService::class)->generateMonthlyInvoice($tenant)?->total_cents)->toBe(150000);
});

it('captures the new price when the subscriber changes plan', function () {
    $subscription = subscriberOnPlan(99900);
    $premium = Plan::factory()->create(['name' => 'Enterprise', 'price_cents' => 299900]);

    app(BillingService::class)->changePlan($subscription->tenant, $premium);

    $subscription->refresh();

    // plan_id and price_cents are written together. A row whose plan and price
    // disagree bills the wrong amount and looks entirely correct in the UI.
    expect($subscription->plan_id)->toBe($premium->id)
        ->and($subscription->price_cents)->toBe(299900);
});

it('credits what the subscriber pays, not what their old plan now costs', function () {
    $subscription = subscriberOnPlan(100000);

    // Same trap from the proration side: after a catalog edit the old plan's
    // price is a stranger's quote. Crediting it refunds money never paid.
    $subscription->plan->update(['price_cents' => 900000]);

    $premium = Plan::factory()->create(['price_cents' => 300000]);
    $result = app(BillingService::class)->changePlan($subscription->tenant, $premium);

    // 300,000 is more than the 100,000 actually being paid, so this is an
    // upgrade and must cost money. Read against the edited plan it would look
    // like a 600,000 downgrade and credit the customer.
    expect($result['proration_cents'])->toBeGreaterThan(
        0,
        'Proration credited the edited catalog price instead of the agreed one.'
    );
});

it('issues no invoice at all for a free plan', function () {
    // Starter is 0 ETB, so this is the ordinary case for it rather than an
    // edge. HandleOverdueInvoicesJob filters on status and due_date and never
    // looks at the amount, so a 0.00 invoice created as `sent` walks the whole
    // dunning ladder and SUSPENDS the tenant at sixty days for failing to pay
    // nothing.
    $subscription = subscriberOnPlan(0);

    expect(app(BillingService::class)->generateMonthlyInvoice($subscription->tenant))->toBeNull()
        ->and(Invoice::withoutGlobalScopes()->where('tenant_id', $subscription->tenant_id)->count())
        ->toBe(0, 'A free plan produced an invoice for 0.00, which dunning will escalate to suspension.');
});

it('does not charge a tenant with no subscription', function () {
    $tenant = createTenant();

    expect(app(BillingService::class)->generateMonthlyInvoice($tenant))->toBeNull()
        ->and(Invoice::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(0);
});
