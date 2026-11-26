<?php

declare(strict_types=1);

use App\Models\Department;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Support\EmployeeImportRow;
use Tests\Support\StagingFixtures as Staging;

/*
 * A list that comes out of other software (PR 6): a title above the column
 * names, a name in parts, a department written as a path. Each is read
 * without the file being edited first, and a line keeps the number the
 * reader sees in their own file.
 */

/**
 * @param  list<list<string>>  $grid
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function readVendorList(object $tenant, array $grid, array $extra = []): array
{
    return test()->postJson(Staging::url($tenant, 'read'), ['grid' => $grid, ...$extra])->assertOk()->json();
}

it('finds the column names under a title, and says which line they were on', function () {
    [$tenant] = Staging::organisation();

    $read = readVendorList($tenant, [
        ['Employee list, Head office'],
        ['Name', 'Email', 'Phone'],
        ['Abebe Kebede', 'abebe@acme.et', '0911223344'],
        ['Hanna Girma', '', ''],
    ]);

    expect($read)->toMatchArray([
        'people' => 2,
        'header' => true,
        'header_line' => 2,
        'columns' => ['name', 'email', 'phone'],
        'problems' => [],
    ]);
});

it('skips a title, a blank line and a date line above the column names', function () {
    [$tenant] = Staging::organisation();

    $read = readVendorList($tenant, [
        ['Attendance export'],
        [],
        ['Exported on 2026-11-02'],
        ['Full name', 'Staff ID', 'Mobile'],
        ['Abebe Kebede', 'EMP-1', '0911223344'],
    ]);

    expect($read)->toMatchArray(['people' => 1, 'header' => true, 'header_line' => 4, 'columns' => ['name', 'employee_code', 'phone']]);
});

it('reads a list whose first line is the column names exactly as before', function () {
    [$tenant] = Staging::organisation();

    $read = readVendorList($tenant, [['Name', 'Email'], ['Abebe Kebede', 'abebe@acme.et']]);

    expect($read)->toMatchArray(['people' => 1, 'header' => true, 'header_line' => 1]);
});

it('reads a list with no column names anywhere as it always did', function () {
    [$tenant] = Staging::organisation();

    $read = readVendorList($tenant, [
        ['Abebe Kebede', 'abebe@acme.et', '0911223344'],
        ['Hanna Girma', '', ''],
    ]);

    expect($read)->toMatchArray(['people' => 2, 'header' => false, 'header_line' => null, 'columns' => EmployeeImportRow::DEFAULT_ORDER]);
});

it('does not take a line of people for column names because of a title above them', function () {
    [$tenant] = Staging::organisation();

    $read = readVendorList($tenant, [
        ['Abebe Kebede', 'abebe@acme.et'],
        ['Hanna Girma', 'hanna@acme.et'],
    ]);

    expect($read)->toMatchArray(['people' => 2, 'header' => false, 'header_line' => null]);
});

it('reads the line the reader names as the column names, and none when they say there are none', function () {
    [$tenant] = Staging::organisation();
    $grid = [
        ['Name', 'Email'],
        ['Name', 'Phone', 'Employee number'],
        ['Abebe Kebede', '0911223344', 'EMP-1'],
    ];

    // Found alone, the earliest of the best lines is read.
    expect(readVendorList($tenant, $grid))->toMatchArray(['header_line' => 2, 'columns' => ['name', 'phone', 'employee_code']]);

    $second = readVendorList($tenant, $grid, ['header_line' => 1]);
    expect($second)->toMatchArray(['header' => true, 'header_line' => 1, 'columns' => ['name', 'email']])
        ->and($second['problems'])->not->toBe([]);

    $none = readVendorList($tenant, [['Name', 'Email'], ['Abebe Kebede', 'abebe@acme.et']], ['header_line' => 0]);
    expect($none)->toMatchArray(['header' => false, 'header_line' => null, 'header_likely' => false, 'headers' => []])
        ->and($none['people'])->toBe(1)
        ->and(array_column($none['problems'], 'line'))->toBe([1]);
});

it('takes the line the reader names even when ETHR knows none of its words, and asks which is the name', function () {
    [$tenant] = Staging::organisation();
    $grid = [
        ['Quarterly staff report'],
        ['Emp Nm', 'Pers Ref', 'Cell'],
        ['Abebe Kebede', 'P-1', '0911223344'],
    ];

    $read = readVendorList($tenant, $grid, ['header_line' => 2]);

    expect($read)->toMatchArray(['header' => true, 'header_line' => 2, 'needs_name' => true, 'people' => 0])
        ->and(array_column($read['headers'], 'header'))->toBe(['Emp Nm', 'Pers Ref', 'Cell']);
});

it('keeps the file\'s own line numbers for a line it cannot stage when lines above were skipped', function () {
    [$tenant] = Staging::organisation();

    $read = readVendorList($tenant, [
        ['Export'],
        [],
        ['Name', 'Email'],
        ['Abebe Kebede', 'abebe@acme.et'],
        ['Bad Mail', 'not-an-email'],
    ]);

    expect($read['people'])->toBe(1)
        ->and($read['header_line'])->toBe(3)
        ->and(array_column($read['problems'], 'line'))->toBe([5]);
});

it('ignores the Problem column of a list with a title above it', function () {
    [$tenant] = Staging::organisation();

    $read = readVendorList($tenant, [
        ['Lines to fix'],
        ['Name', 'Email', 'Problem'],
        ['Abebe Kebede', 'abebe@acme.et', 'Line 5: Department not found'],
    ]);

    expect($read)->toMatchArray(['people' => 1, 'header_line' => 2, 'columns' => ['name', 'email'], 'ignored' => [], 'problems' => []]);
});

it('reads the columns the reader matched when the names are on a later line, found by the matched names', function () {
    [$tenant] = Staging::organisation();
    $grid = [
        ['Payroll export'],
        ['Emp Nm', 'Pers Ref', 'Cell'],
        ['Abebe Kebede', 'P-1042', '0911223344'],
    ];
    $mapping = [
        ['header' => 'Emp Nm', 'column' => 'name'],
        ['header' => 'Pers Ref', 'column' => 'external_id'],
        ['header' => 'Cell', 'column' => 'phone'],
    ];

    $read = readVendorList($tenant, $grid, ['mapping' => $mapping]);

    expect($read)->toMatchArray(['people' => 1, 'header' => true, 'header_line' => 2, 'columns' => ['name', 'external_id', 'phone']]);
});

it('offers a saved source for a list whose column names are on the third line', function () {
    [$tenant] = Staging::organisation();
    $source = test()->postJson(Staging::url($tenant, 'sources'), ['name' => 'Peachtree', 'mapping' => [
        ['header' => 'Full name', 'column' => 'name'],
        ['header' => 'Pers Ref', 'column' => 'external_id'],
    ]])->assertCreated()->json();

    $grid = [
        ['Peachtree'],
        [],
        ['Full name', 'Pers Ref', 'Mobile'],
        ['Abebe Kebede', 'P-1042', '0911223344'],
    ];

    expect(readVendorList($tenant, $grid))->toMatchArray(['header_line' => 3, 'suggested_source' => $source['public_id']]);

    $read = readVendorList($tenant, $grid, ['source' => $source['public_id']]);

    expect($read)->toMatchArray(['people' => 1, 'header_line' => 3])
        ->and($read['columns'])->toBe(['name', 'external_id', 'phone']);
});

it('builds the name from its parts, in order, one space apart', function () {
    [$tenant] = Staging::organisation();

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [
        ['First Name', 'Father Name', 'Grandfather Name', 'Employee number'],
        ['Abebe', 'Kebede', 'Tadesse', 'E-1'],
        ['Hanna', '', 'Girma', 'E-2'],
        ['Sara', '', '', 'E-3'],
    ]])->assertCreated()->json();

    expect(array_column($staged['rows'], 'display_name'))->toBe(['Abebe Kebede Tadesse', 'Hanna Girma', 'Sara']);
});

it('lets a column of the whole name win over the parts, and stores no part as a field', function () {
    [$tenant] = Staging::organisation();

    $read = readVendorList($tenant, [
        ['Name', 'Given name', 'Surname'],
        ['Abebe Kebede', 'Someone', 'Else'],
    ]);

    expect($read['people'])->toBe(1)
        ->and($read['columns'])->toBe(['name', 'first_name', 'grandfather_name']);

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [
        ['Name', 'Given name', 'Surname'],
        ['Abebe Kebede', 'Someone', 'Else'],
    ]])->assertCreated()->json();

    expect($staged['rows'][0]['display_name'])->toBe('Abebe Kebede');
    $raw = MigrationBatch::where('public_id', $staged['public_id'])->firstOrFail()->rows()->firstOrFail()->raw;
    expect(array_keys($raw))->not->toContain('first_name')->not->toContain('grandfather_name');
});

it('reads the Amharic names of the parts', function () {
    [$tenant] = Staging::organisation();

    $read = readVendorList($tenant, [
        ['First name', 'የአባት ስም', 'የአያት ስም'],
        ['አበበ', 'ከበደ', 'ታደሰ'],
    ]);

    expect($read)->toMatchArray(['people' => 1, 'columns' => ['first_name', 'father_name', 'grandfather_name'], 'ignored' => []]);

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [
        ['First name', 'የአባት ስም', 'የአያት ስም'],
        ['አበበ', 'ከበደ', 'ታደሰ'],
    ]])->assertCreated()->json();

    expect($staged['rows'][0]['display_name'])->toBe('አበበ ከበደ ታደሰ');
});

it('reads each spelling of a part as the part it is', function (string $header, string $column) {
    expect(EmployeeImportRow::column($header)[0])->toBe($column);
})->with([
    ['First Name', 'first_name'],
    ['Given Name', 'first_name'],
    ['Middle Name', 'father_name'],
    ['Father Name', 'father_name'],
    ["Father's Name", 'father_name'],
    ['Last Name', 'grandfather_name'],
    ['Surname', 'grandfather_name'],
    ['Family Name', 'grandfather_name'],
    ['Grandfather Name', 'grandfather_name'],
    ["Grandfather's Name", 'grandfather_name'],
    ['የአባት ስም', 'father_name'],
    ['የአያት ስም', 'grandfather_name'],
    ['ስም', 'name'],
    ['Name', 'name'],
    ['Full name', 'name'],
]);

it('leaves the file import reading its headers as it always did', function () {
    expect(EmployeeImportRow::header('Last Name'))->toBe('last_name')
        ->and(EmployeeImportRow::header('First Name'))->toBe('first_name')
        ->and(EmployeeImportRow::TEMPLATE)->not->toContain('first_name')
        ->and(EmployeeImportRow::COLUMNS)->not->toContain('first_name');
});

it('is satisfied by a first name when matching columns, and asks for a name when neither is matched', function () {
    [$tenant] = Staging::organisation();
    $grid = [['Emp Nm', 'Pers Ref'], ['Abebe', 'P-1']];

    $ready = readVendorList($tenant, $grid, ['mapping' => [
        ['header' => 'Emp Nm', 'column' => 'first_name'],
        ['header' => 'Pers Ref', 'column' => 'external_id'],
    ]]);
    expect($ready)->toMatchArray(['needs_name' => false, 'people' => 1]);

    $missing = readVendorList($tenant, $grid, ['mapping' => [
        ['header' => 'Emp Nm', 'column' => 'father_name'],
        ['header' => 'Pers Ref', 'column' => 'external_id'],
    ]]);
    expect($missing)->toMatchArray(['needs_name' => true, 'people' => 0]);
});

describe('a department written as a path', function () {
    it('is found by its last part', function (string $written) {
        [$tenant] = Staging::organisation();
        Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);

        $read = readVendorList($tenant, [['Name', 'Department'], ['Abebe Kebede', $written]]);

        expect($read['people'])->toBe(1)->and($read['problems'])->toBe([]);
    })->with([
        'slash' => 'Head office / Finance',
        'backslash' => 'Head office\\Finance',
        'angle' => 'Head office > Operations > finance ',
        'trailing separator' => 'Head office > Finance >',
    ]);

    it('matches a name that holds the separator as a whole, before it looks at parts', function () {
        [$tenant] = Staging::organisation();
        $whole = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales / Marketing']);
        Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Marketing']);

        $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [['Name', 'Department'], ['Abebe Kebede', 'Sales / Marketing']]])
            ->assertCreated()->json();
        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))->assertOk();

        expect(Employee::where('tenant_id', $tenant->id)->firstOrFail()->department_id)->toBe($whole->id);
    });

    it('stays a problem, with the whole value as written, when its last part is unknown', function () {
        [$tenant] = Staging::organisation();
        Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);

        $read = readVendorList($tenant, [['Name', 'Department'], ['Abebe Kebede', 'Head office / Marketing']]);

        expect($read['people'])->toBe(0)
            ->and($read['problems'])->toHaveCount(1)
            ->and($read['problems'][0]['code'])->toBe('relation_not_found')
            ->and($read['problems'][0]['message'])->toContain('Head office / Marketing');
    });

    it('stays a problem when its last part names two departments', function () {
        [$tenant] = Staging::organisation();
        Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => 'FIN-1']);
        Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => 'FIN-2']);

        $read = readVendorList($tenant, [['Name', 'Department'], ['Abebe Kebede', 'Head office / Finance']]);

        expect($read['people'])->toBe(0)
            ->and($read['problems'][0]['code'])->toBe('relation_ambiguous')
            ->and($read['problems'][0]['message'])->toContain('Head office / Finance');
    });

    it('is put on the department when the list is added, and when a person already here is matched', function () {
        [$tenant] = Staging::organisation();
        $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
        $existing = Staging::employee($tenant, ['name' => 'Hanna Girma', 'employee_code' => 'E-9']);

        $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [
            ['Name', 'Employee number', 'Department'],
            ['Abebe Kebede', 'E-1', 'Head office > Finance'],
            ['Hanna Girma', 'E-9', 'Head office > Finance'],
        ]])->assertCreated()->json();
        test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))->assertOk();

        expect(Employee::where('tenant_id', $tenant->id)->where('employee_code', 'E-1')->firstOrFail()->department_id)->toBe($finance->id)
            ->and($existing->refresh()->department_id)->toBe($finance->id);
    });

    it('does not read a path as a branch or a position', function () {
        [$tenant] = Staging::organisation();
        Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);

        $read = readVendorList($tenant, [['Name', 'Branch'], ['Abebe Kebede', 'Region / Bole']]);

        expect($read['people'])->toBe(0)->and($read['problems'][0]['code'])->toBe('relation_not_found');
    });
});

it('stages the same people from a list with its column names on line 3 as from one with them on line 1', function () {
    [$tenant] = Staging::organisation();
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
    $people = [
        ['Abebe Kebede', 'E-1', '0911223344', 'Finance'],
        ['Hanna Girma', 'E-2', '0922334455', 'Finance'],
    ];
    $names = ['Name', 'Employee number', 'Phone', 'Department'];

    $plain = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [$names, ...$people]])->assertCreated()->json();
    $titled = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [['Staff list'], [], $names, ...$people]])->assertCreated()->json();

    $raw = fn (array $batch): array => MigrationBatch::where('public_id', $batch['public_id'])->firstOrFail()->rows()->orderBy('id')->get()->map(fn ($row): array => $row->raw)->all();

    expect($raw($titled))->toBe($raw($plain))
        ->and(array_column($titled['rows'], 'display_name'))->toBe(['Abebe Kebede', 'Hanna Girma']);
});

it('stages a list as the reader said where its column names are, the same as it was read', function () {
    [$tenant] = Staging::organisation();
    $grid = [
        ['Name', 'Email'],
        ['Name', 'Phone'],
        ['Abebe Kebede', '0911223344'],
    ];

    // Read and staged with the same word, the two cannot disagree.
    expect(readVendorList($tenant, $grid, ['header_line' => 2]))->toMatchArray(['people' => 1, 'header_line' => 2]);

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => $grid, 'header_line' => 2])->assertCreated()->json();
    expect(array_column($staged['rows'], 'display_name'))->toBe(['Abebe Kebede']);

    // Without it the reader's choice is lost: line 1 is read as the names, and line 2 is refused.
    test()->postJson(Staging::url($tenant, 'rows'), ['grid' => $grid, 'header_line' => 1])->assertUnprocessable();
});

it('refuses a header line that is not a number of a line', function () {
    [$tenant] = Staging::organisation();

    test()->postJson(Staging::url($tenant, 'read'), ['grid' => [['Name']], 'header_line' => -1])
        ->assertUnprocessable()->assertJsonValidationErrors(['header_line']);
    test()->postJson(Staging::url($tenant, 'read'), ['grid' => [['Name']], 'header_line' => 'two'])
        ->assertUnprocessable()->assertJsonValidationErrors(['header_line']);
});
