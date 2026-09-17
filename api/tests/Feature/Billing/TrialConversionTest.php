<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Jobs\GenerateMonthlyInvoicesJob;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Illuminate\Support\Carbon;

/**
 * A trialing tenant converting to a paid plan.
 *
 * This is not one gap, it is a closed loop, and it is worth stating plainly
 * because the loop is invisible from any single file:
 *
 *   AuthService puts EVERY new tenant on a `trial` subscription.
 *   Nothing anywhere transitioned a subscription from `trial` to `active` —
 *     no job, no command, no listener, no controller. Grep it.
 *   GenerateMonthlyInvoicesJob bills `status = 'active'` only.
 *   changePlan() required `status = 'active'` and returned an error otherwise.
 *
 * So the only route into `active` was `changePlan`, which refused anyone not
 * already there. No tenant could ever reach a billable state, which means the
 * product could not charge anybody at all — and the landing page's "Start Free
 * Trial" led to an account with no way out of the trial. Every test passed
 * throughout, because each one started from a subscription it had created as
 * ACTIVE itself.
 *
 * Revert the `whereIn` in `changePlan()` and the first test here fails on the
 * error branch.
 */
function trialingTenant(int $starterPriceCents = 0): Subscription
{
    $tenant = createTenant();
    $starter = Plan::factory()->create(['name' => 'Starter', 'price_cents' => $starterPriceCents]);

    return Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $starter->id,
        'price_cents' => $starterPriceCents,
        'status' => SubscriptionStatus::TRIAL,
        'current_period_start' => Carbon::now(),
        // Six months, as AuthService sets it.
        'current_period_end' => Carbon::now()->addMonths(6),
    ]);
}

it('lets a trialing tenant move to a paid plan', function () {
    $subscription = trialingTenant();
    $professional = Plan::factory()->create(['name' => 'Professional', 'price_cents' => 99900]);

    $result = app(BillingService::class)->changePlan($subscription->tenant, $professional);

    expect($result)->not->toHaveKey(
        'error',
        'A trialing tenant could not convert — the only path out of a trial is still closed.'
    )->and($result['new_plan'])->toBe('Professional');
});

it('makes the converted subscription billable', function () {
    $subscription = trialingTenant();
    $professional = Plan::factory()->create(['price_cents' => 99900]);

    app(BillingService::class)->changePlan($subscription->tenant, $professional);
    $subscription->refresh();

    // ACTIVE is not cosmetic: it is the only status GenerateMonthlyInvoicesJob
    // looks at. A conversion that left the row on `trial` would take the money
    // decision and still never invoice.
    expect($subscription->status)->toBe(SubscriptionStatus::ACTIVE)
        ->and($subscription->price_cents)->toBe(99900);

    (new GenerateMonthlyInvoicesJob)->handle(app(BillingService::class));

    expect(Invoice::withoutGlobalScopes()->where('tenant_id', $subscription->tenant_id)->count())
        ->toBe(1, 'A converted tenant was still not invoiced.');
});

it('charges nothing to convert', function () {
    Carbon::setTestNow('2026-06-01');

    $subscription = trialingTenant();
    $professional = Plan::factory()->create(['price_cents' => 99900]);

    $result = app(BillingService::class)->changePlan($subscription->tenant, $professional);

    // Nothing was paid for the trial, so there is no balance to prorate
    // against. Running a trial through the normal proration scales the new plan
    // by the unused fraction of a SIX-MONTH period — near 1.0 on day one — and
    // bills most of a full period for pressing Upgrade.
    expect($result['proration_cents'])->toBe(
        0,
        'Converting a trial produced a charge — the trial period was prorated as if it had been paid for.'
    );

    Carbon::setTestNow();
});

it('starts a monthly period at conversion rather than inheriting the trial', function () {
    Carbon::setTestNow('2026-06-01');

    $subscription = trialingTenant();
    $professional = Plan::factory()->create(['price_cents' => 99900]);

    app(BillingService::class)->changePlan($subscription->tenant, $professional);
    $subscription->refresh();

    // Carrying over trial_ends_at would leave the first paid period running
    // five more months, and every proration in between would divide by it.
    expect($subscription->current_period_end->toDateString())->toBe('2026-07-01');

    Carbon::setTestNow();
});

it('prorates normally once the subscription is already paid', function () {
    Carbon::setTestNow('2026-06-16');

    $subscription = trialingTenant();
    $professional = Plan::factory()->create(['price_cents' => 99900]);
    app(BillingService::class)->changePlan($subscription->tenant, $professional);

    // Second change, now from ACTIVE: the zero-proration rule must be specific
    // to converting a trial, not a hole that makes every later change free.
    $enterprise = Plan::factory()->create(['price_cents' => 299900]);
    $result = app(BillingService::class)->changePlan($subscription->tenant, $enterprise);

    expect($result['proration_cents'])->toBeGreaterThan(
        0,
        'An upgrade from a paid plan was free — the trial branch is catching paid subscriptions too.'
    );

    Carbon::setTestNow();
});

it('does not start billing a tenant who converts onto a free plan', function () {
    $subscription = trialingTenant();
    $free = Plan::factory()->create(['name' => 'Starter', 'price_cents' => 0]);

    app(BillingService::class)->changePlan($subscription->tenant, $free);
    (new GenerateMonthlyInvoicesJob)->handle(app(BillingService::class));

    // The conversion is the first route into ACTIVE, so it is also the first
    // route to a 0.00 invoice — and dunning suspends on one.
    expect(Invoice::withoutGlobalScopes()->where('tenant_id', $subscription->tenant_id)->count())
        ->toBe(0, 'Converting onto a free plan started billing the tenant 0.00 a month.');
});

it('still refuses a tenant with no subscription at all', function () {
    $tenant = createTenant();
    $plan = Plan::factory()->create(['price_cents' => 99900]);

    // The guard is loosened, not removed.
    expect(app(BillingService::class)->changePlan($tenant, $plan))->toHaveKey('error');
});

it('still refuses a cancelled subscription', function () {
    $subscription = trialingTenant();
    $subscription->update(['status' => SubscriptionStatus::CANCELLED]);

    $plan = Plan::factory()->create(['price_cents' => 99900]);

    expect(app(BillingService::class)->changePlan($subscription->tenant, $plan))->toHaveKey('error');
});
