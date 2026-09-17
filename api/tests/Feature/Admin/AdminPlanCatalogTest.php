<?php

declare(strict_types=1);

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Illuminate\Support\Facades\DB;

/**
 * The plan catalog, owned by the platform operator.
 *
 * Everything the public pricing page shows used to be a literal in
 * `pricing-content.tsx` — a price that disagreed with the database by 2.5x,
 * limits five times what `PlanLimitService` enforces, and an invented "99.9%"
 * uptime figure. A wrong number in a component is nobody's job to correct. The
 * point of this surface is that it becomes somebody's.
 *
 * The test that matters most is the last one. Editing a price here must not
 * reach anyone already subscribed — that property is what made it safe to build
 * this screen at all, and it lives in `subscriptions.price_cents`.
 */
function asPlatformOperator(): void
{
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);
}

it('lists every plan, including ones withdrawn from sale', function () {
    asPlatformOperator();

    Plan::factory()->create(['name' => 'Public', 'is_public' => true, 'is_active' => true]);
    Plan::factory()->create(['name' => 'Retired', 'is_public' => false, 'is_active' => false]);

    // This is the screen where a plan gets un-hidden, so hiding hidden plans
    // would remove the control from the only person allowed to use it.
    $this->getJson('/api/v1/admin/plans')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('creates a plan with its marketing copy', function () {
    asPlatformOperator();

    $this->postJson('/api/v1/admin/plans', [
        'name' => 'Growth',
        'slug' => 'growth',
        'description' => 'For a company outgrowing Starter.',
        'description_am' => 'ከStarter ለሚያድግ ኩባንያ።',
        'price_cents' => 149900,
        'currency' => 'ETB',
        'max_employees' => 250,
        'features' => ['attendance', 'payroll'],
        'marketing_features' => ['Payroll', 'Reports'],
        'is_public' => true,
        'sort_order' => 4,
    ])->assertCreated()->assertJsonPath('data.slug', 'growth');

    $plan = Plan::where('slug', 'growth')->firstOrFail();
    expect($plan->price_cents)->toBe(149900)
        ->and($plan->marketing_features)->toBe(['Payroll', 'Reports'])
        ->and($plan->currency)->toBe('ETB');

    $this->assertDatabaseHas('audit_log', ['action' => 'platform.plan.created']);
});

it('refuses a feature key that is not a PlanFeature', function () {
    asPlatformOperator();

    // RequiresPlanFeature compares strings, so a typo here would deny access
    // with no error anywhere — the failure would look like a permissions bug
    // months later.
    $this->postJson('/api/v1/admin/plans', [
        'name' => 'Typo',
        'slug' => 'typo',
        'price_cents' => 1000,
        'features' => ['payrol'],
    ])->assertStatus(422);

    expect(Plan::where('slug', 'typo')->exists())->toBeFalse();
});

it('refuses a billing interval the product does not implement', function () {
    asPlatformOperator();

    // GenerateMonthlyInvoicesJob runs monthlyOn(1) and nothing reads this
    // column. Accepting "yearly" would tell an operator the product supports
    // annual billing, and it would then bill monthly anyway.
    $this->postJson('/api/v1/admin/plans', [
        'name' => 'Annual',
        'slug' => 'annual',
        'price_cents' => 1000,
        'billing_interval' => 'yearly',
    ])->assertStatus(422);
});

it('will not let the slug be changed after creation', function () {
    asPlatformOperator();

    $plan = Plan::factory()->create(['slug' => 'starter']);

    // AuthService looks the starter plan up by slug to put every new tenant on
    // it. Renaming it detaches that lookup silently.
    $this->putJson("/api/v1/admin/plans/{$plan->public_id}", ['slug' => 'starter-v2'])
        ->assertStatus(422);

    expect($plan->fresh()->slug)->toBe('starter');
});

it('retires a plan instead of deleting it', function () {
    asPlatformOperator();

    $plan = Plan::factory()->create(['is_active' => true, 'is_public' => true]);
    $tenant = createTenant();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'price_cents' => $plan->price_cents,
        'status' => SubscriptionStatus::ACTIVE,
    ]);

    $this->deleteJson("/api/v1/admin/plans/{$plan->public_id}")->assertOk();

    // The row must survive: subscriptions.plan_id is a foreign key with no
    // cascade, and the billing dashboard reads $subscription->plan->name.
    $plan->refresh();
    expect($plan->exists)->toBeTrue()
        ->and($plan->is_active)->toBeFalse()
        ->and($plan->is_public)->toBeFalse();

    $this->assertDatabaseHas('audit_log', ['action' => 'platform.plan.retired']);
});

it('records who was on a plan at the moment it was edited', function () {
    asPlatformOperator();

    $plan = Plan::factory()->create(['price_cents' => 99900]);
    foreach ([createTenant(), createTenant()] as $tenant) {
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'price_cents' => 99900,
            'status' => SubscriptionStatus::ACTIVE,
        ]);
    }

    $this->putJson("/api/v1/admin/plans/{$plan->public_id}", ['price_cents' => 149900])
        ->assertOk();

    $entry = DB::table('audit_log')->where('action', 'platform.plan.updated')->latest('id')->first();
    $payload = json_decode((string) $entry->payload, true);

    // Reconstructing this afterwards is impossible once subscriptions move.
    expect($payload['subscribers_on_plan'])->toBe(2)
        // Captured before the write: Eloquent re-syncs $original after save, so
        // reading it afterwards records the change as a no-op.
        ->and($payload['before']['price_cents'])->toBe(99900)
        ->and($payload['after']['price_cents'])->toBe(149900);
});

it('does not let a tenant admin near the catalog', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->getJson('/api/v1/admin/plans')->assertForbidden();
    $this->postJson('/api/v1/admin/plans', ['name' => 'X', 'slug' => 'x', 'price_cents' => 0])
        ->assertForbidden();
});

it('does not re-price an existing subscriber when the catalog price changes', function () {
    asPlatformOperator();

    $plan = Plan::factory()->create(['price_cents' => 99900]);
    $tenant = createTenant();
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'price_cents' => 99900,
        'status' => SubscriptionStatus::ACTIVE,
    ]);

    // The whole reason this screen could not exist before Phase 2: with the
    // invoice reading the plan live, this edit would have tripled the next bill
    // for everyone on the plan, and HandleOverdueInvoicesJob escalates
    // non-payment to tenant suspension at sixty days.
    $this->putJson("/api/v1/admin/plans/{$plan->public_id}", ['price_cents' => 299900])
        ->assertOk();

    $this->travel(1)->month();
    $invoice = app(BillingService::class)->generateMonthlyInvoice($tenant->fresh());

    expect($invoice?->total_cents)->toBe(
        99900,
        'Editing the catalog price re-billed an existing subscriber.'
    );
});
