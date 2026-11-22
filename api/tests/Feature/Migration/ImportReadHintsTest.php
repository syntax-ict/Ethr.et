<?php

declare(strict_types=1);

use App\Models\ImportMapping;
use App\Support\DateColumnCalendar;
use App\Support\EmployeeImportRow;
use Tests\Support\StagingFixtures as Staging;

/*
 * What a list's own cells can tell the reader, by rule (2026-11-22, no model
 * involved): dates real in one calendar only, column names in either Amharic
 * spelling or one letter out, and one name written twice.
 */

it('knows the calendar of a column only from a date real in one calendar alone', function (array $values, ?string $calendar, ?int $line) {
    $found = DateColumnCalendar::detect($values);

    expect($found['calendar'] ?? null)->toBe($calendar)
        ->and($found['line'] ?? null)->toBe($line);
})->with([
    'a 13th month' => [[2 => '2014-03-01', 3 => '2016-13-03'], 'ethiopian', 3],
    'a 30 February, day first' => [[2 => '30/02/2016'], 'ethiopian', 2],
    'a 29 February of a Gregorian common year' => [[2 => '2015-02-29'], 'ethiopian', 2],
    'a 31st' => [[2 => '2016-04-15', 5 => '31.01.2019'], 'gregorian', 5],
    'dates real in both' => [[2 => '2016-04-15', 3 => '15/04/2016'], null, null],
    'dates of both kinds' => [[2 => '2016-13-03', 3 => '2019-01-31'], null, null],
    'a Pagume 6 of a common Ethiopian year' => [[2 => '2016-13-06'], null, null],
    'a date that names its calendar' => [[2 => '2016-13-03 E.C.'], null, null],
    'an Excel day number' => [[2 => '43654'], null, null],
    'not dates' => [[2 => 'soon', 3 => ''], null, null],
]);

it('tells the reader when hire dates are real only in the other calendar', function () {
    [$tenant] = Staging::organisation();

    $grid = [
        ['Name', 'Hire date'],
        ['Abebe Kebede', '2012-05-01'],
        ['Hanna Girma', '2016-13-03'],
    ];

    $read = test()->postJson(Staging::url($tenant, 'read'), ['grid' => $grid, 'calendar' => 'gregorian'])->assertOk()->json();

    expect($read['calendar_hints'])->toBe([[
        'column' => 'hire_date',
        'calendar' => 'ethiopian',
        'value' => '2016-13-03',
        'line' => 3,
        'from_header' => false,
    ]]);

    $asEthiopian = test()->postJson(Staging::url($tenant, 'read'), ['grid' => $grid, 'calendar' => 'ethiopian'])->assertOk()->json();
    expect($asEthiopian['calendar_hints'])->toBe([])
        ->and($asEthiopian['people'])->toBe(2);
});

it('says so when the column name set the calendar the dates disagree with', function () {
    [$tenant] = Staging::organisation();

    $read = test()->postJson(Staging::url($tenant, 'read'), ['grid' => [
        ['Name', 'Hire date (E.C.)'],
        ['Abebe Kebede', '2019-01-31'],
    ], 'calendar' => 'ethiopian'])->assertOk()->json();

    expect($read['calendar_hints'])->toHaveCount(1)
        ->and($read['calendar_hints'][0])->toMatchArray(['calendar' => 'gregorian', 'from_header' => true, 'line' => 2]);
});

it('gives the hint on the line the reader counts in a list with no column names', function () {
    [$tenant] = Staging::organisation();

    // No header: the columns are DEFAULT_ORDER, and line 1 is a person.
    $read = test()->postJson(Staging::url($tenant, 'read'), ['grid' => [
        array_replace(array_fill(0, count(EmployeeImportRow::DEFAULT_ORDER), ''), [
            array_search('name', EmployeeImportRow::DEFAULT_ORDER, true) => 'Abebe Kebede',
            array_search('hire_date', EmployeeImportRow::DEFAULT_ORDER, true) => '2016-13-03',
        ]),
    ], 'calendar' => 'gregorian'])->assertOk()->json();

    expect($read['header'])->toBeFalse()
        ->and($read['calendar_hints'])->toHaveCount(1)
        ->and($read['calendar_hints'][0])->toMatchArray(['column' => 'hire_date', 'line' => 1, 'calendar' => 'ethiopian']);
});

it('reads a column name in either Amharic spelling of a sound', function (string $header, string $column) {
    expect(EmployeeImportRow::header($header))->toBe($column);
})->with([
    'ሠ for ሰ' => ['የሠራተኛ ቁጥር', 'employee_code'],
    'ሥ for ስ' => ['ሙሉ ሥም', 'name'],
    'ሥ in a phone' => ['ሥልክ', 'phone'],
    'the spelling as listed' => ['የሰራተኛ ቁጥር', 'employee_code'],
    'ዐ for አ in a calendar mark' => ['Hire date (ዐ.ም)', 'hire_date'],
]);

it('keeps the calendar mark of a column name in either spelling', function () {
    expect(EmployeeImportRow::calendarMark('Hire date (ዓ.ም)'))->toBe('ethiopian')
        ->and(EmployeeImportRow::calendarMark('Hire date (ዐ.ም)'))->toBe('ethiopian')
        ->and(EmployeeImportRow::column('Hire date (ዐ.ም)'))->toBe(['hire_date', 'ethiopian']);
});

it('matches a saved column name in the other Amharic spelling, and keeps it as written', function () {
    $map = ImportMapping::mapOf([['header' => 'የሠራተኛ መለያ', 'column' => 'employee_code']]);

    expect($map)->toHaveKey(EmployeeImportRow::headerKey('የሰራተኛ መለያ'))
        ->and(EmployeeImportRow::headerKey('Emp. Name'))->toBe(EmployeeImportRow::headerKey('EMP NAME'));
});

it('leaves what a cell holds as written', function () {
    // Folding is for column names: a contract type in Amharic is not rewritten.
    $row = EmployeeImportRow::normalize(['name' => 'ሐና ሠረቀ', 'contract_type' => 'ሐሳብ']);

    expect($row['name'])->toBe('ሐና ሠረቀ')
        ->and($row['contract_type'])->toBe('ሐሳብ');
});

it('suggests the column of a known word written one or two letters out', function (string $header, ?string $column) {
    expect(EmployeeImportRow::suggest($header))->toBe($column);
})->with([
    ['Departmnet', 'department_code'],
    ['Telephon', 'phone'],
    ['Salery', 'salary'],
    ['Gendr', 'gender'],
    ['Notes', null],
    ['Allowance', null],
    ['Net pay', null],
    // A hint stem is not a word to be one letter from: "Rational" is not "national".
    ['Rational', null],
]);

it('warns when one name is on two lines, and adds both', function () {
    [$tenant] = Staging::organisation();

    $read = test()->postJson(Staging::url($tenant, 'read'), ['grid' => [
        ['Name', 'Employee number'],
        ['Abebe Kebede', 'EMP-1'],
        ['Hanna Girma', 'EMP-2'],
        ['abebe  kebede', 'EMP-3'],
    ]])->assertOk()->json();

    expect($read['people'])->toBe(3)
        ->and($read['problems'])->toBe([])
        ->and(array_column($read['warnings'], 'line'))->toBe([2, 4])
        ->and(array_unique(array_column($read['warnings'], 'code')))->toBe(['same_name_in_list'])
        ->and(array_unique(array_column($read['warnings'], 'column')))->toBe(['name']);

    // One message for the name, so the lines of one name group together.
    expect(array_unique(array_column($read['warnings'], 'message')))->toHaveCount(1)
        ->and($read['warnings'][0]['message'])->toContain('Abebe Kebede');
});

it('counts a name on a line with a problem, and warns on the staged one', function () {
    [$tenant] = Staging::organisation();

    $read = test()->postJson(Staging::url($tenant, 'read'), ['grid' => [
        ['Name', 'Department'],
        ['Abebe Kebede', 'No such department'],
        ['Abebe Kebede', ''],
    ]])->assertOk()->json();

    expect($read['people'])->toBe(1)
        ->and(array_column($read['problems'], 'line'))->toBe([2])
        ->and(array_column($read['warnings'], 'line'))->toBe([3])
        ->and($read['warnings'][0]['code'])->toBe('same_name_in_list');
});
