<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Illuminate\Support\Carbon;

/**
 * Proration arithmetic when a tenant changes plan.
 *
 * `BillingService::changePlan()` computes what the switch costs by scaling both
 * plans by the fraction of the billing period still unused. It is money
 * arithmetic on a customer-facing number and had no direct test — `BASELINE`
 * §12 listed `BillingService` among the untested, and the first tests written
 * against that file found the double-invoicing defect in §15b.
 *
 * Two properties are worth pinning beyond the happy path:
 *
 *  - **Sign.** An upgrade should cost money and a downgrade should credit it.
 *    Getting this backwards is the kind of error that reaches an invoice.
 *  - **Bounds.** The proration factor is `daysRemaining / totalDays`, and
 *    Carbon 3 returns *signed* floats from `diffInDays` — `now()->diffInDays()`
 *    is negative for a past date. A subscription still marked ACTIVE past its
 *    `current_period_end` therefore yields a negative factor, which inverts the
 *    whole calculation. That state is reachable whenever renewal has not run,
 *    which on shared hosting means whenever the cron stopped — the exact failure
 *    `ethr:queue:check` exists to catch.
 */
function subscriptionSpanning(Carbon $start, Carbon $end, int $priceCents = 100000): Subscription
{
    $tenant = createTenant();
    $plan = Plan::factory()->create(['name' => 'Starter', 'price_cents' => $priceCents]);

    return Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::ACTIVE,
        'current_period_start' => $start,
        'current_period_end' => $end,
    ]);
}

it('charges the difference for an upgrade taken mid-period', function () {
    Carbon::setTestNow('2026-06-16');

    // 30-day period, 14 days used, 14 remaining — roughly half.
    $subscription = subscriptionSpanning(Carbon::parse('2026-06-01'), Carbon::parse('2026-07-01'), 100000);
    $premium = Plan::factory()->create(['name' => 'Premium', 'price_cents' => 300000]);

    $result = app(BillingService::class)->changePlan($subscription->tenant, $premium);

    expect($result)->not->toHaveKey('error')
        ->and($result['new_plan'])->toBe('Premium');

    // Upgrading costs money: the unused half of the more expensive plan minus
    // the unused half of the cheaper one.
    expect($result['proration_cents'])->toBeGreaterThan(0);

    $subscription->refresh();
    expect($subscription->plan_id)->toBe($premium->id);

    Carbon::setTestNow();
});

it('credits the difference for a downgrade', function () {
    Carbon::setTestNow('2026-06-16');

    $subscription = subscriptionSpanning(Carbon::parse('2026-06-01'), Carbon::parse('2026-07-01'), 300000);
    $starter = Plan::factory()->create(['name' => 'Cheaper', 'price_cents' => 100000]);

    $result = app(BillingService::class)->changePlan($subscription->tenant, $starter);

    // Sign matters. A downgrade that charged instead of credited would reach an
    // invoice looking entirely plausible.
    expect($result['proration_cents'])->toBeLessThan(0);

    Carbon::setTestNow();
});

it('does not invert the charge when the period has already ended', function () {
    Carbon::setTestNow('2026-07-20');

    // ACTIVE but past its period end — reachable whenever renewal has not run,
    // which on shared hosting means whenever the scheduler stopped.
    $subscription = subscriptionSpanning(Carbon::parse('2026-06-01'), Carbon::parse('2026-07-01'), 100000);
    $premium = Plan::factory()->create(['name' => 'Premium', 'price_cents' => 300000]);

    $result = app(BillingService::class)->changePlan($subscription->tenant, $premium);

    // Carbon 3's diffInDays is signed, so daysRemaining goes negative here and
    // an UPGRADE would be reported as a CREDIT. Nothing is left of the period,
    // so the honest answer is zero.
    expect($result['proration_cents'])->toBe(
        0,
        'An expired period produced a non-zero proration — the factor is unclamped.'
    );

    Carbon::setTestNow();
});

it('does not charge more than a full period', function () {
    Carbon::setTestNow('2026-05-01');

    // Period starts in the future — a scheduling or data error, but it must not
    // scale the charge above one whole period.
    $subscription = subscriptionSpanning(Carbon::parse('2026-06-01'), Carbon::parse('2026-07-01'), 100000);
    $premium = Plan::factory()->create(['name' => 'Premium', 'price_cents' => 300000]);

    $result = app(BillingService::class)->changePlan($subscription->tenant, $premium);

    expect($result['proration_cents'])->toBeLessThanOrEqual(
        200000,
        'Proration exceeded the full difference between the two plans.'
    );

    Carbon::setTestNow();
});

it('reports no proration for a change to the same price', function () {
    Carbon::setTestNow('2026-06-16');

    $subscription = subscriptionSpanning(Carbon::parse('2026-06-01'), Carbon::parse('2026-07-01'), 100000);
    $sidegrade = Plan::factory()->create(['name' => 'Sidegrade', 'price_cents' => 100000]);

    expect(app(BillingService::class)->changePlan($subscription->tenant, $sidegrade)['proration_cents'])->toBe(0);

    Carbon::setTestNow();
});

it('refuses when there is no active subscription', function () {
    $tenant = createTenant();
    $plan = Plan::factory()->create(['price_cents' => 100000]);

    expect(app(BillingService::class)->changePlan($tenant, $plan))->toHaveKey('error');
});
