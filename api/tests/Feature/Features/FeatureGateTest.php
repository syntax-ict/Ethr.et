<?php

declare(strict_types=1);

use App\Enums\Feature;
use App\Enums\FeatureOverrideMode;
use App\Enums\FeatureReason;
use App\Enums\FeatureState;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlatformFeatureState;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantFeatureOverride;
use App\Services\CurrentTenant;
use App\Services\Features\FeatureGate;
use App\Services\PlanFeatureService;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The feature control center's resolver (docs/FEATURE-CONTROL-CENTER-DESIGN.md).
 *
 * The property that matters most is the one PR 1 claims: with no platform rows
 * and no overrides, every answer equals the plan gate's. Everything else is
 * the resolution order in §3, which the truth table below states row by row.
 */
function gatePlan(array $features): Plan
{
    return Plan::factory()->create([
        'slug' => 'gate-'.uniqid(),
        'features' => $features,
        'max_employees' => 999999,
        'max_branches' => 999,
        'max_devices' => 999,
    ]);
}

function gateTenant(?array $planFeatures = ['attendance', 'leave', 'employee_management']): Tenant
{
    $tenant = createTenant();

    if ($planFeatures !== null) {
        Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => gatePlan($planFeatures)->id,
        ]);
    }

    $tenant->update(['status' => TenantStatus::ACTIVE]);

    return $tenant->refresh()->load('subscription.plan');
}

function setPlatformState(Feature $feature, FeatureState $state): void
{
    PlatformFeatureState::query()->updateOrCreate(['key' => $feature->value], ['state' => $state]);
}

function setOverride(Tenant $tenant, Feature $feature, FeatureOverrideMode $mode, mixed $expiresAt = null): void
{
    TenantFeatureOverride::query()->withoutGlobalScope('tenant')->updateOrCreate(
        ['tenant_id' => $tenant->id, 'key' => $feature->value],
        ['mode' => $mode, 'reason' => 'test', 'expires_at' => $expiresAt],
    );
}

/** A gate that has read nothing yet, as the next request or job would get. */
function freshGate(): FeatureGate
{
    app()->forgetScopedInstances();

    return app(FeatureGate::class);
}

describe('resolution order', function () {
    test('decides each combination as §3 states', function (
        FeatureState $state,
        ?FeatureOverrideMode $override,
        bool $planAllows,
        bool $on,
        bool $visible,
        FeatureReason $reason,
    ) {
        $tenant = gateTenant($planAllows ? ['payroll'] : ['attendance']);
        setPlatformState(Feature::Payroll, $state);

        if ($override !== null) {
            setOverride($tenant, Feature::Payroll, $override);
        }

        $decision = freshGate()->decide($tenant, Feature::Payroll);

        expect([$decision->on, $decision->visible, $decision->reason])->toBe([$on, $visible, $reason]);
    })->with([
        // state, override, plan allows => on, visible, reason
        'enabled, none, plan yes' => [FeatureState::Enabled, null, true, true, true, FeatureReason::Enabled],
        'enabled, none, plan no' => [FeatureState::Enabled, null, false, false, false, FeatureReason::Plan],
        'enabled, grant, plan yes' => [FeatureState::Enabled, FeatureOverrideMode::Grant, true, true, true, FeatureReason::Granted],
        'enabled, grant, plan no' => [FeatureState::Enabled, FeatureOverrideMode::Grant, false, true, true, FeatureReason::Granted],
        'enabled, revoke, plan yes' => [FeatureState::Enabled, FeatureOverrideMode::Revoke, true, false, false, FeatureReason::Revoked],
        'enabled, revoke, plan no' => [FeatureState::Enabled, FeatureOverrideMode::Revoke, false, false, false, FeatureReason::Revoked],
        'hidden, none, plan yes' => [FeatureState::Hidden, null, true, true, false, FeatureReason::Enabled],
        'hidden, none, plan no' => [FeatureState::Hidden, null, false, false, false, FeatureReason::Plan],
        'hidden, grant, plan yes' => [FeatureState::Hidden, FeatureOverrideMode::Grant, true, true, false, FeatureReason::Granted],
        'hidden, grant, plan no' => [FeatureState::Hidden, FeatureOverrideMode::Grant, false, true, false, FeatureReason::Granted],
        'hidden, revoke, plan yes' => [FeatureState::Hidden, FeatureOverrideMode::Revoke, true, false, false, FeatureReason::Revoked],
        'hidden, revoke, plan no' => [FeatureState::Hidden, FeatureOverrideMode::Revoke, false, false, false, FeatureReason::Revoked],
        'restricted, none, plan yes' => [FeatureState::Restricted, null, true, false, false, FeatureReason::NotInRollout],
        'restricted, none, plan no' => [FeatureState::Restricted, null, false, false, false, FeatureReason::NotInRollout],
        'restricted, grant, plan yes' => [FeatureState::Restricted, FeatureOverrideMode::Grant, true, true, true, FeatureReason::Granted],
        'restricted, grant, plan no' => [FeatureState::Restricted, FeatureOverrideMode::Grant, false, true, true, FeatureReason::Granted],
        'restricted, revoke, plan yes' => [FeatureState::Restricted, FeatureOverrideMode::Revoke, true, false, false, FeatureReason::Revoked],
        'restricted, revoke, plan no' => [FeatureState::Restricted, FeatureOverrideMode::Revoke, false, false, false, FeatureReason::Revoked],
        'disabled, none, plan yes' => [FeatureState::Disabled, null, true, false, false, FeatureReason::PlatformDisabled],
        'disabled, none, plan no' => [FeatureState::Disabled, null, false, false, false, FeatureReason::PlatformDisabled],
        'disabled, grant, plan yes' => [FeatureState::Disabled, FeatureOverrideMode::Grant, true, false, false, FeatureReason::PlatformDisabled],
        'disabled, grant, plan no' => [FeatureState::Disabled, FeatureOverrideMode::Grant, false, false, false, FeatureReason::PlatformDisabled],
        'disabled, revoke, plan yes' => [FeatureState::Disabled, FeatureOverrideMode::Revoke, true, false, false, FeatureReason::PlatformDisabled],
        'disabled, revoke, plan no' => [FeatureState::Disabled, FeatureOverrideMode::Revoke, false, false, false, FeatureReason::PlatformDisabled],
    ]);

    test('an expired override is ignored, in both directions', function () {
        $granted = gateTenant(['attendance']);
        $revoked = gateTenant(['payroll']);
        setOverride($granted, Feature::Payroll, FeatureOverrideMode::Grant, now()->subMinute());
        setOverride($revoked, Feature::Payroll, FeatureOverrideMode::Revoke, now()->subMinute());

        $gate = freshGate();

        expect($gate->decide($granted, Feature::Payroll)->reason)->toBe(FeatureReason::Plan)
            ->and($gate->decide($revoked, Feature::Payroll)->reason)->toBe(FeatureReason::Enabled);
    });

    test('an override with a future expiry applies', function () {
        $tenant = gateTenant(['attendance']);
        setOverride($tenant, Feature::Payroll, FeatureOverrideMode::Grant, now()->addDay());

        expect(freshGate()->allows($tenant, Feature::Payroll))->toBeTrue();
    });

    test('a grant gives an organisation the custom domain add-on its plan lacks (D1)', function () {
        $tenant = gateTenant(['attendance']);

        expect(freshGate()->allows($tenant, Feature::CustomDomain))->toBeFalse();

        setOverride($tenant, Feature::CustomDomain, FeatureOverrideMode::Grant);

        expect(freshGate()->allows($tenant, Feature::CustomDomain))->toBeTrue();
    });

    test('one organisation\'s override never changes another\'s answer', function () {
        $revoked = gateTenant(['payroll']);
        $other = gateTenant(['payroll']);
        setOverride($revoked, Feature::Payroll, FeatureOverrideMode::Revoke);

        $gate = freshGate();

        expect($gate->allows($revoked, Feature::Payroll))->toBeFalse()
            ->and($gate->allows($other, Feature::Payroll))->toBeTrue();
    });

    test('a revoke holds when another organisation, or none, is resolved', function () {
        // The console and queued jobs ask about an organisation that is not
        // the resolved one. Read through the fail-closed scope, the revoke
        // would vanish and the feature would come back on.
        $revoked = gateTenant(['payroll']);
        setOverride($revoked, Feature::Payroll, FeatureOverrideMode::Revoke);
        $other = gateTenant(['payroll']);

        $gate = freshGate();
        app(CurrentTenant::class)->set($other);

        expect($gate->allows($revoked, Feature::Payroll))->toBeFalse();

        app()->forgetScopedInstances();

        expect(app(FeatureGate::class)->allows($revoked, Feature::Payroll))->toBeFalse();
    });

    test('rows for keys the catalogue does not know are ignored', function () {
        $tenant = gateTenant(['payroll']);
        PlatformFeatureState::query()->create(['key' => 'not_a_feature', 'state' => FeatureState::Disabled]);

        expect(freshGate()->allows($tenant, Feature::Payroll))->toBeTrue();
    });
});

describe('no rows means the plan gate, unchanged', function () {
    test('plan_features from the gate equals the plan service for every seeded plan', function () {
        $this->seed(PlanSeeder::class);

        foreach (Plan::query()->get() as $plan) {
            $tenant = createTenant();
            Subscription::factory()->create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id]);
            $tenant->update(['status' => TenantStatus::ACTIVE]);
            $tenant->refresh()->load('subscription.plan');

            expect(freshGate()->enabledPlanFeatures($tenant))
                ->toBe(app(PlanFeatureService::class)->enabledFor($tenant), "Plan {$plan->slug}");
        }
    });

    test('and for a trial, a tenant with no plan, and a plan with no feature list', function () {
        $trial = gateTenant(['attendance']);
        $trial->update(['status' => TenantStatus::TRIAL]);
        $planless = gateTenant(null);
        $unlisted = gateTenant(['attendance']);
        $unlisted->subscription->plan->update(['features' => null]);
        $unlisted->refresh()->load('subscription.plan');

        foreach ([$trial, $planless, $unlisted] as $tenant) {
            expect(freshGate()->enabledPlanFeatures($tenant))
                ->toBe(app(PlanFeatureService::class)->enabledFor($tenant))
                ->toBeNull();
        }
    });

    test('every feature\'s decision equals the plan service\'s answer', function () {
        $tenant = gateTenant(['attendance', 'payroll', 'reports']);
        $gate = freshGate();

        foreach (Feature::cases() as $feature) {
            expect($gate->allows($tenant, $feature))
                ->toBe(app(PlanFeatureService::class)->allows($tenant, $feature->planFeature()), $feature->value);
        }
    });
});

describe('the route gate', function () {
    test('a platform switch refuses the write with its own problem type', function () {
        $tenant = gateTenant(['payroll']);
        setPlatformState(Feature::Payroll, FeatureState::Disabled);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ])
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://ethr.et/errors/feature-unavailable')
            ->assertJsonPath('code', 'feature-unavailable')
            ->assertJsonPath('reason', 'platform_disabled')
            ->assertJsonPath('detail', __('billing.feature_unavailable', ['feature' => __('billing.features.payroll')]));
    });

    test('a plan refusal keeps the plan gate\'s own answer', function () {
        $tenant = gateTenant(['attendance']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ])
            ->assertForbidden()
            ->assertJsonPath('type', 'https://ethr.et/errors/http')
            ->assertJsonPath('detail', __('billing.feature_not_in_plan', ['feature' => __('billing.features.payroll')]));
    });

    test('a grant lets an organisation past its plan', function () {
        $tenant = gateTenant(['attendance']);
        setOverride($tenant, Feature::Payroll, FeatureOverrideMode::Grant);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        // Not asserting success: only that the feature gate is not what stops it.
        $response = $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ]);

        expect($response->status())->not->toBe(403);
    });

    test('reads stay open while the platform has the feature switched off', function () {
        $tenant = gateTenant(['payroll']);
        setPlatformState(Feature::Payroll, FeatureState::Disabled);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/runs")->assertOk();
    });
});

describe('what /auth/me tells the screen', function () {
    test('every feature\'s decision, beside the plan list it always sent', function () {
        $tenant = gateTenant(['attendance', 'leave', 'employee_management']);
        setPlatformState(Feature::Leave, FeatureState::Hidden);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $response = $this->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/me")->assertOk();

        $response
            ->assertJsonPath('plan_features', ['attendance', 'leave', 'employee_management'])
            ->assertJsonPath('features.payroll', ['on' => false, 'visible' => false, 'reason' => 'plan'])
            ->assertJsonPath('features.leave', ['on' => true, 'visible' => false, 'reason' => 'enabled'])
            ->assertJsonPath('features.attendance', ['on' => true, 'visible' => true, 'reason' => 'enabled']);

        expect(array_keys($response->json('features')))
            ->toBe(array_map(static fn (Feature $feature): string => $feature->value, Feature::cases()));
    });

    test('a platform switch reaches screens still reading plan_features', function () {
        $tenant = gateTenant(['attendance', 'leave', 'employee_management']);
        setPlatformState(Feature::Leave, FeatureState::Disabled);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/me")
            ->assertOk()
            ->assertJsonPath('plan_features', ['attendance', 'employee_management']);
    });
});

describe('reading the state', function () {
    test('the gate reads once per request or job, and the next one sees a change', function () {
        $tenant = gateTenant(['payroll']);
        $gate = freshGate();

        expect($gate->allows($tenant, Feature::Payroll))->toBeTrue();

        setPlatformState(Feature::Payroll, FeatureState::Disabled);

        expect($gate->allows($tenant, Feature::Payroll))->toBeTrue()
            ->and(app(FeatureGate::class))->toBe($gate)
            ->and(freshGate()->allows($tenant, Feature::Payroll))->toBeFalse();
    });

    test('unreadable tables answer the catalogue defaults and the plan, not an error', function () {
        // On the host the code goes live before migrate runs.
        $tenant = gateTenant(['attendance']);
        Schema::drop('tenant_feature_overrides');
        Schema::drop('platform_feature_states');

        $gate = freshGate();

        expect($gate->decide($tenant, Feature::Attendance)->reason)->toBe(FeatureReason::Enabled)
            ->and($gate->decide($tenant, Feature::Payroll)->reason)->toBe(FeatureReason::Plan);
    });
});

test('the migration refuses to drop a feature_flags table that holds rows', function () {
    Schema::create('feature_flags', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('tenant_id')->nullable();
        $table->string('key');
        $table->boolean('enabled')->default(false);
        $table->timestamps();
    });
    DB::table('feature_flags')->insert(['key' => 'legacy', 'enabled' => true]);

    $migration = require database_path('migrations/2026_10_28_000020_create_feature_control_tables.php');

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'feature_flags holds rows');
    expect(DB::table('feature_flags')->count())->toBe(1);
});
