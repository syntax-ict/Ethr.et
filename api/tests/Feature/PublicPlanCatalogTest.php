<?php

declare(strict_types=1);

use App\Models\Plan;

/**
 * `GET /api/v1/plans` — the only thing the public pricing page reads.
 *
 * Unauthenticated by design, so what it returns is world-readable. That cuts
 * both ways: it must carry enough for the page to stop hardcoding figures, and
 * nothing more.
 */
it('returns only plans that are both active and public', function () {
    Plan::factory()->create(['name' => 'Advertised', 'is_active' => true, 'is_public' => true]);
    Plan::factory()->create(['name' => 'Negotiated', 'is_active' => true, 'is_public' => false]);
    Plan::factory()->create(['name' => 'Withdrawn', 'is_active' => false, 'is_public' => true]);

    $names = collect($this->getJson('/api/v1/plans')->assertOk()->json('data'))->pluck('name');

    // Active and public are different questions. A negotiated plan is
    // subscribable but not advertised; without the distinction an operator must
    // choose between advertising a bespoke price and cancelling the customer on
    // it.
    expect($names)->toContain('Advertised')
        ->and($names)->not->toContain('Negotiated')
        ->and($names)->not->toContain('Withdrawn');
});

it('carries the copy the pricing page would otherwise hardcode', function () {
    Plan::factory()->create([
        'name' => 'Professional',
        'description' => 'For an organisation running payroll.',
        'description_am' => 'ደመወዝ ለሚያስኬድ ድርጅት።',
        'price_cents' => 99900,
        'currency' => 'ETB',
        'marketing_features' => ['Payroll', 'Reports'],
        'marketing_features_am' => ['ደመወዝ', 'ሪፖርቶች'],
        'is_popular' => true,
        'is_active' => true,
        'is_public' => true,
    ]);

    $plan = $this->getJson('/api/v1/plans')->assertOk()->json('data.0');

    expect($plan['currency'])->toBe('ETB')
        ->and($plan['billing_interval'])->toBe('monthly')
        ->and($plan['description_am'])->toBe('ደመወዝ ለሚያስኬድ ድርጅት።')
        ->and($plan['marketing_features'])->toBe(['Payroll', 'Reports'])
        ->and($plan['is_popular'])->toBeTrue();
});

it('publishes exactly the fields the contract promises, and no others', function () {
    Plan::factory()->create(['is_active' => true, 'is_public' => true]);

    $plan = $this->getJson('/api/v1/plans')->assertOk()->json('data.0');

    // Pinned against PlanResource, which exists because returning the model let
    // Scramble type the response from the model's @property block — so
    // generated.ts promised is_active, created_at and updated_at on a payload
    // that never carried them. The drift gate only catches a contract that
    // CHANGES, not one that was never true, so this is where that is caught.
    expect(array_keys($plan))->toBe([
        'public_id',
        'name',
        'slug',
        'description',
        'description_am',
        'price_cents',
        'currency',
        'billing_interval',
        'max_employees',
        'max_branches',
        'max_devices',
        'features',
        'marketing_features',
        'marketing_features_am',
        'is_popular',
        'sort_order',
    ]);
});

it('reports an uncapped plan as null rather than a sentinel', function () {
    Plan::factory()->create([
        'max_employees' => null,
        'max_branches' => null,
        'max_devices' => null,
        'is_active' => true,
        'is_public' => true,
    ]);

    $plan = $this->getJson('/api/v1/plans')->assertOk()->json('data.0');

    // The 999999 sentinel reached the public page as "Up to 999,999 employees".
    expect($plan['max_employees'])->toBeNull()
        ->and($plan['max_branches'])->toBeNull()
        ->and($plan['max_devices'])->toBeNull();
});

it('needs no authentication', function () {
    Plan::factory()->create(['is_active' => true, 'is_public' => true]);

    // The pricing page is read by people who do not have accounts yet; that is
    // the entire point of the endpoint.
    $this->getJson('/api/v1/plans')->assertOk();
});
