<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\ImportMapping;
use App\Models\Tenant;
use App\Support\EmployeeImportRow;

/*
 * What an employee is known as outside ETHR (One employee identity, PR 11):
 * the number each attendance terminal has for them and the id another system
 * gave them. The links decide who a punch or an imported line is, and until
 * now nobody could see them, confirm one, or remove a wrong one.
 */

/** @param array<string, mixed> $attributes */
function linkedIdentity(Employee $employee, array $attributes = []): EmployeeExternalIdentity
{
    return EmployeeExternalIdentity::query()->create([
        'tenant_id' => $employee->tenant_id,
        'employee_id' => $employee->id,
        'source_type' => 'zkteco',
        'source_ref' => 'device:1',
        'identifier_type' => 'device_user_id',
        'identifier_value' => '14',
        'confidence' => 0.9,
        ...$attributes,
    ]);
}

function identitiesUrl(Tenant $tenant, Employee $employee, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/employees/{$employee->public_id}/identities{$path}";
}

it('lists where the record came from and each id the person is known by, named', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'created_via' => 'device']);
    $device = Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id, 'name' => 'Front gate']);
    ImportMapping::query()->create(['tenant_id' => $tenant->id, 'name' => 'Peachtree payroll', 'slug' => 'peachtree-payroll', 'columns' => [['header' => 'Emp Nm', 'column' => 'name']]]);

    $onDevice = linkedIdentity($employee, ['source_ref' => 'device:'.$device->id, 'verified_at' => now()]);
    linkedIdentity($employee, ['source_type' => EmployeeImportRow::EXTERNAL_SOURCE, 'source_ref' => 'peachtree-payroll', 'identifier_type' => 'external_id', 'identifier_value' => 'P-1042']);
    linkedIdentity($employee, ['source_type' => EmployeeImportRow::EXTERNAL_SOURCE, 'source_ref' => 'default', 'identifier_type' => 'external_id', 'identifier_value' => 'HR-7']);

    $response = test()->getJson(identitiesUrl($tenant, $employee))->assertOk()->assertJsonPath('created_via', 'device');

    expect($response->json('identities'))->toHaveCount(3)
        ->and($response->json('identities.0'))->toMatchArray([
            'public_id' => $onDevice->public_id,
            'kind' => 'device',
            'source' => 'Front gate',
            'identifier_type' => 'device_user_id',
            'identifier_value' => '14',
            'verified' => true,
        ])
        ->and($response->json('identities.1'))->toMatchArray(['kind' => 'other_system', 'source' => 'Peachtree payroll', 'identifier_value' => 'P-1042', 'verified' => false])
        // An id with no named source: the screen says "another system".
        ->and($response->json('identities.2'))->toMatchArray(['kind' => 'other_system', 'source' => null, 'identifier_value' => 'HR-7'])
        ->and($response->json('identities.0'))->not->toHaveKey('id')
        ->and($response->json('identities.0'))->not->toHaveKey('employee_id');
});

it('still names a terminal that has been removed, and says nothing of a record older than the gateway', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'created_via' => null]);
    $device = Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id, 'name' => 'Old terminal']);
    linkedIdentity($employee, ['source_ref' => 'device:'.$device->id]);
    $device->delete();

    test()->getJson(identitiesUrl($tenant, $employee))
        ->assertOk()
        ->assertJsonPath('created_via', null)
        ->assertJsonPath('identities.0.source', 'Old terminal');
});

it('never shows a national id kept as an identifier', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    linkedIdentity($employee, ['source_type' => 'import', 'source_ref' => '', 'identifier_type' => 'national_id', 'identifier_value' => '1234567890123456']);

    $response = test()->getJson(identitiesUrl($tenant, $employee))->assertOk()->assertJsonPath('identities.0.identifier_value', null);

    expect($response->getContent())->not->toContain('1234567890123456');
});

it('confirms a link, once, and records who it was confirmed for', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $identity = linkedIdentity($employee);

    test()->postJson(identitiesUrl($tenant, $employee, "/{$identity->public_id}/verify"))->assertOk()->assertJsonPath('verified', true);
    $verifiedAt = $identity->refresh()->verified_at;
    expect($verifiedAt)->not->toBeNull();

    // Confirming again changes nothing and writes no second record.
    test()->postJson(identitiesUrl($tenant, $employee, "/{$identity->public_id}/verify"))->assertOk();
    expect($identity->refresh()->verified_at?->toIso8601String())->toBe($verifiedAt->toIso8601String())
        ->and(AuditLog::where('action', 'identity.verified')->count())->toBe(1);
});

it('removes a wrong link and leaves the employee as they were', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'badge_number' => '77']);
    $identity = linkedIdentity($employee);
    $kept = linkedIdentity($employee, ['source_ref' => 'device:2', 'identifier_value' => '15']);

    test()->deleteJson(identitiesUrl($tenant, $employee, "/{$identity->public_id}"))->assertNoContent();

    expect(EmployeeExternalIdentity::whereKey($identity->id)->exists())->toBeFalse()
        ->and(EmployeeExternalIdentity::whereKey($kept->id)->exists())->toBeTrue()
        ->and($employee->refresh()->badge_number)->toBe('77')
        ->and(AuditLog::where('action', 'identity.unlinked')->count())->toBe(1);
});

it('answers a link that is another employee\'s as one that does not exist', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = linkedIdentity($colleague);

    test()->postJson(identitiesUrl($tenant, $employee, "/{$theirs->public_id}/verify"))->assertNotFound();
    test()->deleteJson(identitiesUrl($tenant, $employee, "/{$theirs->public_id}"))->assertNotFound();

    expect($theirs->refresh()->verified_at)->toBeNull();
});

it('does not reach another organisation\'s employee or links', function () {
    $other = createTenant();
    $theirs = Employee::factory()->create(['tenant_id' => $other->id]);
    $link = linkedIdentity($theirs);
    app()->forgetScopedInstances();

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $mine = Employee::factory()->create(['tenant_id' => $tenant->id]);

    test()->getJson(identitiesUrl($tenant, $theirs))->assertNotFound();
    test()->deleteJson(identitiesUrl($tenant, $mine, "/{$link->public_id}"))->assertNotFound();

    expect(EmployeeExternalIdentity::withoutGlobalScope('tenant')->whereKey($link->id)->exists())->toBeTrue();
});

it('lets someone who may read a profile and not edit it see the links and change none', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::FINANCE_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $identity = linkedIdentity($employee);

    test()->getJson(identitiesUrl($tenant, $employee))->assertOk()->assertJsonCount(1, 'identities');
    test()->postJson(identitiesUrl($tenant, $employee, "/{$identity->public_id}/verify"))->assertForbidden();
    test()->deleteJson(identitiesUrl($tenant, $employee, "/{$identity->public_id}"))->assertForbidden();

    expect($identity->refresh()->verified_at)->toBeNull();
});

it('shows nothing to someone who may not read employee records', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    linkedIdentity($employee);

    test()->getJson(identitiesUrl($tenant, $employee))->assertForbidden();
});
