<?php

declare(strict_types=1);

use App\Models\Plan;

/**
 * The owner made a custom domain the Enterprise tier of an organisation's
 * address (decision 2026-10-08). PlanSeeder writes `custom_domain` onto
 * Enterprise, but it uses firstOrCreate, so a deployment whose plans were
 * already seeded never gains it: every domain assignment there would be
 * refused until someone edited the plan by hand.
 *
 * This migration closes that gap on the one plan the decision names, and
 * touches nothing else.
 */
function enterpriseDomainMigration(): object
{
    return require database_path('migrations/2026_10_08_000002_add_custom_domain_to_enterprise_plan.php');
}

function seededPlan(string $slug, ?array $features): Plan
{
    $plan = Plan::factory()->create(['slug' => $slug]);
    // Saved through the query builder, so a null list stays NULL in the column.
    Plan::query()->whereKey($plan->id)->update(['features' => $features === null ? null : json_encode($features)]);

    return $plan->refresh();
}

it('adds custom_domain to an already-seeded Enterprise plan', function () {
    $plan = seededPlan('enterprise', ['attendance', 'payroll', 'audit_log']);

    enterpriseDomainMigration()->up();

    expect($plan->refresh()->features)->toBe(['attendance', 'payroll', 'audit_log', 'custom_domain']);
});

it('is idempotent', function () {
    $plan = seededPlan('enterprise', ['attendance', 'custom_domain']);

    enterpriseDomainMigration()->up();
    enterpriseDomainMigration()->up();

    expect($plan->refresh()->features)->toBe(['attendance', 'custom_domain']);
});

it('touches no other plan', function () {
    $starter = seededPlan('starter', ['attendance']);
    $professional = seededPlan('professional', ['attendance', 'payroll']);

    enterpriseDomainMigration()->up();

    expect($starter->refresh()->features)->toBe(['attendance'])
        ->and($professional->refresh()->features)->toBe(['attendance', 'payroll']);
});

it('leaves an Enterprise plan with no feature list alone, rather than turning unlimited into one feature', function () {
    $plan = seededPlan('enterprise', null);

    enterpriseDomainMigration()->up();

    expect($plan->refresh()->features)->toBeNull();
});

it('takes the add-on away again on rollback', function () {
    $plan = seededPlan('enterprise', ['attendance', 'custom_domain']);

    enterpriseDomainMigration()->down();

    expect($plan->refresh()->features)->toBe(['attendance']);
});
