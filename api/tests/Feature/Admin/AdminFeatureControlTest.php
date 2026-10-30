<?php

declare(strict_types=1);

use App\Enums\Feature;
use App\Enums\FeatureState;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlatformFeatureState;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantFeatureOverride;
use App\Models\User;
use App\Services\CurrentTenant;

/**
 * The console's feature switches (docs/FEATURE-CONTROL-CENTER-DESIGN.md §5, §7):
 * the platform state of each feature and one organisation's grant or revoke,
 * each with a required reason and an audit entry. Requests go to
 * admin.ethr.et, the one host EnsurePlatformContext serves the console on.
 */
function featureConsoleAdmin(): User
{
    config(['app.domain' => 'ethr.et']);
    app(CurrentTenant::class)->forget();

    $admin = User::factory()->create([
        'tenant_id' => null,
        'role' => UserRole::SUPER_ADMIN,
        'mfa_enabled' => true,
    ]);
    test()->actingAs($admin);

    return $admin;
}

function featureConsoleTenant(array $planFeatures = ['attendance', 'leave', 'employee_management']): Tenant
{
    $tenant = Tenant::factory()->create(['status' => TenantStatus::ACTIVE]);
    Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => Plan::factory()->create(['features' => $planFeatures])->id,
    ]);

    return $tenant->refresh();
}

const CONSOLE = 'http://admin.ethr.et/api/v1/admin';

describe('platform state', function () {
    it('lists every feature at its default until the admin changes one', function () {
        featureConsoleAdmin();

        $response = test()->getJson(CONSOLE.'/features')->assertOk();

        expect($response->json('data'))->toHaveCount(count(Feature::cases()))
            ->and($response->json('data.3'))->toBe([
                'key' => 'payroll',
                'kind' => 'module',
                'overridable' => true,
                'state' => 'enabled',
                'default_state' => 'enabled',
                'reason' => null,
                'changed_at' => null,
                'grants' => 0,
                'revokes' => 0,
            ]);
    });

    it('sets a state with its reason and audits the change', function () {
        $admin = featureConsoleAdmin();

        test()->putJson(CONSOLE.'/features/payroll', ['state' => 'disabled', 'reason' => 'Bank file outage'])
            ->assertOk()
            ->assertJsonPath('key', 'payroll')
            ->assertJsonPath('state', 'disabled')
            ->assertJsonPath('reason', 'Bank file outage');

        expect(PlatformFeatureState::query()->where('key', 'payroll')->value('state'))->toBe(FeatureState::Disabled);

        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'platform.feature_state_changed')->sole();
        expect($audit->tenant_id)->toBeNull()
            ->and($audit->user_id)->toBe($admin->id)
            ->and($audit->payload)->toMatchArray([
                'feature' => 'payroll',
                'from' => 'enabled',
                'to' => 'disabled',
                'reason' => 'Bank file outage',
            ]);
    });

    it('takes effect for organisations on their next request', function () {
        $tenantDomain = config('app.domain');
        featureConsoleAdmin();
        $tenant = featureConsoleTenant(['attendance', 'payroll']);

        test()->putJson(CONSOLE.'/features/payroll', ['state' => 'disabled', 'reason' => 'Bank file outage'])->assertOk();

        config(['app.domain' => $tenantDomain]);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/payroll/process", [
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ])->assertForbidden()->assertJsonPath('reason', 'platform_disabled');
    });

    it('requires a known state and a reason', function () {
        featureConsoleAdmin();

        test()->putJson(CONSOLE.'/features/payroll', ['state' => 'off'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['state', 'reason']);

        expect(PlatformFeatureState::query()->count())->toBe(0);
    });

    it('does not route a key the catalogue does not know', function () {
        featureConsoleAdmin();

        test()->putJson(CONSOLE.'/features/not_a_feature', ['state' => 'disabled', 'reason' => 'test'])
            ->assertNotFound();
    });
});

describe('one organisation', function () {
    it('shows each feature\'s decision and why', function () {
        featureConsoleAdmin();
        $tenant = featureConsoleTenant();

        $rows = collect(test()->getJson(CONSOLE."/tenants/{$tenant->public_id}/features")->assertOk()->json('data'))
            ->keyBy('key');

        expect($rows['attendance'])->toMatchArray(['on' => true, 'reason' => 'enabled', 'override' => null])
            ->and($rows['payroll'])->toMatchArray(['on' => false, 'reason' => 'plan', 'override' => null]);
    });

    it('grants a feature the plan lacks, with an expiry, and audits it against the organisation', function () {
        $admin = featureConsoleAdmin();
        $tenant = featureConsoleTenant();
        $expires = now()->addDays(30)->startOfSecond();

        $rows = collect(test()->putJson(CONSOLE."/tenants/{$tenant->public_id}/features/payroll", [
            'mode' => 'grant',
            'reason' => 'Pilot agreed by sales',
            'expires_at' => $expires->toIso8601String(),
        ])->assertOk()->json('data'))->keyBy('key');

        expect($rows['payroll'])->toMatchArray(['on' => true, 'reason' => 'granted'])
            ->and($rows['payroll']['override'])->toMatchArray([
                'mode' => 'grant',
                'reason' => 'Pilot agreed by sales',
                'expires_at' => $expires->toIso8601String(),
                'expired' => false,
            ]);

        $audit = AuditLog::query()->withoutGlobalScopes()->where('action', 'admin.tenant.feature_override_set')->sole();
        expect($audit->tenant_id)->toBe($tenant->id)
            ->and($audit->user_id)->toBe($admin->id)
            ->and($audit->payload)->toMatchArray(['feature' => 'payroll', 'from' => null, 'to' => 'grant']);
    });

    it('changes an existing override in place rather than adding a second', function () {
        featureConsoleAdmin();
        $tenant = featureConsoleTenant(['attendance', 'payroll']);

        foreach (['grant', 'revoke'] as $mode) {
            test()->putJson(CONSOLE."/tenants/{$tenant->public_id}/features/payroll", [
                'mode' => $mode,
                'reason' => 'Changing course',
            ])->assertOk();
        }

        expect(TenantFeatureOverride::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count())->toBe(1)
            ->and(collect(test()->getJson(CONSOLE."/tenants/{$tenant->public_id}/features")->json('data'))
                ->firstWhere('key', 'payroll')['reason'])->toBe('revoked');
    });

    it('removes an override with a reason, and says when there was none', function () {
        featureConsoleAdmin();
        $tenant = featureConsoleTenant();

        test()->deleteJson(CONSOLE."/tenants/{$tenant->public_id}/features/payroll", ['reason' => 'Nothing to remove'])
            ->assertNotFound();

        test()->putJson(CONSOLE."/tenants/{$tenant->public_id}/features/payroll", [
            'mode' => 'grant',
            'reason' => 'Pilot',
        ])->assertOk();

        test()->deleteJson(CONSOLE."/tenants/{$tenant->public_id}/features/payroll", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

        $rows = collect(test()->deleteJson(CONSOLE."/tenants/{$tenant->public_id}/features/payroll", ['reason' => 'Pilot ended'])
            ->assertOk()->json('data'))->keyBy('key');

        expect($rows['payroll'])->toMatchArray(['on' => false, 'reason' => 'plan', 'override' => null])
            ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'admin.tenant.feature_override_removed')->sole()->payload)
            ->toMatchArray(['feature' => 'payroll', 'was' => 'grant', 'reason' => 'Pilot ended']);
    });

    it('refuses an expiry in the past', function () {
        featureConsoleAdmin();
        $tenant = featureConsoleTenant();

        test()->putJson(CONSOLE."/tenants/{$tenant->public_id}/features/payroll", [
            'mode' => 'grant',
            'reason' => 'Pilot',
            'expires_at' => now()->subDay()->toIso8601String(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['expires_at']);
    });

    it('counts grants and revokes per feature on the overview, ignoring expired ones', function () {
        featureConsoleAdmin();

        foreach (['grant', 'grant', 'revoke'] as $mode) {
            $tenant = featureConsoleTenant();
            test()->putJson(CONSOLE."/tenants/{$tenant->public_id}/features/payroll", ['mode' => $mode, 'reason' => 'Pilot'])->assertOk();
        }

        TenantFeatureOverride::query()->withoutGlobalScopes()->create([
            'tenant_id' => featureConsoleTenant()->id,
            'key' => 'payroll',
            'mode' => 'grant',
            'reason' => 'Old pilot',
            'expires_at' => now()->subDay(),
        ]);

        $payroll = collect(test()->getJson(CONSOLE.'/features')->json('data'))->firstWhere('key', 'payroll');

        expect([$payroll['grants'], $payroll['revokes']])->toBe([2, 1]);
    });
});

describe('what the catalogue allows', function () {
    it('says what kind each feature is and whether one organisation can be excepted', function () {
        featureConsoleAdmin();

        $rows = collect(test()->getJson(CONSOLE.'/features')->assertOk()->json('data'))->keyBy('key');

        expect($rows['payroll'])->toMatchArray(['kind' => 'module', 'overridable' => true])
            ->and($rows['scim'])->toMatchArray(['kind' => 'integration', 'overridable' => true])
            ->and($rows['public_news'])->toMatchArray(['kind' => 'public', 'overridable' => true])
            ->and($rows['self_registration'])->toMatchArray(['kind' => 'integration', 'overridable' => false]);
    });

    it('refuses a grant or a rollout for sign-up, which no organisation can hold', function () {
        featureConsoleAdmin();
        $tenant = featureConsoleTenant();

        test()->putJson(CONSOLE.'/features/self_registration', ['state' => 'restricted', 'reason' => 'Pilot'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['state']);
        test()->putJson(CONSOLE."/tenants/{$tenant->public_id}/features/self_registration", ['mode' => 'grant', 'reason' => 'Pilot'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['mode']);
        test()->putJson(CONSOLE.'/features/self_registration', ['state' => 'disabled', 'reason' => 'Spam wave'])->assertOk();

        expect(TenantFeatureOverride::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('who may use it', function () {
    it('refuses an organisation\'s own admin', function () {
        $tenant = featureConsoleTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

        test()->getJson(CONSOLE.'/features')->assertForbidden();
        test()->putJson(CONSOLE.'/features/payroll', ['state' => 'disabled', 'reason' => 'test'])->assertForbidden();
        test()->putJson(CONSOLE."/tenants/{$tenant->public_id}/features/payroll", ['mode' => 'grant', 'reason' => 'test'])
            ->assertForbidden();

        expect(PlatformFeatureState::query()->count())->toBe(0)
            ->and(TenantFeatureOverride::query()->withoutGlobalScopes()->count())->toBe(0);
    });

});
