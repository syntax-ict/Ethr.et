<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MigrationBatch;
use App\Models\MigrationStagingRow;
use App\Models\Position;
use App\Support\EmployeeImportRow;
use Tests\Support\StagingFixtures as Staging;

/*
 * A workbook is opened in the browser, which sends its cells as text
 * (One employee identity, PR 7). What a spreadsheet does to a value before
 * that is the server's to undo or to refuse: a leading zero dropped from a
 * phone, a long number rounded, a date kept as a day count. And the template
 * an organisation downloads offers, in its dropdowns, only what the import
 * will then accept.
 */

/**
 * @param  list<list<string>>  $grid
 * @return array<string, mixed>
 */
function readWorkbookCells(string $url, array $grid, string $calendar = 'gregorian'): array
{
    return test()->postJson($url, ['grid' => $grid, 'calendar' => $calendar])->assertOk()->json();
}

it('refuses a number the spreadsheet rounded, and says how to keep it whole', function (string $column, string $value) {
    [$tenant] = Staging::organisation();

    $read = readWorkbookCells(Staging::url($tenant, 'read'), [['Name', $column], ['Abebe Kebede', $value]]);

    expect($read['people'])->toBe(0)
        ->and($read['problems'][0]['line'])->toBe(2)
        ->and(implode(' ', array_column($read['problems'], 'message')))->toContain('Format the column as text');
})->with([
    ['National ID', '1.23457E+15'],
    ['National ID', '1.2345678901234568e+21'],
    ['Employee number', '1.0E+15'],
    ['TIN', '1.2E+9'],
    ['Phone', '9.11223E+8'],
    ['Legacy ID', '4.5e+16'],
]);

it('takes a whole number that came through as a decimal, and a phone that lost its zero', function () {
    [$tenant] = Staging::organisation();

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => [
        ['Name', 'Employee number', 'Phone', 'Badge'],
        ['Abebe Kebede', '1001.0', '911223344', '77.00'],
    ]])->assertCreated()->json();

    $raw = Staging::service()->employees(MigrationBatch::where('public_id', $staged['public_id'])->firstOrFail());
    expect($raw)->toBeEmpty();

    $row = MigrationStagingRow::where('public_id', $staged['rows'][0]['public_id'])->firstOrFail()->raw;

    expect($row['employee_code'])->toBe('1001')
        ->and($row['badge_number'])->toBe('77')
        ->and($row['phone'])->toBe('911223344');

    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))->assertOk();

    expect(Employee::where('tenant_id', $tenant->id)->where('employee_code', '1001')->value('phone'))
        ->toBe('+251911223344');
});

it('leaves a decimal alone where it means something', function () {
    $row = EmployeeImportRow::normalize(['name' => 'Abebe Kebede', 'salary' => '12500.0', 'employee_code' => '10.5']);

    expect($row['salary'])->toBe('12500.0')
        ->and($row['employee_code'])->toBe('10.5');
});

it('reads a date the spreadsheet kept as a day count, in the Gregorian calendar whatever the list is in', function (string $calendar) {
    $row = EmployeeImportRow::normalize(['name' => 'Abebe Kebede', 'hire_date' => '43654', 'date_of_birth' => '32874'], $calendar);

    expect($row['hire_date'])->toBe('2019-07-08')
        ->and($row['date_of_birth'])->toBe('1990-01-01');
})->with(['gregorian', 'ethiopian']);

it('does not take a year, or a number out of range, for a day count', function (string $value) {
    $row = EmployeeImportRow::normalize(['name' => 'Abebe Kebede', 'hire_date' => $value]);

    expect($row['hire_date'])->toBe($value);
})->with(['2019', '19999', '60001', '436540']);

it('reads a date cell the browser marked as Gregorian in a list of Ethiopian dates', function () {
    [$tenant] = Staging::organisation();

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['calendar' => 'ethiopian', 'grid' => [
        ['Name', 'Hire date'],
        ['Typed As Text', '2011-10-30'],
        ['A Real Date Cell', '2019-07-07 G.C.'],
    ]])->assertCreated()->json();

    $dates = MigrationBatch::where('public_id', $staged['public_id'])->firstOrFail()
        ->rows()->orderBy('id')->get()->map(fn ($row): ?string => $row->raw['hire_date'] ?? null)->all();

    // 30 Sene 2011 is 7 July 2019: the same day, written both ways.
    expect($dates)->toBe(['2019-07-07', '2019-07-07']);
});

it('serves what each choice column may hold, from this organisation only', function () {
    [$tenant] = Staging::organisation();
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => 'FIN']);
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales', 'code' => 'SAL-N']);
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'sales', 'code' => 'SAL-S']);
    Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bole', 'code' => 'BOL']);
    Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Accountant']);

    $other = createTenant();
    Department::factory()->create(['tenant_id' => $other->id, 'name' => 'Their Secret Unit']);
    Branch::factory()->create(['tenant_id' => $other->id, 'name' => 'Their Branch']);

    $template = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/import/template")->assertOk()->json();

    expect($template['required'])->toBe(['name'])
        ->and($template['text_columns'])->toBe(EmployeeImportRow::TEXT_COLUMNS)
        ->and($template['date_columns'])->toBe(EmployeeImportRow::DATES)
        ->and($template['lists']['gender'])->toBe(['male', 'female'])
        ->and($template['lists']['status'])->toBe(EmployeeImportRow::STATUSES)
        // Two departments share a name, which the import refuses as
        // ambiguous: each is offered by its code instead.
        ->and($template['lists']['department'])->toEqualCanonicalizing(['Finance', 'SAL-N', 'SAL-S'])
        ->and($template['lists']['branch'])->toBe(['Bole'])
        ->and($template['lists']['position'])->toBe(['Accountant'])
        ->and(json_encode($template))->not->toContain('Their');

    // Every choice column is one the template has, and a text or date column too.
    foreach ([...array_keys($template['lists']), ...$template['text_columns'], ...$template['date_columns']] as $column) {
        expect($template['headers'])->toContain($column);
    }
});

it('offers nothing the import then refuses', function () {
    [$tenant] = Staging::organisation();
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales', 'code' => 'SAL-N']);
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales', 'code' => 'SAL-S']);
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => null]);
    Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Bole']);
    Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Accountant']);

    $lists = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/import/template")->json('lists');

    $grid = [['Name', 'Gender', 'Status', 'Nationality status', 'Marital status', 'Department', 'Branch', 'Position']];
    $longest = max(array_map('count', $lists));
    for ($i = 0; $i < $longest; $i++) {
        $pick = fn (string $list): string => $lists[$list][$i % count($lists[$list])];
        $grid[] = ["Person {$i}", $pick('gender'), $pick('status'), $pick('nationality_status'), $pick('marital_status'), $pick('department'), $pick('branch'), $pick('position')];
    }

    $read = readWorkbookCells(Staging::url($tenant, 'read'), $grid);

    expect($read['problems'])->toBe([])->and($read['people'])->toBe($longest);
});
