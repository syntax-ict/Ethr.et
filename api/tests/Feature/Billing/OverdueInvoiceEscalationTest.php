<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\HandleOverdueInvoicesJob;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Billing\BillingService;
use Illuminate\Support\Carbon;

/**
 * Dunning: what happens when a tenant stops paying.
 *
 * `HandleOverdueInvoicesJob` escalates in three tiers — remind at 7 days, mark
 * the subscription past-due at 30, suspend the tenant at 60. It suspends
 * customer accounts and had no test at all.
 *
 * Two of these tests document defects rather than desired behaviour, and say so
 * where they do. Both are written from the outside, using invoices shaped the
 * way `BillingService::generateMonthlyInvoice()` actually shapes them, because
 * the defects only appear when you do that rather than constructing an invoice
 * in whatever state the job happens to want.
 */
function overdueTenant(string $invoiceStatus, int $daysPastDue): array
{
    $tenant = createTenant(['status' => TenantStatus::ACTIVE]);
    $plan = Plan::factory()->create(['price_cents' => 100000]);

    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::ACTIVE,
    ]);

    $invoice = Invoice::create([
        'tenant_id' => $tenant->id,
        'subscription_id' => $subscription->id,
        'amount_cents' => 100000,
        'tax_cents' => 0,
        'total_cents' => 100000,
        'status' => $invoiceStatus,
        'line_items' => [],
        'due_date' => Carbon::today()->subDays($daysPastDue),
    ]);

    return [$tenant, $subscription, $invoice];
}

it('marks a sent invoice overdue after seven days', function () {
    [, , $invoice] = overdueTenant('sent', 10);

    (new HandleOverdueInvoicesJob)->handle();

    expect($invoice->refresh()->status)->toBe('overdue');
});

it('marks the subscription past due after thirty days', function () {
    [, $subscription, $invoice] = overdueTenant('sent', 35);

    (new HandleOverdueInvoicesJob)->handle();

    expect($invoice->refresh()->status)->toBe('past_due')
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::PAST_DUE);
});

it('suspends the tenant after sixty days', function () {
    [$tenant] = overdueTenant('past_due', 70);

    (new HandleOverdueInvoicesJob)->handle();

    expect($tenant->refresh()->status)->toBe(TenantStatus::SUSPENDED);
});

it('leaves a paid invoice and its tenant alone', function () {
    [$tenant, $subscription, $invoice] = overdueTenant('paid', 90);

    (new HandleOverdueInvoicesJob)->handle();

    // Dunning must never touch a settled invoice. Suspending a paying customer
    // is the worst outcome available to this job.
    expect($invoice->refresh()->status)->toBe('paid')
        ->and($tenant->refresh()->status)->toBe(TenantStatus::ACTIVE)
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::ACTIVE);
});

it('suspends a long-overdue invoice that was never escalated', function () {
    // The job missed the 7-30 and 30-60 windows entirely — which happens
    // whenever the scheduler stopped, the failure ethr:queue:check now watches
    // for. The invoice is 70 days past due and still `sent`.
    //
    // This used to escalate nowhere. Tiers 1 and 2 are bounded ABOVE (30 and 60
    // days), so both had aged out, and tier 3 matched only `overdue` and
    // `past_due` — so the tenant was never suspended, however long they went
    // without paying. Tier 3 now takes any unpaid status precisely because it is
    // the safety net and cannot assume the tiers before it ran.
    [$tenant, , $invoice] = overdueTenant('sent', 70);

    (new HandleOverdueInvoicesJob)->handle();

    expect($tenant->refresh()->status)->toBe(
        TenantStatus::SUSPENDED,
        'A 70-day-overdue invoice was never escalated because the job missed its earlier windows.'
    );
    expect($invoice->refresh()->status)->not->toBe('sent');
});

it('escalates an invoice the system generated itself, end to end', function () {
    // The test that matters. Everything else in this file constructs an invoice
    // in whatever state it wants to exercise; this one generates a real invoice
    // through BillingService and drives it all the way to suspension, which is
    // the only way to prove the chain is connected.
    //
    // It was previously a `todo` asserting the OPPOSITE: generateMonthlyInvoice()
    // wrote `status => 'draft'`, tier 1 matched `'sent'`, and nothing anywhere
    // transitioned between them - so no invoice this system produced ever
    // entered dunning. Owner decision 2026-09-15: invoices are `sent` on
    // creation. See docs/decisions/DECISIONS.md D-010.
    $tenant = createTenant(['status' => TenantStatus::ACTIVE]);
    $plan = Plan::factory()->create(['price_cents' => 100000]);

    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => SubscriptionStatus::ACTIVE,
    ]);

    $invoice = app(BillingService::class)->generateMonthlyInvoice($tenant);

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('sent', 'A generated invoice must enter dunning.');

    // due_date is set 15 days out, so 22 days on is 7 days overdue.
    $this->travel(22)->days();
    (new HandleOverdueInvoicesJob)->handle();
    expect($invoice->refresh()->status)->toBe('overdue');

    // 30 days past due.
    $this->travel(23)->days();
    (new HandleOverdueInvoicesJob)->handle();
    expect($invoice->refresh()->status)->toBe('past_due')
        ->and($subscription->refresh()->status)->toBe(SubscriptionStatus::PAST_DUE);

    // 60 days past due.
    $this->travel(30)->days();
    (new HandleOverdueInvoicesJob)->handle();
    expect($tenant->refresh()->status)->toBe(TenantStatus::SUSPENDED);
});
