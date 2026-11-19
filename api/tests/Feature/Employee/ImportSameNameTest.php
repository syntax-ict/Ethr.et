<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;

/*
 * The file import (`POST /employees/import/commit`) and a file brought in a
 * second time. It has no review step, so a row whose full name someone here
 * already holds, in the department it names, is refused and named, as a
 * likeness on a phone is. A name alone still decides nothing: a row that
 * brings a number, email, badge, phone or national ID of its own that
 * differs from theirs is someone else, and is added.
 */

function nameImport(string $key, array $rows): array
{
    return test()->postJson('/api/v1/employees/import/commit', ['import_key' => $key, 'rows' => $rows])
        ->assertSuccessful()
        ->json();
}

function nameImportHeadcount(Tenant $tenant): int
{
    return Employee::withTrashed()->where('tenant_id', $tenant->id)->count();
}

const NAME_IMPORT_ROWS = [
    ['name' => 'Semira Aragaw Sisay', 'department_code' => 'REV', 'hire_date' => '2026-01-05'],
    ['name' => 'Abebe Kebede Tesfaye', 'department_code' => 'REV', 'hire_date' => '2026-01-05'],
    ['name' => 'Hanna Girma Alemu', 'hire_date' => '2026-01-05'],
];

function nameImportOrganisation(): Tenant
{
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Revenue Office', 'code' => 'REV']);
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Health Office', 'code' => 'HEA']);

    return $tenant;
}

it('adds nobody when a file with only names is imported a second time', function () {
    $tenant = nameImportOrganisation();

    expect(nameImport('first', NAME_IMPORT_ROWS)['created'])->toBe(3);

    $second = nameImport('second', NAME_IMPORT_ROWS);

    expect($second['created'])->toBe(0)
        ->and($second['errors'])->toHaveCount(3)
        ->and($second['errors'][0][0])->toBe(__('employee.import_possible_duplicate', ['row' => 0, 'name' => 'Semira Aragaw Sisay']))
        ->and(nameImportHeadcount($tenant))->toBe(3);
});

it('compares the whole name, ignoring case and spaces', function () {
    $tenant = nameImportOrganisation();
    nameImport('first', [['name' => 'Semira Aragaw Sisay', 'hire_date' => '2026-01-05']]);

    $second = nameImport('second', [
        ['name' => ' semira  ARAGAW sisay', 'hire_date' => '2026-01-05'],
        ['name' => 'Semira Aragaw', 'hire_date' => '2026-01-05'],
    ]);

    expect($second['created'])->toBe(1)
        ->and(array_keys($second['errors']))->toBe([0])
        ->and(nameImportHeadcount($tenant))->toBe(2);
});

it('adds the same name in another department', function () {
    $tenant = nameImportOrganisation();
    nameImport('first', [['name' => 'Semira Aragaw Sisay', 'department_code' => 'REV', 'hire_date' => '2026-01-05']]);

    expect(nameImport('second', [['name' => 'Semira Aragaw Sisay', 'department_code' => 'HEA', 'hire_date' => '2026-01-05']])['created'])->toBe(1)
        ->and(nameImportHeadcount($tenant))->toBe(2);
});

it('adds a person of the same name whose own number, email or phone differs', function (array $theirs, array $ours) {
    $tenant = nameImportOrganisation();
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Semira Aragaw Sisay',
        'department_id' => null,
        'employee_code' => 'WOR-0144',
        'email' => null,
        'phone' => null,
        'badge_number' => null,
        'national_id' => null,
        ...$theirs,
    ]);

    $result = nameImport('second', [['name' => 'Semira Aragaw Sisay', 'hire_date' => '2026-01-05', ...$ours]]);

    expect($result['created'])->toBe(1)
        ->and($result['errors'])->toBe([])
        ->and(nameImportHeadcount($tenant))->toBe(2);
})->with([
    'another employee number' => [[], ['employee_code' => 'WOR-3932']],
    'another email' => [['email' => 'semira@example.et'], ['email' => 'semira.s@example.et']],
    'another phone' => [['phone' => '+251911223344'], ['phone' => '0922334455']],
]);

it('refuses a same-name row whose details only add to theirs', function () {
    $tenant = nameImportOrganisation();
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Semira Aragaw Sisay',
        'department_id' => null,
        'email' => null,
        'phone' => null,
        'badge_number' => null,
    ]);

    $result = nameImport('second', [['name' => 'Semira Aragaw Sisay', 'email' => 'semira@example.et', 'hire_date' => '2026-01-05']]);

    expect($result['created'])->toBe(0)
        ->and($result['errors'])->toHaveKey(0)
        ->and(nameImportHeadcount($tenant))->toBe(1);
});

it('adds two people of one name in the same file', function () {
    $tenant = nameImportOrganisation();

    $result = nameImport('first', [
        ['name' => 'Semira Aragaw Sisay', 'department_code' => 'REV', 'hire_date' => '2026-01-05'],
        ['name' => 'Semira Aragaw Sisay', 'department_code' => 'REV', 'hire_date' => '2026-01-05'],
    ]);

    expect($result['created'])->toBe(2)
        ->and(nameImportHeadcount($tenant))->toBe(2);
});

it('never counts a name held in another organisation', function () {
    $other = createTenant();
    Employee::factory()->create(['tenant_id' => $other->id, 'name' => 'Semira Aragaw Sisay', 'department_id' => null]);
    app()->forgetScopedInstances();

    $tenant = nameImportOrganisation();

    expect(nameImport('first', [['name' => 'Semira Aragaw Sisay', 'hire_date' => '2026-01-05']])['created'])->toBe(1)
        ->and(nameImportHeadcount($tenant))->toBe(1);
});

it('does not count someone deleted', function () {
    $tenant = nameImportOrganisation();
    Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Semira Aragaw Sisay', 'department_id' => null])->delete();

    expect(nameImport('first', [['name' => 'Semira Aragaw Sisay', 'hire_date' => '2026-01-05']])['created'])->toBe(1);
});
