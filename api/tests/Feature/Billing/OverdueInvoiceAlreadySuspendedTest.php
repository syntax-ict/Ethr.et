<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Jobs\HandleOverdueInvoicesJob;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * An invoice must reach a terminal state even when its tenant is already
 * suspended.
 *
 * Found by reading back the diff of the tier-3 fix rather than by a failing
 * test, which is the only reason it is a separate file: the status update sat
 * *inside* the `tenant->status !== SUSPENDED` guard, so an invoice belonging to
 * an already-suspended tenant kept `sent` forever — re-selected on every nightly
 * run, and permanently mislabelled as merely sent while 60+ days overdue.
 *
 * Not a money error. It is a data-truth error, and this job's whole purpose is
 * to record how far a non-payment has escalated.
 */
it('marks the invoice past due even if the tenant was already suspended', function () {
    $tenant = createTenant(['status' => TenantStatus::SUSPENDED]);
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
        'status' => 'sent',
        'line_items' => [],
        'due_date' => Carbon::today()->subDays(70),
    ]);

    (new HandleOverdueInvoicesJob)->handle();

    expect($invoice->refresh()->status)->toBe(
        'past_due',
        'The invoice stayed `sent` because its tenant was already suspended.'
    );

    // The tenant is left exactly as it was. Re-suspending an already-suspended
    // tenant would churn the record and log a fresh suspension every night for
    // one that happened weeks ago.
    expect($tenant->refresh()->status)->toBe(TenantStatus::SUSPENDED);
});

it('is idempotent across repeated nightly runs', function () {
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
        'status' => 'sent',
        'line_items' => [],
        'due_date' => Carbon::today()->subDays(70),
    ]);

    // This job runs every night. Reaching a stable state after the first pass is
    // what stops it re-reporting the same escalation indefinitely.
    (new HandleOverdueInvoicesJob)->handle();
    $afterFirst = [$invoice->refresh()->status, $tenant->refresh()->status];

    (new HandleOverdueInvoicesJob)->handle();

    expect([$invoice->refresh()->status, $tenant->refresh()->status])->toBe($afterFirst);
});
