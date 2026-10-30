<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Cache;

/*
 * "Trusted by" on ETHR's public site (2026-10-18). Every organisation is
 * named from creation (owner decision 2026-10-26) while it is in use, unless
 * its administrator switched it off or the platform hid it.
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

it('names every operational organisation from creation, unless it opted out or is hidden', function () {
    showcaseTenant(['name' => 'Listed PLC']);

    $optedOut = showcaseTenant(['name' => 'Opted Out PLC']);
    $optedOut->forceFill(['showcase_consented_at' => null])->save();

    // A free trial that has not ended is named (owner decision 2026-10-18)
    // once it has people in it (onboarding review 2026-10-30); one that has
    // ended is not.
    $trial = showcaseTenant(['name' => 'Trial PLC', 'status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->addDays(10)]);
    $trial->forceFill(['showcase_consented_at' => now()])->save();
    Employee::factory()->count(Tenant::PUBLIC_MIN_EMPLOYEES)->create(['tenant_id' => $trial->id]);

    // Registered a minute ago with no email checked, and nearly empty: not
    // named yet, whatever it calls itself.
    $fresh = showcaseTenant(['name' => 'Fresh Trial PLC', 'status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->addDays(10)]);
    Employee::factory()->count(Tenant::PUBLIC_MIN_EMPLOYEES - 1)->create(['tenant_id' => $fresh->id]);

    $expired = showcaseTenant(['name' => 'Expired Trial PLC', 'status' => TenantStatus::TRIAL, 'trial_ends_at' => now()->subDay()]);
    $expired->forceFill(['showcase_consented_at' => now()])->save();

    $hidden = showcaseTenant(['name' => 'Hidden PLC']);
    $hidden->forceFill(['showcase_consented_at' => now(), 'showcase_hidden_at' => now()])->save();

    $suspended = showcaseTenant(['name' => 'Suspended PLC', 'status' => TenantStatus::SUSPENDED]);
    $suspended->forceFill(['showcase_consented_at' => now()])->save();

    $response = $this->getJson('/api/v1/trusted-by')->assertOk();

    expect($response->json('data.*.name'))->toEqualCanonicalizing(['Listed PLC', 'Trial PLC'])
        ->and(array_keys($response->json('data.0')))->toEqualCanonicalizing(['public_id', 'name', 'logo_url', 'url']);
});

it('lets an administrator withdraw and list again, audited, and the list follows', function () {
    $tenant = showcaseTenant(['name' => 'Acme PLC']);
    $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/settings/showcase";

    // Listed from creation.
    $this->actingAs($admin)->getJson($url)->assertOk()->assertJsonPath('listed', true)->assertJsonPath('shown', true);

    $this->actingAs($admin)->putJson($url, ['listed' => false])
        ->assertOk()->assertJsonPath('listed', false)->assertJsonPath('shown', false);
    expect($tenant->refresh()->showcase_consented_at)->toBeNull();

    app(CurrentTenant::class)->forget();
    $this->app['auth']->forgetGuards();
    expect($this->getJson('/api/v1/trusted-by')->json('data'))->toBe([]);

    $this->actingAs($admin)->putJson($url, ['listed' => true])->assertOk()->assertJsonPath('listed', true);

    app(CurrentTenant::class)->forget();
    $this->app['auth']->forgetGuards();
    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toBe(['Acme PLC']);

    app(CurrentTenant::class)->set($tenant);
    expect(AuditLog::query()->whereIn('action', ['settings.showcase_consented', 'settings.showcase_withdrawn'])->count())->toBe(2);
});

it('does not let anyone but an administrator decide', function () {
    $tenant = showcaseTenant();
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->actingAs($hr)
        ->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/settings/showcase", ['listed' => false])
        ->assertForbidden();

    expect($tenant->refresh()->showcase_consented_at)->not->toBeNull();
});

it('lets the platform hide an organisation that agreed, and show it again', function () {
    $tenant = showcaseTenant(['name' => 'Test Org']);
    $tenant->forceFill(['showcase_consented_at' => now()])->save();
    $admin = createUser(['role' => UserRole::SUPER_ADMIN, 'tenant_id' => null, 'mfa_enabled' => true]);
    $this->actingAs($admin);
    app(CurrentTenant::class)->forget();

    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toContain('Test Org');

    $this->putJson("/api/v1/admin/tenants/{$tenant->public_id}/showcase", ['hidden' => true])
        ->assertOk()->assertJsonPath('showcase_hidden', true);
    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->not->toContain('Test Org');
    // The organisation's own consent is untouched by the veto.
    expect($tenant->refresh()->showcase_consented_at)->not->toBeNull();

    $this->putJson("/api/v1/admin/tenants/{$tenant->public_id}/showcase", ['hidden' => false])
        ->assertOk()->assertJsonPath('showcase_hidden', false);
    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toContain('Test Org');
});

it('drops an organisation from the list as soon as it is suspended', function () {
    $tenant = showcaseTenant(['name' => 'Soon Suspended']);
    $tenant->forceFill(['showcase_consented_at' => now()])->save();
    $admin = createUser(['role' => UserRole::SUPER_ADMIN, 'tenant_id' => null, 'mfa_enabled' => true]);
    app(CurrentTenant::class)->forget();

    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toContain('Soon Suspended');

    $this->actingAs($admin)
        ->putJson("/api/v1/admin/tenants/{$tenant->public_id}/status", ['status' => 'suspended'])
        ->assertOk();

    $this->app['auth']->forgetGuards();
    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->not->toContain('Soon Suspended');
});

it('links each organisation to its own public page, not to ETHR\'s home', function () {
    $tenant = showcaseTenant(['subdomain' => 'abay']);
    $tenant->forceFill(['showcase_consented_at' => now()])->save();

    $url = $this->getJson('/api/v1/trusted-by')->json('data.0.url');

    expect($url)->toBe(FrontendUrl::at($tenant, 'login'))
        ->and($url)->toContain('abay');
});

it('lists a new organisation at once, whatever the cached list said before', function () {
    showcaseTenant(['name' => 'First PLC']);
    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toBe(['First PLC']);

    showcaseTenant(['name' => 'Second PLC']);
    expect($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toEqualCanonicalizing(['First PLC', 'Second PLC']);
});

it('lists every existing organisation once migrated, and leaves the platform veto alone', function () {
    $never = showcaseTenant(['name' => 'Never Decided PLC']);
    $hidden = showcaseTenant(['name' => 'Hidden PLC']);
    // Written through the query builder, as rows from before 2026-10-26 were.
    Tenant::query()->whereKey([$never->id, $hidden->id])->update(['showcase_consented_at' => null]);
    Tenant::query()->whereKey($hidden->id)->update(['showcase_hidden_at' => now()]);
    $listedAt = now()->subWeek()->startOfSecond();
    $listed = showcaseTenant(['name' => 'Listed PLC']);
    Tenant::query()->whereKey($listed->id)->update(['showcase_consented_at' => $listedAt]);

    (require database_path('migrations/2026_10_26_000002_list_every_organisation_under_trusted_by.php'))->up();

    expect($never->refresh()->showcase_consented_at)->not->toBeNull()
        ->and($hidden->refresh()->showcase_hidden_at)->not->toBeNull()
        ->and($listed->refresh()->showcase_consented_at?->equalTo($listedAt))->toBeTrue()
        ->and($this->getJson('/api/v1/trusted-by')->json('data.*.name'))->toEqualCanonicalizing(['Listed PLC', 'Never Decided PLC']);
});
