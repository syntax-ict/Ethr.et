<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Cache;

/*
 * "Trusted by" on ETHR's public site (2026-10-18). Naming a customer is a
 * public claim, so an organisation appears only when it agreed to be named,
 * is an active customer, and the platform has not hidden it.
 */

beforeEach(function () {
    Cache::forget(Tenant::SHOWCASE_CACHE_KEY);
});

function showcaseTenant(array $attributes = []): Tenant
{
    return Tenant::factory()->create([
        'status' => TenantStatus::ACTIVE,
        ...$attributes,
    ]);
}

it('names only operational organisations that agreed and are not hidden', function () {
    $listed = showcaseTenant(['name' => 'Listed PLC']);
    $listed->forceFill(['showcase_consented_at' => now()->subDay()])->save();

    showcaseTenant(['name' => 'Never Agreed PLC']);

    // A free trial that has not ended is named (owner decision 2026-10-18);
    // one that has ended is not.
    $trial = showcaseTenant(['name' => 'Trial PLC', 'status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->addDays(10)]);
    $trial->forceFill(['showcase_consented_at' => now()])->save();

    $expired = showcaseTenant(['name' => 'Expired Trial PLC', 'status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->subDay()]);
    $expired->forceFill(['showcase_consented_at' => now()])->save();

    $hidden = showcaseTenant(['name' => 'Hidden PLC']);
    $hidden->forceFill(['showcase_consented_at' => now(), 'showcase_hidden_at' => now()])->save();

    $suspended = showcaseTenant(['name' => 'Suspended PLC', 'status' => TenantStatus::SUSPENDED]);
    $suspended->forceFill(['showcase_consented_at' => now()])->save();

    $response = $this->getJson('/api/v1/trusted-by')->assertOk();

    expect($response->json('data.*.name'))->toBe(['Listed PLC', 'Trial PLC'])
        ->and(array_keys($response->json('data.0')))->toEqualCanonicalizing(['public_id', 'name', 'logo_url', 'url']);
});

it('lets an administrator agree and withdraw, audited, and the list follows', function () {
    $tenant = showcaseTenant(['name' => 'Acme PLC']);
    $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/settings/showcase";

    $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('listed', false)->assertJsonPath('shown', false);

    $this->actingAs($admin)->putJson($url, ['listed' => true])
        ->assertOk()->assertJsonPath('listed', true)->assertJsonPath('shown', true);
    expect($tenant->refresh()->showcase_consented_at)->not->toBeNull();

    app(CurrentTenant::class)->forget();
    $this->app['auth']->forgetGuards();
    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toBe(['Acme PLC']);

    $this->actingAs($admin)->putJson($url, ['listed' => false])->assertOk()->assertJsonPath('listed', false);

    app(CurrentTenant::class)->forget();
    $this->app['auth']->forgetGuards();
    expect($this->getJson('/api/v1/trusted-by')->json('data'))->toBe([]);

    app(CurrentTenant::class)->set($tenant);
    expect(AuditLog::query()->whereIn('action', ['settings.showcase_consented', 'settings.showcase_withdrawn'])->count())->toBe(2);
});

it('does not let anyone but an administrator decide', function () {
    $tenant = showcaseTenant();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->actingAs($hr)
        ->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings/showcase", ['listed' => true])
        ->assertForbidden();

    expect($tenant->refresh()->showcase_consented_at)->toBeNull();
});

it('lets the platform hide an organisation that agreed, and show it again', function () {
    $tenant = showcaseTenant(['name' => 'Test Org']);
    $tenant->forceFill(['showcase_consented_at' => now()])->save();
    $admin = createUser(['role' => UserRole::SUPER_ADMIN, 'tenant_id' => null, 'mfa_enabled' => true]);
    $this->actingAs($admin);
    app(CurrentTenant::class)->forget();

    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toBe(['Test Org']);

    $this->putJson("/api/v1/admin/tenants/{$tenant->public_id}/showcase", ['hidden' => true])
        ->assertOk()->assertJsonPath('showcase_hidden', true);
    expect($this->getJson('/api/v1/trusted-by')->json('data'))->toBe([]);
    // The organisation's own consent is untouched by the veto.
    expect($tenant->refresh()->showcase_consented_at)->not->toBeNull();

    $this->putJson("/api/v1/admin/tenants/{$tenant->public_id}/showcase", ['hidden' => false])
        ->assertOk()->assertJsonPath('showcase_hidden', false);
    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toBe(['Test Org']);
});

it('drops an organisation from the list as soon as it is suspended', function () {
    $tenant = showcaseTenant(['name' => 'Soon Suspended']);
    $tenant->forceFill(['showcase_consented_at' => now()])->save();
    $admin = createUser(['role' => UserRole::SUPER_ADMIN, 'tenant_id' => null, 'mfa_enabled' => true]);
    app(CurrentTenant::class)->forget();

    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toBe(['Soon Suspended']);

    $this->actingAs($admin)
        ->putJson("/api/v1/admin/tenants/{$tenant->public_id}/status", ['status' => 'suspended'])
        ->assertOk();

    $this->app['auth']->forgetGuards();
    expect($this->getJson('/api/v1/trusted-by')->json('data'))->toBe([]);
});

it('links each organisation to its own public page, not to ETHR\'s home', function () {
    $tenant = showcaseTenant(['subdomain' => 'abay']);
    $tenant->forceFill(['showcase_consented_at' => now()])->save();

    $url = $this->getJson('/api/v1/trusted-by')->json('data.0.url');

    expect($url)->toBe(FrontendUrl::at($tenant, 'login'))
        ->and($url)->toContain('abay');
});
