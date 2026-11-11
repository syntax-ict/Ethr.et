<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\MigrationBatch;
use App\Models\Position;
use App\Support\EmployeeImportRow;
use Tests\Support\StagingFixtures as Staging;

/*
 * One parser (One employee identity, PR 6). The guided setup's paste box read
 * its list in the browser, with its own table of column names and its own
 * date rule, and the file import read its file on the server with another.
 * A pasted list now arrives as cells and is read where a file is read, by
 * EmployeeImportRow.
 */

/**
 * @param  list<list<string>>  $grid
 * @return array<string, mixed>
 */
function readPeopleList(string $url, array $grid, string $calendar = 'gregorian'): array
{
    return test()->postJson($url, ['grid' => $grid, 'calendar' => $calendar])->assertOk()->json();
}

it('reads column names however they are written, in any order, and names the ones that mean nothing', function () {
    [$tenant] = Staging::organisation();
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => 'FIN']);

    $read = readPeopleList(Staging::url($tenant, 'read'), [
        ['Dept', 'ሙሉ ስም', 'Start date', 'Staff ID', 'Notes', 'ስልክ'],
        ['Finance', 'Abebe Kebede', '2019-07-08', 'EMP-1', 'senior', '0911223344'],
        ['fin', 'Hanna Girma', '', '', '', ''],
    ]);

    expect($read)->toMatchArray([
        'people' => 2,
        'header' => true,
        'columns' => ['department_code', 'name', 'hire_date', 'employee_code', 'phone'],
        'ignored' => ['Notes'],
        'without_hire_date' => 1,
        'problems' => [],
    ]);

    expect(MigrationBatch::count())->toBe(0);
});

it('gives a column the meaning the file import gives it', function (string $header, string $column) {
    expect(EmployeeImportRow::header($header))->toBe($column);
})->with([
    ['Badge', 'badge_number'],
    ['Badge number', 'badge_number'],
    ['Device ID', 'badge_number'],
    ['Employee number', 'employee_code'],
    ['Staff number', 'employee_code'],
    ['E-mail', 'email'],
    ['ኢሜይል', 'email'],
    ['የተቀጠረበት ቀን', 'hire_date'],
    ['Hire date (E.C.)', 'hire_date'],
    ['Date of birth (G.C.)', 'date_of_birth'],
    ['Division', 'department_code'],
    ['Salary (ETB)', 'salary'],
    ['Employment status', 'status'],
    ['ID in the other system', 'external_id'],
]);

it('reads a list with no column names in the documented order, only the name required', function () {
    [$tenant] = Staging::organisation();
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales']);

    $read = readPeopleList(Staging::url($tenant, 'read'), [
        ['Abebe Kebede', 'abebe@acme.et', '0911223344', 'EMP-1', '2019-07-08', 'Sales'],
        ['Hanna Girma'],
    ]);

    expect($read)->toMatchArray([
        'people' => 2,
        'header' => false,
        'columns' => EmployeeImportRow::DEFAULT_ORDER,
        'problems' => [],
    ]);
});

it('reads a date year first or day first, in the calendar the column, the choice or the date itself names', function () {
    [$tenant] = Staging::organisation();
    $url = Staging::url($tenant, 'rows');

    $staged = function (array $grid, string $calendar = 'gregorian') use ($url): array {
        $id = test()->postJson($url, ['grid' => $grid, 'calendar' => $calendar])->assertCreated()->json('public_id');

        return MigrationBatch::where('public_id', $id)->firstOrFail()->rows()->orderBy('id')->get()
            ->map(fn ($row): ?string => $row->raw['hire_date'] ?? null)
            ->all();
    };

    // 30 Sene 2011 is 7 July 2019.
    expect($staged([
        ['Name', 'Hire date'],
        ['Year First', '2019/7/8'],
        ['Day First', '15/03/2024'],
        ['Says Itself', '2011-10-30 E.C.'],
    ]))->toBe(['2019-07-08', '2024-03-15', '2019-07-07']);

    expect($staged([['Name', 'Hire date'], ['By Choice', '2011-10-30']], 'ethiopian'))->toBe(['2019-07-07']);

    // The column name is over the choice.
    expect($staged([['Name', 'Hire date (E.C.)'], ['By Header', '30/10/2011']], 'gregorian'))->toBe(['2019-07-07']);
    expect($staged([['Name', 'Hire date (G.C.)'], ['By Header', '2019-07-07']], 'ethiopian'))->toBe(['2019-07-07']);
});

it('names each line it cannot stage, counted as the reader sees it, and leaves it out', function () {
    [$tenant] = Staging::organisation();
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);

    $read = readPeopleList(Staging::url($tenant, 'read'), [
        ['Name', 'Email', 'Employee number', 'Hire date', 'Department'],
        ['Abebe Kebede', 'abebe@acme.et', 'EMP-1', '2019-07-08', 'Finance'],
        [],
        ['', 'nobody@acme.et', '', '', ''],
        ['Bad Mail', 'not-an-email', '', '', ''],
        ['Bad Date', '', '', '2019-02-30', ''],
        ['Same Number', '', 'emp-1', '', ''],
        ['No Such Dept', '', '', '', 'Marketing'],
    ]);

    expect($read['people'])->toBe(1)
        ->and(array_column($read['problems'], 'line'))->toBe([4, 5, 6, 7, 8])
        ->and($read['problems'][3]['message'])->toContain('line 2')
        ->and($read['problems'][4]['message'])->toContain('Marketing');
});

it('says when a list is over what one staging takes', function () {
    [$tenant] = Staging::organisation();

    $grid = [['Name']];
    for ($i = 1; $i <= 2001; $i++) {
        $grid[] = ["Person {$i}"];
    }

    $read = readPeopleList(Staging::url($tenant, 'read'), $grid);

    expect($read['people'])->toBe(2001)
        ->and($read['problems'])->toHaveCount(1)
        ->and($read['problems'][0]['message'])->toContain('2000');
});

it('stages nothing from a list with a line it cannot read, and says which', function () {
    [$tenant] = Staging::organisation();

    test()->postJson(Staging::url($tenant, 'rows'), [
        'grid' => [['Name', 'Email'], ['Abebe Kebede', 'abebe@acme.et'], ['Bad Mail', 'nope']],
    ])->assertUnprocessable()->assertJsonValidationErrors(['grid.2']);

    expect(MigrationBatch::count())->toBe(0);
});

it('stages the lines it can read and leaves the rest out, when asked to', function () {
    [$tenant] = Staging::organisation();
    $grid = [['Name', 'Email'], ['Abebe Kebede', 'abebe@acme.et'], ['Bad Mail', 'nope'], ['Hanna Girma', '']];

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => $grid, 'skip_problems' => true])
        ->assertCreated()
        ->json();

    expect(array_column($staged['rows'], 'display_name'))->toBe(['Abebe Kebede', 'Hanna Girma']);

    // Nothing left once the bad lines are out is still nothing to stage.
    test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [['Name', 'Email'], ['Bad Mail', 'nope']], 'skip_problems' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['grid']);
});

it('makes a new person from everything their line brings', function () {
    [$tenant] = Staging::organisation();
    $department = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
    $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bole', 'code' => 'BOL']);
    $position = Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Accountant']);

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [
        ['Name', 'Amharic name', 'Employee number', 'Phone', 'Gender', 'Hire date', 'Department', 'Branch', 'Position', 'Salary', 'Fayda', 'TIN', 'Badge', 'Status', 'ID in the other system'],
        ['Abebe Kebede', 'አበበ ከበደ', 'EMP-1', '0911223344', 'M', '08/07/2019', 'Finance', 'bol', 'Accountant', '12,500.50 ETB', '1234567890123456', '0012345678', 'B-77', 'Active', 'HR-00042'],
    ]])->assertCreated()->json();

    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))
        ->assertOk()
        ->assertJsonPath('totals.created', 1);

    $abebe = Employee::where('tenant_id', $tenant->id)->where('employee_code', 'EMP-1')->firstOrFail();

    expect($abebe->only(['name', 'name_am', 'phone', 'gender', 'badge_number', 'national_id', 'tin', 'salary_cents', 'department_id', 'branch_id', 'position_id']))
        ->toBe([
            'name' => 'Abebe Kebede',
            'name_am' => 'አበበ ከበደ',
            'phone' => '+251911223344',
            'gender' => 'male',
            'badge_number' => 'B-77',
            'national_id' => '1234567890123456',
            'tin' => '0012345678',
            'salary_cents' => 1250050,
            'department_id' => $department->id,
            'branch_id' => $branch->id,
            'position_id' => $position->id,
        ])
        ->and($abebe->hire_date->toDateString())->toBe('2019-07-08')
        ->and($abebe->status)->toBe(EmployeeStatus::CONFIRMED)
        ->and($abebe->confirmation_date?->toDateString())->toBe(now()->toDateString());

    expect(EmployeeExternalIdentity::where('employee_id', $abebe->id)->where('source_type', 'hr_system')->value('identifier_value'))
        ->toBe('HR-00042');
});

it('finds the same person by the other system\'s id in a later list, whatever else changed', function () {
    [$tenant] = Staging::organisation();
    $url = Staging::url($tenant, 'rows');

    $first = test()->postJson($url, ['grid' => [
        ['Name', 'Legacy ID'],
        ['Abebe Kebede', 'HR-00042'],
    ]])->assertCreated()->json();
    test()->postJson(Staging::url($tenant, "batches/{$first['public_id']}/commit"))->assertOk();

    // Married, renumbered, a new phone: nothing a person is matched by is the same.
    $second = test()->postJson($url, ['grid' => [
        ['Name', 'Legacy ID', 'Phone'],
        ['Abebe K. Tadesse', 'HR-00042 ', '0911223344'],
    ]])->assertCreated()->json();

    expect($second['rows'][0])->toMatchArray(['match_outcome' => 'matched', 'action' => 'merge']);

    test()->postJson(Staging::url($tenant, "batches/{$second['public_id']}/commit"))
        ->assertOk()
        ->assertJsonPath('totals.created', 0)
        ->assertJsonPath('totals.merged', 1);

    expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('needs the pay permission to stage a list that sets a salary or a TIN', function () {
    $tenant = createTenant();
    test()->actingAs(createScopedUser($tenant, null, 'all', ['employee.create', 'employee.viewAny']));
    $url = Staging::url($tenant, 'rows');

    test()->postJson($url, ['grid' => [['Name', 'Salary'], ['Abebe Kebede', '12500']]])->assertForbidden();
    test()->postJson($url, ['rows' => [['name' => 'Abebe Kebede', 'tin' => '0012345678']]])->assertForbidden();
    test()->postJson($url, ['grid' => [['Name', 'Phone'], ['Abebe Kebede', '0911223344']]])->assertCreated();

    expect(MigrationBatch::count())->toBe(1);
});

it('still takes people already keyed by field, with every column a list has', function () {
    [$tenant] = Staging::organisation();
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
    Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bole']);

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['rows' => [[
        'name' => 'Abebe Kebede',
        'hire_date' => '2019-07-08',
        'department' => 'Finance',
        'branch' => 'Bole',
        'salary' => '9000',
        'status' => 'probation',
    ]]])->assertCreated()->json();

    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))->assertOk();

    $abebe = Employee::where('tenant_id', $tenant->id)->firstOrFail();
    expect($abebe->salary_cents)->toBe(900000)
        ->and($abebe->status)->toBe(EmployeeStatus::PROBATION)
        ->and($abebe->branch_id)->not->toBeNull();

    test()->postJson(Staging::url($tenant, 'rows'), ['rows' => [['name' => 'Hanna Girma', 'branch' => 'Piassa']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rows.0.branch']);
});

it('does not read another organisation\'s departments', function () {
    [$tenant] = Staging::organisation();
    Department::factory()->create(['tenant_id' => createTenant()->id, 'name' => 'Finance']);

    $read = readPeopleList(Staging::url($tenant, 'read'), [['Name', 'Department'], ['Abebe Kebede', 'Finance']]);

    expect($read['people'])->toBe(0)->and($read['problems'])->toHaveCount(1);
});
