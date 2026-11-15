<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\OrganizationType;
use App\Models\Tenant;
use App\Models\TenantProfileAssignment;
use App\Models\User;
use App\Services\CurrentTenant;

// The console shows an organisation's kind in law (docs/operations/ORGANISATION-TYPES.md),
// read-only, and the organisations list filters by it. The industry is `type`.

/** A platform super admin, who belongs to no organisation, on the console's own host. */
function consoleAdminForKinds(): void
{
    test()->actingAs(User::factory()->create(['tenant_id' => null, 'role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true]));
    app(CurrentTenant::class)->forget();
}

function kindsConsoleUrl(string $path): string
{
    return "http://admin.ethr.test/api/v1/admin/tenants{$path}";
}

it('shows each organisation\'s kind in law next to its industry, and filters the list by it', function () {
    $company = Tenant::factory()->create(['name' => 'Bole Traders', 'type' => 'general', 'organization_type' => 'private']);
    $ministry = Tenant::factory()->create(['name' => 'Ministry Office', 'type' => 'government', 'organization_type' => 'government', 'organization_type_needs_review' => true]);
    consoleAdminForKinds();

    $all = test()->getJson(kindsConsoleUrl('?per_page=100'))->assertOk();
    $rows = collect($all->json('data'))->keyBy('name');

    expect($rows['Bole Traders']['organization_type'])->toBe('private')
        ->and($rows['Bole Traders']['type'])->toBe('general')
        ->and($rows['Bole Traders']['organization_type_needs_review'])->toBeFalse()
        ->and($rows['Ministry Office']['organization_type'])->toBe('government')
        ->and($rows['Ministry Office']['organization_type_needs_review'])->toBeTrue();

    $only = test()->getJson(kindsConsoleUrl('?filter[organization_type]=government&per_page=100'))->assertOk();
    expect(collect($only->json('data'))->pluck('public_id')->all())
        ->toContain($ministry->public_id)
        ->not->toContain($company->public_id);
});

it('filters the list by the group a kind belongs to', function () {
    $group = OrganizationType::query()->where('code', 'government')->value('group_code');
    $company = Tenant::factory()->create(['organization_type' => 'private']);
    $ministry = Tenant::factory()->create(['organization_type' => 'government']);
    consoleAdminForKinds();

    $ids = collect(test()->getJson(kindsConsoleUrl("?filter[organization_type_group]={$group}&per_page=100"))->assertOk()->json('data'))->pluck('public_id');

    expect($ids)->toContain($ministry->public_id)->not->toContain($company->public_id);
});

it('refuses a kind or group that is not in the catalogue, and never exposes a numeric id', function () {
    Tenant::factory()->create();
    consoleAdminForKinds();

    test()->getJson(kindsConsoleUrl('?filter[organization_type]=nonsense'))->assertUnprocessable();
    test()->getJson(kindsConsoleUrl('?filter[organization_type_group]=nonsense'))->assertUnprocessable();

    $row = test()->getJson(kindsConsoleUrl('?per_page=1'))->assertOk()->json('data.0');
    expect($row)->not->toHaveKey('id')->and($row)->not->toHaveKey('tenant_id');
});

it('shows the kind and a scheduled change on the detail, and offers no way to change it', function () {
    $tenant = Tenant::factory()->create(['organization_type' => 'private', 'organization_type_needs_review' => true]);
    app(CurrentTenant::class)->set($tenant);
    TenantProfileAssignment::query()->create(['tenant_id' => $tenant->id, 'organization_type' => 'private', 'effective_from' => now()->subYear()->toDateString()]);
    TenantProfileAssignment::query()->create(['tenant_id' => $tenant->id, 'organization_type' => 'government', 'effective_from' => now()->addMonth()->startOfMonth()->toDateString()]);
    $plain = Tenant::factory()->create(['organization_type' => 'government']);
    consoleAdminForKinds();

    $detail = test()->getJson(kindsConsoleUrl("/{$tenant->public_id}"))->assertOk();
    expect($detail->json('organization_type'))->toBe('private')
        ->and($detail->json('organization_type_needs_review'))->toBeTrue()
        ->and($detail->json('organization_type_pending'))->toBe([
            'organization_type' => 'government',
            'effective_from' => now()->addMonth()->startOfMonth()->toDateString(),
        ]);

    expect(test()->getJson(kindsConsoleUrl("/{$plain->public_id}"))->json('organization_type_pending'))->toBeNull();

    test()->putJson(kindsConsoleUrl("/{$tenant->public_id}"), ['organization_type' => 'government'])->assertStatus(405);
    expect($tenant->fresh()->organization_type)->toBe('private');
});

it('shows the kind in force on the detail even while the stored column still holds the old one', function () {
    $tenant = Tenant::factory()->create(['organization_type' => 'private']);
    app(CurrentTenant::class)->set($tenant);
    TenantProfileAssignment::query()->create(['tenant_id' => $tenant->id, 'organization_type' => 'private', 'effective_from' => now()->subYear()->toDateString()]);
    TenantProfileAssignment::query()->create(['tenant_id' => $tenant->id, 'organization_type' => 'government', 'effective_from' => now()->subDay()->toDateString()]);
    consoleAdminForKinds();

    $detail = test()->getJson(kindsConsoleUrl("/{$tenant->public_id}"))->assertOk();

    expect($detail->json('organization_type'))->toBe('government')
        ->and($detail->json('organization_type_pending'))->toBeNull()
        ->and($tenant->fresh()->organization_type)->toBe('private');
});
