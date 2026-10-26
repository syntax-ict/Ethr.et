<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantSubdomainAlias;
use Illuminate\Support\Facades\Cache;

/**
 * A tenant admin renaming the organisation's address (2026-10-26): the
 * `{slug}` in ethr.et/{slug} and {slug}.ethr.et.
 *
 * The old name is retired, not released: it keeps reaching the organisation
 * and forwards to the new one, and no other organisation can take it. The
 * organisation can take a retired name back.
 */
beforeEach(function () {
    config([
        'app.domain' => 'ethr.test',
        'app.frontend_url' => 'http://ethr.test',
        'tenancy.subdomains' => false,
        'tenancy.custom_domain_target' => null,
    ]);
});

function renameUrl(string $host, string $path = ''): string
{
    return "http://{$host}.ethr.test/api/v1/settings/address/subdomain{$path}";
}

function renamingAdmin(string $subdomain = 'acme'): Tenant
{
    $tenant = createTenant(['subdomain' => $subdomain]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    return $tenant;
}

it('lets a tenant admin rename the address, keeping the old name as a retired one', function () {
    $tenant = renamingAdmin('acme');

    test()->putJson(renameUrl('acme'), ['subdomain' => '  Acme-Group '])
        ->assertOk()
        ->assertJsonPath('address.subdomain', 'acme-group')
        ->assertJsonPath('address.entry_url', 'http://ethr.test/acme-group')
        ->assertJsonPath('address.retired_subdomains', ['acme']);

    expect($tenant->fresh()->subdomain)->toBe('acme-group')
        ->and(TenantSubdomainAlias::withoutGlobalScopes()->where('subdomain', 'acme')->value('tenant_id'))->toBe($tenant->id);

    $audit = AuditLog::withoutGlobalScopes()->where('action', 'settings.subdomain_changed')->sole();
    expect($audit->payload)->toMatchArray(['old_subdomain' => 'acme', 'new_subdomain' => 'acme-group']);
});

it('keeps the old name reaching the organisation, cached or not', function () {
    $tenant = renamingAdmin('acme');

    // Warm the resolver's cache for the old name first.
    test()->getJson('http://acme.ethr.test/api/v1/settings')->assertOk();
    expect(Cache::has('tenant:acme'))->toBeTrue();

    test()->putJson(renameUrl('acme'), ['subdomain' => 'acme-group'])->assertOk();

    test()->getJson('http://acme.ethr.test/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('address.subdomain', 'acme-group');
    test()->getJson('http://acme-group.ethr.test/api/v1/settings')
        ->assertOk()
        ->assertJsonPath('address.subdomain', 'acme-group');
    test()->withHeader('X-Tenant', 'acme')
        ->getJson('http://ethr.test/api/v1/auth/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant.name', $tenant->name);
});

it('forwards ethr.et/{old} to the new entry address', function () {
    renamingAdmin('acme');
    test()->putJson(renameUrl('acme'), ['subdomain' => 'acme-group'])->assertOk();

    test()->get('http://ethr.test/acme')->assertRedirect('http://ethr.test/acme-group');
    test()->get('http://ethr.test/acme-group')->assertRedirect('/login?org=acme-group');
});

it('forwards the old subdomain\'s sign-in page to the new one', function () {
    config(['tenancy.subdomains' => true]);
    renamingAdmin('acme');
    test()->putJson(renameUrl('acme'), ['subdomain' => 'acme-group'])->assertOk();

    $page = public_path('login.html');
    $existed = is_file($page);
    if (! $existed) {
        file_put_contents($page, '<!doctype html><html><head><title>ETHR</title></head><body></body></html>');
    }

    try {
        test()->get('http://acme.ethr.test/login')->assertRedirect('http://acme-group.ethr.test/login');
    } finally {
        if (! $existed) {
            unlink($page);
        }
    }
});

it('lets the organisation take a retired name back', function () {
    $tenant = renamingAdmin('acme');
    test()->putJson(renameUrl('acme'), ['subdomain' => 'acme-group'])->assertOk();

    test()->putJson(renameUrl('acme-group'), ['subdomain' => 'acme'])
        ->assertOk()
        ->assertJsonPath('address.subdomain', 'acme')
        ->assertJsonPath('address.retired_subdomains', ['acme-group']);

    expect($tenant->fresh()->subdomain)->toBe('acme')
        ->and(TenantSubdomainAlias::withoutGlobalScopes()->pluck('subdomain')->all())->toBe(['acme-group']);
});

it('refuses a name another organisation has, had, or held before it was deleted', function (string $name) {
    $other = Tenant::factory()->create(['subdomain' => 'other']);
    TenantSubdomainAlias::query()->create(['tenant_id' => $other->id, 'subdomain' => 'other-old']);
    Tenant::factory()->create(['subdomain' => 'gone', 'status' => TenantStatus::CANCELLED])->delete();

    $tenant = renamingAdmin('acme');

    test()->putJson(renameUrl('acme'), ['subdomain' => $name])
        ->assertStatus(422)
        ->assertJsonPath('errors.subdomain.0', __('validation.subdomain_taken'));

    expect($tenant->fresh()->subdomain)->toBe('acme');
})->with(['current' => ['other'], 'retired' => ['other-old'], 'deleted' => ['gone']]);

it('holds a rename to the rules registration uses', function (string $name, string $message) {
    renamingAdmin('acme');

    test()->putJson(renameUrl('acme'), ['subdomain' => $name])
        ->assertStatus(422)
        ->assertJsonPath('errors.subdomain.0', __($message));
})->with([
    'reserved' => ['admin', 'validation.subdomain_reserved'],
    'a frontend page' => ['dashboard', 'validation.subdomain_reserved'],
    'bad characters' => ['acme_group', 'validation.subdomain_format'],
    'leading hyphen' => ['-acme', 'validation.subdomain_format'],
]);

it('keeps a retired name from being registered by a new organisation', function () {
    renamingAdmin('acme');
    test()->putJson(renameUrl('acme'), ['subdomain' => 'acme-group'])->assertOk();

    test()->getJson('http://ethr.test/api/v1/register/check-subdomain?subdomain=acme')
        ->assertOk()
        ->assertJsonPath('available', false);
});

it('checks a name before it is saved, saying why one is not available', function () {
    $other = Tenant::factory()->create(['subdomain' => 'other']);
    renamingAdmin('acme');

    test()->getJson(renameUrl('acme', '/check?subdomain=Acme-Group'))
        ->assertOk()
        ->assertJson(['subdomain' => 'acme-group', 'available' => true, 'message' => null]);
    test()->getJson(renameUrl('acme', '/check?subdomain=other'))
        ->assertOk()
        ->assertJson(['available' => false, 'message' => __('validation.subdomain_taken')]);
    test()->getJson(renameUrl('acme', '/check?subdomain=admin'))
        ->assertOk()
        ->assertJson(['available' => false, 'message' => __('validation.subdomain_reserved')]);

    expect($other->fresh()->subdomain)->toBe('other');
});

it('is for tenant admins only', function (UserRole $role) {
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => $role], $tenant);

    test()->putJson(renameUrl('acme'), ['subdomain' => 'acme-group'])->assertForbidden();
    test()->getJson(renameUrl('acme', '/check?subdomain=acme-group'))->assertForbidden();

    expect($tenant->fresh()->subdomain)->toBe('acme');
})->with([
    'hr admin' => [UserRole::HR_ADMIN],
    'finance admin' => [UserRole::FINANCE_ADMIN],
    'employee' => [UserRole::EMPLOYEE],
]);

it('stops a deleted organisation resolving at its retired names too', function () {
    $tenant = renamingAdmin('acme');
    test()->putJson(renameUrl('acme'), ['subdomain' => 'acme-group'])->assertOk();

    $tenant->fresh()->delete();

    expect(Tenant::findBySlug('acme'))->toBeNull()
        ->and(Tenant::findBySlug('acme-group'))->toBeNull()
        ->and(Tenant::slugAvailableFor('acme'))->toBeFalse();
});
