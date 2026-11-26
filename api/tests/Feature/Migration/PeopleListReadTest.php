<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\ImportMapping;
use App\Models\MigrationBatch;
use App\Models\Position;
use App\Services\Device\AdmsRoster;
use App\Services\Migration\WorkforceMigrationService;
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

it('warns of a badge number a terminal of the organisation would refuse, and stages the person all the same', function () {
    [$tenant] = Staging::organisation();
    Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id, 'adapter_type' => 'zkteco_adms']);
    Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id, 'adapter_type' => 'zkteco_adms']);

    $read = readPeopleList(Staging::url($tenant, 'read'), [
        ['Name', 'Badge'],
        ['Abebe Kebede', 'A12'],
        ['Hanna Girma', '0012'],
    ]);

    expect($read['people'])->toBe(2)
        ->and($read['problems'])->toBe([])
        ->and($read['warnings'])->toHaveCount(1)
        ->and($read['warnings'][0])->toMatchArray(['line' => 2, 'code' => 'terminal_number_not_accepted'])
        ->and($read['warnings'][0]['message'])->toContain('A12')->toContain('a ZKTeco terminal')->not->toContain('zkteco_adms');
});

it('gives no warning to an organisation with no terminal that refuses the number, or with a deleted one', function () {
    [$tenant] = Staging::organisation();
    Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id, 'adapter_type' => 'hikvision']);
    Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id, 'adapter_type' => 'zkteco'])->delete();

    $read = readPeopleList(Staging::url($tenant, 'read'), [['Name', 'Badge'], ['Abebe Kebede', 'A12']]);

    expect($read['people'])->toBe(1)->and($read['warnings'])->toBe([]);
});

it('does not warn because of another organisation\'s terminal', function () {
    [$tenant] = Staging::organisation();
    $other = createTenant();
    Device::factory()->create(['tenant_id' => $other->id, 'branch_id' => Branch::factory()->create(['tenant_id' => $other->id])->id, 'adapter_type' => 'zkteco_adms']);

    $read = readPeopleList(Staging::url($tenant, 'read'), [['Name', 'Badge'], ['Abebe Kebede', 'A12']]);

    expect($read['people'])->toBe(1)->and($read['warnings'])->toBe([]);
});

it('carries what staging noted about a device-staged row in the batch', function () {
    [$tenant] = Staging::organisation();
    $device = Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id, 'adapter_type' => 'zkteco_adms']);
    app(AdmsRoster::class)->record($device, [
        'A_1' => ['pin' => 'A_1', 'user' => true, 'name' => 'Odd Id', 'card' => null, 'is_admin' => false, 'fingerprints' => 0, 'face' => false],
        '17' => ['pin' => '17', 'user' => true, 'name' => 'Plain Id', 'card' => null, 'is_admin' => false, 'fingerprints' => 0, 'face' => false],
    ]);
    $batch = app(WorkforceMigrationService::class)->stageFromDevice($device);

    $rows = collect(test()->getJson(Staging::url($tenant, "batches/{$batch->public_id}"))->assertOk()->json('rows'))->keyBy('display_name');

    expect($rows['Odd Id']['warnings'])->toHaveCount(1)
        ->and($rows['Odd Id']['warnings'][0]['code'])->toBe('device_id_not_badge')
        ->and($rows['Plain Id']['warnings'])->toBe([]);
});

it('says which column each problem is about, and a code derived from what was refused', function () {
    [$tenant] = Staging::organisation();
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales', 'code' => 'S1']);
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales', 'code' => 'S2']);

    $read = readPeopleList(Staging::url($tenant, 'read'), [
        ['Name', 'Email', 'Employee number', 'Hire date', 'Department', 'Gender', 'Contract type', 'Contract end date', 'Probation end date', 'Bank name', 'Bank account'],
        ['Abebe Kebede', 'abebe@acme.et', 'EMP-1', '2019-07-08', 'Finance', '', '', '', '', '', ''],
        ['', '', '', '', '', '', '', '', '', '', ''],
        ['Bad Mail', 'not-an-email', '', '', '', '', '', '', '', '', ''],
        ['Bad Date', '', '', '2019-02-30', '', '', '', '', '', '', ''],
        ['Bad Gender', '', '', '', '', 'robot', '', '', '', '', ''],
        ['Same Number', '', 'emp-1', '', '', '', '', '', '', '', ''],
        ['No Such Dept', '', '', '', 'Marketing', '', '', '', '', '', ''],
        ['Two Depts', '', '', '', 'Sales', '', '', '', '', '', ''],
        ['Contract No Type', '', '', '2020-01-01', '', '', '', '2021-01-01', '', '', ''],
        ['Fixed No End', '', '', '2020-01-01', '', '', 'fixed_term', '', '', '', ''],
        ['Probation Early', '', '', '2020-01-01', '', '', '', '', '2019-01-01', '', ''],
        ['Half Bank', '', '', '', '', '', '', '', '', 'CBE', ''],
    ]);

    $by = collect($read['problems'])->groupBy('line')->map(fn ($found) => $found->map(fn ($p) => [$p['column'], $p['code']])->all());

    expect($by[4])->toBe([['email', 'email']])
        ->and($by[5])->toBe([['hire_date', 'date']])
        ->and($by[6])->toBe([['gender', 'in']])
        ->and($by[7])->toBe([['employee_code', 'duplicate_in_list']])
        ->and($by[8])->toBe([['department', 'relation_not_found']])
        ->and($by[9])->toBe([['department', 'relation_ambiguous']])
        ->and($by[10])->toBe([['contract_type', 'contract_type_needed']])
        ->and($by[11])->toBe([['contract_end_date', 'contract_end_needed']])
        ->and($by[12])->toBe([['probation_end_date', 'probation_before_hire']])
        ->and($by[13])->toBe([['bank_account', 'required_with']])
        ->and($read['problems'][0])->toHaveKeys(['line', 'message', 'column', 'code']);
});

it('names the contract problems that need a hire date or a later end', function () {
    [$tenant] = Staging::organisation();

    $read = readPeopleList(Staging::url($tenant, 'read'), [
        ['Name', 'Hire date', 'Contract type', 'Contract end date'],
        ['No Hire', '', 'permanent', ''],
        ['Ends Early', '2020-01-01', 'fixed_term', '2019-12-31'],
    ]);

    $by = collect($read['problems'])->mapWithKeys(fn ($p) => [$p['line'] => [$p['column'], $p['code']]])->all();

    expect($by)->toBe([
        2 => ['hire_date', 'contract_needs_hire_date'],
        3 => ['contract_end_date', 'contract_end_before_start'],
    ]);
});

it('gives the problem about a whole list no column', function () {
    [$tenant] = Staging::organisation();

    $grid = [['Name']];
    for ($i = 1; $i <= 2001; $i++) {
        $grid[] = ["Person {$i}"];
    }

    $read = readPeopleList(Staging::url($tenant, 'read'), $grid);

    expect($read['problems'][0])->toMatchArray(['column' => null, 'code' => 'too_many']);
});

it('says which column a warning is about', function () {
    [$tenant] = Staging::organisation();
    Device::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => Branch::factory()->create(['tenant_id' => $tenant->id])->id, 'adapter_type' => 'zkteco_adms']);

    $read = readPeopleList(Staging::url($tenant, 'read'), [['Name', 'Badge'], ['Abebe Kebede', 'A12']]);

    expect($read['warnings'][0])->toMatchArray(['line' => 2, 'column' => 'badge_number', 'code' => 'terminal_number_not_accepted']);
});

it('ignores a Problem column when the lines to fix come back, and changes nothing because of it', function () {
    [$tenant] = Staging::organisation();

    $read = readPeopleList(Staging::url($tenant, 'read'), [
        ['Name', 'Email', 'Problem'],
        ['Abebe Kebede', 'abebe@acme.et', 'Line 4: The email must be a valid email address.'],
        ['Hanna Girma', '', 'hire_date'],
    ]);

    expect($read['people'])->toBe(2)
        ->and($read['problems'])->toBe([])
        ->and($read['ignored'])->toBe([])
        ->and($read['columns'])->toBe(['name', 'email'])
        ->and(array_column($read['headers'], 'header'))->toBe(['Name', 'Email'])
        ->and(array_column($read['headers'], 'suggested'))->toBe([null, null]);

    // A column headed differently is still reported as not read.
    $other = readPeopleList(Staging::url($tenant, 'read'), [['Name', 'Notes'], ['Abebe Kebede', 'senior']]);
    expect($other['ignored'])->toBe(['Notes']);
});

it('carries the number of the person a row matched, for someone who may open that person', function () {
    [$tenant] = Staging::organisation();
    $abebe = Staging::employee($tenant, ['employee_code' => 'E-77']);

    $staged = test()->postJson(Staging::url($tenant, 'rows'), [
        'rows' => [['name' => 'Abebe Kebede', 'employee_code' => 'E-77'], ['name' => 'Nobody Yet']],
    ])->assertCreated()->json();

    $shown = collect(test()->getJson(Staging::url($tenant, "batches/{$staged['public_id']}"))->assertOk()->json('rows'))->keyBy('display_name');

    expect($shown['Abebe Kebede'])->toMatchArray(['resolved_employee_public_id' => $abebe->public_id, 'resolved_employee_code' => 'E-77'])
        ->and($shown['Nobody Yet'])->toMatchArray(['resolved_employee_public_id' => null, 'resolved_employee_code' => null]);
});

it('reads the example people of the employee template without a problem', function () {
    [$tenant] = Staging::organisation();

    $read = readPeopleList(Staging::url($tenant, 'read'), [
        ['name', 'hire_date', 'probation_end_date', 'contract_type', 'contract_end_date', 'bank_name', 'bank_branch', 'bank_account', 'emergency_name', 'emergency_relationship', 'emergency_phone'],
        ['Abebe Kebede', '2019-07-08', '2019-10-08', 'permanent', '', 'Example Bank', 'Main branch', '1000000000001', 'Almaz Kebede', 'Sister', '0911000011'],
        ['Hanna Girma', '2023-02-20', '2023-05-20', 'fixed_term', '2025-02-19', 'Example Bank', 'Bole branch', '1000000000002', 'Dawit Girma', 'Brother', '0911000022'],
    ]);

    expect($read['problems'])->toBe([])->and($read['people'])->toBe(2);
});

it('drops a Problem column whole, whatever else was said about the list', function () {
    [$tenant] = Staging::organisation();
    $url = Staging::url($tenant, 'read');

    // The first line is not recognised as column names, so the reader matches them.
    $without = readPeopleList($url, [['Staff', 'Mail'], ['Abebe Kebede', 'abebe@acme.et']]);
    expect($without['header'])->toBeFalse();

    $mapping = [['header' => 'Staff', 'column' => 'name'], ['header' => 'Mail', 'column' => 'email']];
    $plain = test()->postJson($url, ['grid' => [['Staff', 'Mail'], ['Abebe Kebede', 'abebe@acme.et']], 'mapping' => $mapping])->assertOk()->json();
    $with = test()->postJson($url, ['grid' => [['Staff', 'Mail', 'Problem'], ['Abebe Kebede', 'abebe@acme.et', 'Line 2: The email is wrong']], 'mapping' => $mapping])->assertOk()->json();

    expect($with['people'])->toBe($plain['people'])->toBe(1)
        ->and($with['problems'])->toBe([])
        ->and($with['columns'])->toBe($plain['columns'])
        ->and($with['ignored'])->toBe([])
        ->and(array_column($with['headers'], 'header'))->toBe(['Staff', 'Mail']);

    // Not matched at all, and not recognised: the Problem cell is no phone number.
    $positional = readPeopleList($url, [['Staff', 'Mail', 'Problem'], ['Abebe Kebede', 'abebe@acme.et', 'x y z']]);
    expect(array_column($positional['headers'], 'header'))->toBe(['Staff', 'Mail'])
        ->and($positional['ignored'])->toBe([]);
});

it('still finds a saved source for a file that has gained a Problem column', function () {
    [$tenant] = Staging::organisation();
    $url = Staging::url($tenant, 'read');
    $source = ImportMapping::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Old payroll',
        'slug' => 'old-payroll',
        'columns' => [['header' => 'Staff', 'column' => 'name'], ['header' => 'Mail', 'column' => 'email']],
    ]);

    $read = readPeopleList($url, [['Staff', 'Mail', 'Problem'], ['Abebe Kebede', 'abebe@acme.et', 'Line 2: x']]);

    expect($read['suggested_source'])->toBe($source->public_id)
        ->and(array_column($read['headers'], 'header'))->toBe(['Staff', 'Mail']);
});

it('drops a Problem cell only from the first line', function () {
    [$tenant] = Staging::organisation();

    $read = readPeopleList(Staging::url($tenant, 'read'), [
        ['Abebe Kebede', 'abebe@acme.et', '0911223344', 'Problem solving'],
        ['Hanna Girma', 'hanna@acme.et', '0911223355', 'Problem'],
    ]);

    expect($read['header'])->toBeFalse()->and($read['people'])->toBe(2)->and($read['problems'])->toBe([]);
});

it('reads a sign-in column in three languages, keeps the answer on the staged row, and refuses a value that is not yes or no with its column named', function () {
    [$tenant] = Staging::organisation();

    $read = readPeopleList(Staging::url($tenant, 'read'), [
        ['Name', 'Email', 'Sign in'],
        ['Abebe Kebede', 'abebe@acme.et', 'Yes'],
        ['Hanna Girma', 'hanna@acme.et', 'አይ'],
        ['Dawit Bekele', 'dawit@acme.et', 'Eeyyee'],
        ['Sara Tesfaye', 'sara@acme.et', ''],
        ['Yonas Haile', 'yonas@acme.et', 'maybe'],
    ]);

    expect($read['columns'])->toBe(['name', 'email', 'sign_in'])
        ->and($read['people'])->toBe(4)
        ->and($read['problems'])->toHaveCount(1)
        ->and($read['problems'][0])->toMatchArray([
            'line' => 6,
            'column' => 'sign_in',
            'code' => 'in',
            'message' => 'Sign in must be yes or no, or left empty.',
        ]);

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [
        ['Name', 'Email', 'Sign in'],
        ['Abebe Kebede', 'abebe@acme.et', 'Yes'],
        ['Hanna Girma', 'hanna@acme.et', 'አይ'],
        ['Dawit Bekele', 'dawit@acme.et', 'Lakki'],
        ['Sara Tesfaye', 'sara@acme.et', ''],
    ]])->assertCreated()->json();

    $kept = MigrationBatch::where('public_id', $staged['public_id'])->firstOrFail()->rows()->orderBy('id')->get()
        ->map(fn ($row) => $row->raw['sign_in'] ?? null)->all();
    expect($kept)->toBe(['yes', 'no', 'no', null]);
});

it('refuses a sign-in value that is not yes or no in a list sent as keyed rows, and names no person for it', function () {
    [$tenant] = Staging::organisation();

    test()->postJson(Staging::url($tenant, 'rows'), ['rows' => [['name' => 'Abebe Kebede', 'sign_in' => 'maybe']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rows.0.sign_in' => 'Sign in must be yes or no, or left empty.']);

    expect(MigrationBatch::count())->toBe(0);
});

it('reads a column named Invite as the sign-in column, and one named Login as nothing at all', function () {
    [$tenant] = Staging::organisation();

    $invite = readPeopleList(Staging::url($tenant, 'read'), [['Name', 'Invite'], ['Abebe Kebede', 'yes']]);
    expect($invite['columns'])->toBe(['name', 'sign_in'])->and($invite['problems'])->toBe([]);

    // A login is often a username: neither read as yes or no nor offered as a guess.
    $login = readPeopleList(Staging::url($tenant, 'read'), [['Name', 'Login'], ['Abebe Kebede', 'abebe.k']]);
    expect($login['columns'])->toBe(['name'])
        ->and($login['ignored'])->toBe(['Login'])
        ->and($login['headers'][1])->toMatchArray(['header' => 'Login', 'column' => null, 'suggested' => null]);
});
