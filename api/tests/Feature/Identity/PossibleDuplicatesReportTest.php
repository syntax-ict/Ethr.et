<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\Identity\PossibleDuplicates;

/*
 * Employees who may be one person recorded twice (One employee identity,
 * PR 11). Every way an employee is made has asked first since 2026-11-11;
 * records older than that were never compared. The report finds them and
 * changes nothing.
 */

/**
 * An employee with nothing two people could share, so a test states only
 * what it is about.
 *
 * @param  array<string, mixed>  $attributes
 */
function rosterPerson(Tenant $tenant, string $name, array $attributes = []): Employee
{
    return Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => $name,
        'email' => null,
        'phone' => null,
        'badge_number' => null,
        'national_id' => null,
        'date_of_birth' => null,
        'status' => EmployeeStatus::CONFIRMED,
        ...$attributes,
    ]);
}

/** @return list<array{reasons: list<string>, names: list<string>}> */
function duplicateGroups(Tenant $tenant): array
{
    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/possible-duplicates")->assertOk();

    return array_map(fn (array $group): array => [
        'reasons' => $group['reasons'],
        'names' => array_column($group['employees'], 'name'),
    ], $response->json('groups'));
}

it('finds nobody on a roster where nobody shares anything', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rosterPerson($tenant, 'Abebe Kebede', ['email' => 'abebe@acme.et', 'phone' => '+251911223344']);
    rosterPerson($tenant, 'Hanna Girma', ['email' => 'hanna@acme.et', 'phone' => '+251922334455']);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/possible-duplicates")
        ->assertOk()
        ->assertExactJson(['groups' => [], 'truncated' => false]);
});

it('puts two records together for each thing a person has only one of', function (string $reason, array $first, array $second) {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rosterPerson($tenant, 'Abebe Kebede', $first);
    rosterPerson($tenant, 'A. Kebede', $second);
    rosterPerson($tenant, 'Somebody Else', ['email' => 'else@acme.et']);

    expect(duplicateGroups($tenant))->toBe([['reasons' => [$reason], 'names' => ['Abebe Kebede', 'A. Kebede']]]);
})->with([
    'national id' => [PossibleDuplicates::NATIONAL_ID, ['national_id' => '1234567890123456'], ['national_id' => '1234567890123456']],
    'email, whatever its case' => [PossibleDuplicates::EMAIL, ['email' => 'Abebe@Acme.et'], ['email' => 'abebe@acme.et ']],
    'phone, however it is written' => [PossibleDuplicates::PHONE, ['phone' => '+251911223344'], ['phone' => '0911 22 33 44']],
    'badge number' => [PossibleDuplicates::BADGE, ['badge_number' => 'B-77'], ['badge_number' => 'b-77']],
]);

it('takes a name as a reason only together with a date of birth', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rosterPerson($tenant, 'Abebe Kebede', ['date_of_birth' => '1990-05-01']);
    rosterPerson($tenant, 'abebe kebede', ['date_of_birth' => '1990-05-01']);
    // The same name, born another day, or with no date: two people.
    rosterPerson($tenant, 'Abebe Kebede', ['date_of_birth' => '1985-01-01']);
    rosterPerson($tenant, 'Abebe Kebede');

    expect(duplicateGroups($tenant))->toBe([
        ['reasons' => [PossibleDuplicates::NAME_AND_BIRTH_DATE], 'names' => ['Abebe Kebede', 'abebe kebede']],
    ]);
});

it('lists the pair that shares the most first, with every reason, and three records as one group', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rosterPerson($tenant, 'Hanna Girma', ['badge_number' => '12']);
    rosterPerson($tenant, 'Hanna G.', ['badge_number' => '12']);
    rosterPerson($tenant, 'H. Girma', ['badge_number' => '12']);
    rosterPerson($tenant, 'Abebe Kebede', ['email' => 'abebe@acme.et', 'phone' => '0911223344']);
    rosterPerson($tenant, 'A. Kebede', ['email' => 'abebe@acme.et', 'phone' => '+251911223344']);

    expect(duplicateGroups($tenant))->toBe([
        ['reasons' => [PossibleDuplicates::EMAIL, PossibleDuplicates::PHONE], 'names' => ['Abebe Kebede', 'A. Kebede']],
        ['reasons' => [PossibleDuplicates::BADGE], 'names' => ['Hanna Girma', 'Hanna G.', 'H. Girma']],
    ]);
});

it('compares only people employed now: a leaver hired again and a deleted record are not duplicates', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rosterPerson($tenant, 'Abebe Kebede', ['email' => 'abebe@acme.et']);
    rosterPerson($tenant, 'Abebe Kebede (left)', ['email' => 'abebe@acme.et', 'status' => EmployeeStatus::RESIGNED]);
    rosterPerson($tenant, 'Abebe Kebede (deleted)', ['email' => 'abebe@acme.et'])->delete();

    expect(duplicateGroups($tenant))->toBe([]);
});

it('says who each record is without the values they share', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
    $first = rosterPerson($tenant, 'Abebe Kebede', ['national_id' => '1234567890123456', 'employee_code' => 'E-1', 'department_id' => $finance->id, 'created_via' => 'device']);
    rosterPerson($tenant, 'A. Kebede', ['national_id' => '1234567890123456', 'employee_code' => 'E-2', 'created_via' => 'import']);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/possible-duplicates")->assertOk();

    expect($response->json('groups.0.employees.0'))->toMatchArray([
        'public_id' => $first->public_id,
        'name' => 'Abebe Kebede',
        'employee_code' => 'E-1',
        'status' => 'confirmed',
        'department' => 'Finance',
        'created_via' => 'device',
    ])->and($response->json('groups.0.employees.1.created_via'))->toBe('import')
        ->and($response->getContent())->not->toContain('1234567890123456')
        ->and($response->json('groups.0.employees.0'))->not->toHaveKey('id');
});

it('never puts two organisations\' people together', function () {
    $other = createTenant();
    rosterPerson($other, 'Abebe Kebede', ['email' => 'abebe@acme.et']);
    app()->forgetScopedInstances();

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    rosterPerson($tenant, 'Abebe Kebede', ['email' => 'abebe@acme.et']);

    expect(duplicateGroups($tenant))->toBe([]);
});

it('is for people who add employees', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/possible-duplicates")->assertForbidden();
});

it('stops at a hundred groups and says there were more', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    foreach (range(1, PossibleDuplicates::MAX_GROUPS + 2) as $n) {
        rosterPerson($tenant, "Person {$n}", ['badge_number' => "B-{$n}"]);
        rosterPerson($tenant, "Person {$n} again", ['badge_number' => "B-{$n}"]);
    }

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/possible-duplicates")->assertOk();

    expect($response->json('groups'))->toHaveCount(PossibleDuplicates::MAX_GROUPS)
        ->and($response->json('truncated'))->toBeTrue();
});
