<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\OrganizationType;
use App\Models\Tenant;
use App\Models\TenantProfileAssignment;
use App\Services\CurrentTenant;

// The console shows an organisation's kind in law (docs/operations/ORGANISATION-TYPES.md),
// read-only, and the organisations list filters by it. The industry is `type`.

/** Signs in a platform admin on the platform host, with no organisation resolved. */
function consoleAdminForKinds(): Tenant
{
    $home = createTenant();
    test()->actingAs(createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home));
    app(CurrentTenant::class)->forget();

    return $home;
}

function kindsConsoleUrl(Tenant $home, string $path): string
{
    return "http://{$home->subdomain}.ethr.test/api/v1/admin/tenants{$path}";
}

it('shows each organisation\'s kind in law next to its industry, and filters the list by it', function () {
    $company = Tenant::factory()->create(['name' => 'Bole Traders', 'type' => 'general', 'organization_type' => 'private']);
    $ministry = Tenant::factory()->create(['name' => 'Ministry Office', 'type' => 'government', 'organization_type' => 'government', 'organization_type_needs_review' => true]);
    $home = consoleAdminForKinds();

    $all = test()->getJson(kindsConsoleUrl($home, '?per_page=100'))->assertOk();
    $rows = collect($all->json('data'))->keyBy('name');

    expect($rows['Bole Traders']['organization_type'])->toBe('private')
        ->and($rows['Bole Traders']['type'])->toBe('general')
        ->and($rows['Bole Traders']['organization_type_needs_review'])->toBeFalse()
        ->and($rows['Ministry Office']['organization_type'])->toBe('government')
        ->and($rows['Ministry Office']['organization_type_needs_review'])->toBeTrue();

    $only = test()->getJson(kindsConsoleUrl($home, '?filter[organization_type]=government&per_page=100'))->assertOk();
    expect(collect($only->json('data'))->pluck('public_id')->all())
        ->toContain($ministry->public_id)
        ->not->toContain($company->public_id);
});

it('filters the list by the group a kind belongs to', function () {
    $group = OrganizationType::query()->where('code', 'government')->value('group_code');
    $company = Tenant::factory()->create(['organization_type' => 'private']);
    $ministry = Tenant::factory()->create(['organization_type' => 'government']);
    $home = consoleAdminForKinds();

    $ids = collect(test()->getJson(kindsConsoleUrl($home, "?filter[organization_type_group]={$group}&per_page=100"))->assertOk()->json('data'))->pluck('public_id');

    expect($ids)->toContain($ministry->public_id)->not->toContain($company->public_id);
});

it('refuses a kind or group that is not in the catalogue, and never exposes a numeric id', function () {
    $home = consoleAdminForKinds();

    test()->getJson(kindsConsoleUrl($home, '?filter[organization_type]=nonsense'))->assertUnprocessable();
    test()->getJson(kindsConsoleUrl($home, '?filter[organization_type_group]=nonsense'))->assertUnprocessable();

    $row = test()->getJson(kindsConsoleUrl($home, '?per_page=1'))->assertOk()->json('data.0');
    expect($row)->not->toHaveKey('id')->and($row)->not->toHaveKey('tenant_id');
});

it('shows the kind and a scheduled change on the detail, and offers no way to change it', function () {
    $tenant = Tenant::factory()->create(['organization_type' => 'private', 'organization_type_needs_review' => true]);
    app(CurrentTenant::class)->set($tenant);
    TenantProfileAssignment::query()->create(['tenant_id' => $tenant->id, 'organization_type' => 'private', 'effective_from' => now()->subYear()->toDateString()]);
    TenantProfileAssignment::query()->create(['tenant_id' => $tenant->id, 'organization_type' => 'government', 'effective_from' => now()->addMonth()->startOfMonth()->toDateString()]);
    $plain = Tenant::factory()->create(['organization_type' => 'government']);
    $home = consoleAdminForKinds();

    $detail = test()->getJson(kindsConsoleUrl($home, "/{$tenant->public_id}"))->assertOk();
    expect($detail->json('organization_type'))->toBe('private')
        ->and($detail->json('organization_type_needs_review'))->toBeTrue()
        ->and($detail->json('organization_type_pending'))->toBe([
            'organization_type' => 'government',
            'effective_from' => now()->addMonth()->startOfMonth()->toDateString(),
        ]);

    expect(test()->getJson(kindsConsoleUrl($home, "/{$plain->public_id}"))->json('organization_type_pending'))->toBeNull();

    test()->putJson(kindsConsoleUrl($home, "/{$tenant->public_id}"), ['organization_type' => 'government'])->assertStatus(405);
    expect($tenant->fresh()->organization_type)->toBe('private');
});
