<?php

declare(strict_types=1);

use App\Enums\Feature;
use App\Enums\FeatureOverrideMode;
use App\Enums\FeatureState;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\PlatformFeatureState;
use App\Models\TenantFeatureOverride;
use App\Models\User;
use App\Services\CurrentTenant;
use App\Services\Features\FeatureGate;

/*
 * A-Z review H-04 (2026-11-05). Five console switches showed "off" while the
 * feature kept working. API access is now enforced on keys that already
 * exist; attendance, leave, employee management and notifications are core,
 * every plan includes them and nothing checks a switch for them, so the
 * console no longer offers one: a switch that does nothing is not offered.
 */

const H04_CONSOLE = 'http://admin.ethr.et/api/v1/admin';

dataset('core features', ['attendance', 'leave', 'employee_management', 'notifications']);

function h04Admin(): void
{
    config(['app.domain' => 'ethr.et']);
    app(CurrentTenant::class)->forget();
    test()->actingAs(User::factory()->create(['tenant_id' => null, 'role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true]));
}

it('lists the core features as not switchable, and the rest as switchable', function () {
    h04Admin();

    $rows = collect(test()->getJson(H04_CONSOLE.'/features')->assertOk()->json('data'))->keyBy('key');

    expect($rows['attendance']['switchable'])->toBeFalse()
        ->and($rows['notifications']['switchable'])->toBeFalse()
        ->and($rows['payroll']['switchable'])->toBeTrue()
        ->and($rows['api_access']['switchable'])->toBeTrue();
});

it('refuses to switch a core feature off for everyone', function (string $key) {
    h04Admin();

    test()->putJson(H04_CONSOLE."/features/{$key}", ['state' => 'disabled', 'reason' => 'Trying it out'])
        ->assertUnprocessable();

    expect(PlatformFeatureState::query()->where('key', $key)->exists())->toBeFalse();
})->with('core features');

it('refuses to switch a core feature off for one organisation', function (string $key) {
    $tenant = createTenant();
    h04Admin();

    test()->putJson(H04_CONSOLE."/tenants/{$tenant->public_id}/features/{$key}", ['mode' => 'revoke', 'reason' => 'Trying it out'])
        ->assertUnprocessable();

    expect(TenantFeatureOverride::query()->withoutGlobalScope('tenant')->where('key', $key)->exists())->toBeFalse();
})->with('core features');

it('ignores a core feature switch stored before this change', function () {
    $tenant = createTenant();
    PlatformFeatureState::query()->create(['key' => 'leave', 'state' => FeatureState::Disabled]);
    TenantFeatureOverride::query()->withoutGlobalScope('tenant')->create([
        'tenant_id' => $tenant->id, 'key' => 'attendance', 'mode' => FeatureOverrideMode::Revoke, 'reason' => 'old',
    ]);
    app()->forgetScopedInstances();
    $gate = app(FeatureGate::class);

    expect($gate->allows($tenant, Feature::Leave))->toBeTrue()
        ->and($gate->allows($tenant, Feature::Attendance))->toBeTrue()
        ->and($gate->pausedFor($tenant))->not->toContain('leave');
});

it('stops a key that already exists when API access is switched off', function () {
    $tenant = createTenant();
    $owner = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $token = 'ethr_'.str_repeat('a', 40);
    ApiKey::factory()->create([
        'tenant_id' => $tenant->id, 'created_by' => $owner->id,
        'key_hash' => hash('sha256', $token), 'abilities' => ['read'],
    ]);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/employees";

    test()->withToken($token)->getJson($url)->assertOk();
    app('auth')->forgetGuards();

    TenantFeatureOverride::query()->withoutGlobalScope('tenant')->create([
        'tenant_id' => $tenant->id, 'key' => 'api_access', 'mode' => FeatureOverrideMode::Revoke, 'reason' => 'Paused by ETHR',
    ]);
    app()->forgetScopedInstances();

    test()->withToken($token)->getJson($url)->assertForbidden();
});
