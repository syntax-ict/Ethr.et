<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\MigrationStagingRow;
use App\Models\Tenant;
use Tests\Support\StagingFixtures as Staging;

/**
 * Staging a list needs only `employee.create`. That was right while a matched
 * row could do no more than link a number to a person. Now it can edit them,
 * and an edit made through a list takes what the same edit takes on the form:
 * `employee.update` with reach over the person, and the pay permission for
 * salary and TIN (One employee identity, PR 5).
 */
function stagingReviewer(Tenant $tenant, array $permissions): void
{
    test()->actingAs(createScopedUser($tenant, null, 'all', $permissions));
}

it('links the person and edits nothing for someone who may add employees but not edit them', function () {
    $tenant = createTenant();
    $abebe = Staging::employee($tenant, ['phone' => '+251911000001']);
    stagingReviewer($tenant, ['employee.create', 'employee.viewAny']);

    $staged = test()->postJson(Staging::url($tenant, 'rows'), [
        'rows' => [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0922000002', 'email' => 'abebe@example.et']],
    ])->assertCreated()->json();

    $row = $staged['rows'][0];

    // They are told the row differs, not what ETHR holds: they may not open
    // this employee's record.
    expect($row['can_apply_changes'])->toBeFalse()
        ->and(Staging::change($row['changes'], 'phone'))->toMatchArray(['kind' => 'conflict', 'current' => null])
        ->and(json_encode($staged))->not->toContain('+251911000001');

    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
        ->assertOk()
        ->assertJsonPath('totals.merged', 1)
        ->assertJsonPath('totals.updated', 0);

    expect($abebe->refresh()->email)->toBeNull()
        ->and($abebe->phone)->toBe('+251911000001');
});

it('refuses to accept a difference from someone who may not edit that employee', function () {
    $tenant = createTenant();
    Staging::employee($tenant, ['phone' => '+251911000001']);
    stagingReviewer($tenant, ['employee.create', 'employee.viewAny']);

    $staged = test()->postJson(Staging::url($tenant, 'rows'), [
        'rows' => [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0922000002']],
    ])->assertCreated()->json();

    $url = Staging::url($tenant, "rows/{$staged['rows'][0]['public_id']}");

    test()->patchJson($url, ['action' => 'merge', 'accept' => ['phone']])->assertForbidden();

    // The decision itself is still theirs to make.
    test()->patchJson($url, ['action' => 'merge'])->assertOk();
    test()->patchJson($url, ['action' => 'merge', 'accept' => []])->assertOk();
});

it('writes the rest of the row and leaves salary and TIN for someone without the pay permission', function () {
    $tenant = createTenant();
    $abebe = Staging::employee($tenant, ['tin' => '0011223344']);
    stagingReviewer($tenant, ['employee.create', 'employee.viewAny', 'employee.view', 'employee.update']);

    $batch = Staging::stage($tenant, [[
        'name' => 'Abebe Kebede',
        'employee_code' => 'E-1',
        'phone' => '0911223344',
        'salary_cents' => 1250000,
        'tin' => '0099887766',
    ]]);
    $row = Staging::firstRow($batch);
    $url = Staging::url($tenant, "rows/{$row->public_id}");

    test()->patchJson($url, ['action' => 'merge', 'accept' => ['tin']])->assertForbidden();

    test()->postJson(Staging::url($tenant, "batches/{$batch->public_id}/commit"))
        ->assertOk()
        ->assertJsonPath('totals.updated', 1);

    $abebe->refresh();
    expect($abebe->phone)->toBe('+251911223344')
        ->and($abebe->salary_cents)->toBe(0)
        ->and($abebe->tin)->toBe('0011223344');
});

it('fills a salary for someone who holds the pay permission', function () {
    $tenant = createTenant();
    $abebe = Staging::employee($tenant);
    stagingReviewer($tenant, ['employee.create', 'employee.viewAny', 'employee.view', 'employee.update', 'employee.updateFinancial']);

    $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'salary_cents' => 1250000]]);

    test()->postJson(Staging::url($tenant, "batches/{$batch->public_id}/commit"))
        ->assertOk()
        ->assertJsonPath('totals.updated', 1);

    expect($abebe->refresh()->salary_cents)->toBe(1250000);
});

it('edits nobody when a list is committed with no one signed in', function () {
    $tenant = createTenant();
    $abebe = Staging::employee($tenant);

    $batch = Staging::stage($tenant, [['name' => 'Abebe Kebede', 'employee_code' => 'E-1', 'phone' => '0911223344']]);
    $totals = Staging::service()->commit($batch);

    expect($totals['merged'])->toBe(1)
        ->and($totals['updated'])->toBe(0)
        ->and($abebe->refresh()->phone)->toBeNull()
        ->and(Employee::withTrashed()->where('tenant_id', $tenant->id)->count())->toBe(1)
        ->and(Staging::firstRow($batch)->action)->toBe(MigrationStagingRow::ACTION_MERGE);
});
