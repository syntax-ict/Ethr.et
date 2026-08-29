<?php

declare(strict_types=1);

use App\Enums\PlanFeature;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\PlanFeatureService;
use Database\Seeders\PlanSeeder;

/**
 * `Plan.features` listed a different capability set per tier and was read by
 * nothing but the pricing page, so every tenant had every feature regardless of
 * what it paid for — a Starter tenant could run payroll, register webhooks and
 * read the audit log.
 *
 * Most of these cases are about the gate NOT firing. That is deliberate: a
 * billing control that is wrong in the closed direction locks paying customers
 * out of features they already use, which is worse than the leak it fixes.
 */
function planWithFeatures(array $features, string $slug = 'test-plan'): Plan
{
    return Plan::factory()->create([
        'slug' => $slug,
        'features' => $features,
        'max_employees' => 999999,
        'max_branches' => 999,
        'max_devices' => 999,
    ]);
}

function subscribeTenant(Tenant $tenant, Plan $plan): void
{
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
    ]);

    $tenant->update(['status' => TenantStatus::ACTIVE]);
    $tenant->refresh()->load('subscription.plan');
}

describe('the gate itself', function () {
    test('a plan that lists the feature allows it', function () {
        $tenant = createTenant();
        subscribeTenant($tenant, planWithFeatures(['attendance', 'payroll']));

        expect(app(PlanFeatureService::class)->allows($tenant, PlanFeature::Payroll))->toBeTrue();
    });

    test('a plan that omits the feature refuses it', function () {
        $tenant = createTenant();
        subscribeTenant($tenant, planWithFeatures(['attendance', 'leave', 'employee_management']));

        expect(app(PlanFeatureService::class)->allows($tenant, PlanFeature::Payroll))->toBeFalse();
    });

    test('an explicitly empty feature list refuses everything', function () {
        // Distinct from a NULL column — see the fail-open cases below. An empty
        // array is a decision that was recorded; NULL is the absence of one.
        $tenant = createTenant();
        subscribeTenant($tenant, planWithFeatures([]));

        expect(app(PlanFeatureService::class)->allows($tenant, PlanFeature::Payroll))->toBeFalse();
    });
});

describe('fail-open safeguards', function () {
    test('a trial tenant gets every feature regardless of its plan', function () {
        // AuthService puts every new tenant on Starter for a 6-month trial.
        // Gating evaluation to Starter's three features would make the trial
        // useless to a prospect assessing payroll — the most likely reason
        // they are evaluating an Ethiopian HCM product at all.
        $tenant = createTenant();
        subscribeTenant($tenant, planWithFeatures(['attendance']));
        $tenant->update(['status' => TenantStatus::TRIAL]);

        expect(app(PlanFeatureService::class)->allows($tenant->refresh(), PlanFeature::Payroll))->toBeTrue();
    });

    test('a tenant with no subscription is unlimited, not locked out', function () {
        // The demo tenant carries 150 employees and no subscription row.
        $tenant = createTenant();
        $tenant->update(['status' => TenantStatus::ACTIVE]);

        expect(app(PlanFeatureService::class)->allows($tenant->refresh(), PlanFeature::Payroll))->toBeTrue();
    });

    test('a plan with a null feature list is unlimited, not empty', function () {
        // A deployment seeded before the column existed must not lose access
        // the moment this gate ships.
        $tenant = createTenant();
        subscribeTenant($tenant, planWithFeatures(features: [], slug: 'legacy'));
        $tenant->subscription->plan->update(['features' => null]);

        expect(app(PlanFeatureService::class)->allows($tenant->refresh(), PlanFeature::Payroll))->toBeTrue();
    });
});

describe('route enforcement', function () {
    test('a Starter tenant is refused payroll processing', function () {
        $tenant = createTenant();
        subscribeTenant($tenant, planWithFeatures(['attendance', 'leave', 'employee_management']));
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ])->assertForbidden();
    });

    test('a Professional tenant may process payroll', function () {
        $tenant = createTenant();
        subscribeTenant($tenant, planWithFeatures(['attendance', 'payroll']));
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        // Not asserting success — payroll needs employees, a period and an
        // idempotency key. Asserting only that the plan gate is not what stops
        // it, which is the single thing this test is about.
        $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ]);

        expect($response->status())->not->toBe(403);
    });

    test('reading past payroll survives a downgrade', function () {
        // The reason writes are gated and reads are not: withdrawing sight of a
        // period an employee was already paid for is a records problem, not a
        // billing control.
        $tenant = createTenant();
        subscribeTenant($tenant, planWithFeatures(['attendance']));
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs")
            ->assertOk();
    });

    test('a Starter tenant is refused webhook creation but may still delete one', function () {
        $tenant = createTenant();
        subscribeTenant($tenant, planWithFeatures(['attendance']));
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks", [
            'url' => 'https://example.com/hook',
            'events' => ['employee.created'],
        ])->assertForbidden();

        // A tenant must always be able to switch off an endpoint still
        // receiving its data. 404 here (the id is fabricated) is the point:
        // the request reached route-model binding instead of the plan gate.
        $delete = test()->deleteJson(
            "http://{$tenant->subdomain}.ethr.test/api/v1/webhooks/01HZZZZZZZZZZZZZZZZZZZZZZZ"
        );

        expect($delete->status())->not->toBe(403);
    });

    test('the audit log is the one read that is gated', function () {
        $tenant = createTenant();
        subscribeTenant($tenant, planWithFeatures(['attendance']));
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/audit-logs")
            ->assertForbidden();
    });

    test('an Enterprise tenant may read the audit log', function () {
        $tenant = createTenant();
        subscribeTenant($tenant, planWithFeatures(['attendance', 'audit_log']));
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/audit-logs")
            ->assertOk();
    });
});

/**
 * The enum exists so a typo in a route's middleware argument is a fatal
 * ValueError rather than a check that quietly passes. That only holds while the
 * cases match what PlanSeeder writes into the column.
 */
test('every feature key in PlanSeeder maps to a PlanFeature case', function () {
    $this->seed(PlanSeeder::class);

    $seeded = Plan::query()->pluck('features')->flatten()->unique()->values();

    expect($seeded)->not->toBeEmpty();

    foreach ($seeded as $key) {
        expect(PlanFeature::tryFrom($key))->not->toBeNull(
            "PlanSeeder writes '{$key}', which has no PlanFeature case.",
        );
    }
});
