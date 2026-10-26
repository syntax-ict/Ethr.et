<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\TenantLanding;

/**
 * Every organisation in use is listed in search results by default (owner
 * decision 2026-10-26). Listing used to be off by default and the settings
 * form saved `listed: false` with every other portal field, so a stored
 * `false` meant nothing. The migration removes each one so the organisation
 * reads as the new default, and touches nothing else on the row.
 */
function listingDefaultMigration(): object
{
    return require database_path('migrations/2026_10_26_000001_list_organisation_portals_by_default.php');
}

function storedLanding(?array $landing): Tenant
{
    $tenant = Tenant::factory()->create();
    // Written through the query builder, so a null stays NULL in the column.
    Tenant::query()->whereKey($tenant->id)->update(['landing' => $landing === null ? null : json_encode($landing)]);

    return $tenant->refresh();
}

it('turns a stored false into the default, keeping every other portal field', function () {
    $tenant = storedLanding(['headline' => 'Welcome', 'headline_am' => 'እንኳን ደህና መጡ', 'listed' => false]);
    expect(TenantLanding::present($tenant)['listed'])->toBeFalse();

    listingDefaultMigration()->up();

    $tenant->refresh();
    expect($tenant->landing)->toBe(['headline' => 'Welcome', 'headline_am' => 'እንኳን ደህና መጡ'])
        ->and(TenantLanding::present($tenant)['listed'])->toBeTrue()
        ->and(TenantLanding::indexable($tenant))->toBeTrue();
});

it('leaves a stored true, a row with no switch and a row with no settings as they are', function () {
    $listed = storedLanding(['background' => 'brand', 'listed' => true]);
    $silent = storedLanding(['background' => 'gradient']);
    $empty = storedLanding(null);

    listingDefaultMigration()->up();

    expect($listed->refresh()->landing)->toBe(['background' => 'brand', 'listed' => true])
        ->and($silent->refresh()->landing)->toBe(['background' => 'gradient'])
        ->and($empty->refresh()->landing)->toBeNull();
});

it('is idempotent', function () {
    $tenant = storedLanding(['listed' => false, 'message' => 'Hi']);

    listingDefaultMigration()->up();
    listingDefaultMigration()->up();

    expect($tenant->refresh()->landing)->toBe(['message' => 'Hi']);
});
