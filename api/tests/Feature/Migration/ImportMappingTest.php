<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Models\ImportMapping;
use App\Models\MigrationStagingRow;
use App\Support\EmployeeImportRow;
use Tests\Support\StagingFixtures as Staging;

/*
 * Another HR or payroll system exports a list with its own column names
 * (One employee identity, PR 8). Someone says once which of them is the
 * name, the employee number and the rest; that is saved per source, the next
 * export is read without asking, and an id from that system finds the same
 * person every time, and only for that system.
 */

/** A payroll system's export: no column name ETHR reads by itself. */
const PAYROLL_EXPORT = [
    ['Emp Nm', 'Pers Ref', 'Cell', 'DOJ', 'Cost Centre Desc', 'Remarks'],
    ['Abebe Kebede', 'P-1042', '0911223344', '2019-07-08', 'Finance', 'on leave'],
    ['Hanna Girma', 'P-1043', '0922334455', '2020-01-15', 'Finance', ''],
];

/** What its column names are, as somebody matching them would say. */
const PAYROLL_COLUMNS = [
    ['header' => 'Emp Nm', 'column' => 'name'],
    ['header' => 'Pers Ref', 'column' => 'external_id'],
    ['header' => 'Cell', 'column' => 'phone'],
    ['header' => 'DOJ', 'column' => 'hire_date'],
    ['header' => 'Cost Centre Desc', 'column' => 'department_code'],
    ['header' => 'Remarks', 'column' => null],
];

it('describes column names it does not know, and guesses the obvious ones without reading them', function () {
    [$tenant] = Staging::organisation();

    $read = test()->postJson(Staging::url($tenant, 'read'), ['grid' => PAYROLL_EXPORT])->assertOk()->json();

    // Nothing was matched, so the first line was read as a person; the
    // answer says it looks like column names instead.
    expect($read['header'])->toBeFalse()
        ->and($read['header_likely'])->toBeTrue()
        ->and($read['needs_name'])->toBeFalse()
        ->and($read['headers'])->toBe([
            ['header' => 'Emp Nm', 'column' => null, 'suggested' => 'name'],
            ['header' => 'Pers Ref', 'column' => null, 'suggested' => null],
            ['header' => 'Cell', 'column' => null, 'suggested' => 'phone'],
            ['header' => 'DOJ', 'column' => null, 'suggested' => 'hire_date'],
            ['header' => 'Cost Centre Desc', 'column' => null, 'suggested' => null],
            ['header' => 'Remarks', 'column' => null, 'suggested' => null],
        ]);
});

it('offers a guess for a column once, and never for one already read', function () {
    [$tenant] = Staging::organisation();

    $read = test()->postJson(Staging::url($tenant, 'read'), ['grid' => [
        ['Name', 'Display name', 'Dept Name', 'Tel 1', 'Tel 2', 'Nationality'],
        ['Abebe Kebede', 'Abe', 'Finance', '0911223344', '', 'Ethiopian'],
    ]])->assertOk()->json();

    expect($read['headers'])->toBe([
        ['header' => 'Name', 'column' => 'name', 'suggested' => null],
        ['header' => 'Display name', 'column' => null, 'suggested' => null],
        ['header' => 'Dept Name', 'column' => null, 'suggested' => 'department_code'],
        ['header' => 'Tel 1', 'column' => null, 'suggested' => 'phone'],
        ['header' => 'Tel 2', 'column' => null, 'suggested' => null],
        ['header' => 'Nationality', 'column' => 'nationality', 'suggested' => null],
    ]);
});

it('reads the list as the reader matched its columns, the first line being names because they said so', function () {
    [$tenant] = Staging::organisation();
    $finance = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);

    $read = test()->postJson(Staging::url($tenant, 'read'), ['grid' => PAYROLL_EXPORT, 'mapping' => PAYROLL_COLUMNS])
        ->assertOk()->json();

    expect($read)->toMatchArray([
        'people' => 2,
        'header' => true,
        'columns' => ['name', 'external_id', 'phone', 'hire_date', 'department_code'],
        // Left out on purpose is not "not recognised".
        'ignored' => [],
        'problems' => [],
        'needs_name' => false,
        'header_likely' => false,
    ]);

    $staged = test()->postJson(Staging::url($tenant, 'rows'), ['grid' => PAYROLL_EXPORT, 'mapping' => PAYROLL_COLUMNS])
        ->assertCreated()->json();
    $raw = MigrationStagingRow::where('public_id', $staged['rows'][0]['public_id'])->firstOrFail()->raw;

    expect($raw)->toMatchArray([
        'name' => 'Abebe Kebede',
        'external_id' => 'P-1042',
        'phone' => '0911223344',
        'hire_date' => '2019-07-08',
        'department' => 'Finance',
    ])->and($raw)->not->toHaveKey('Remarks');

    test()->postJson(Staging::url($tenant, "batches/{$staged['public_id']}/commit"))->assertOk();
    expect(Employee::where('tenant_id', $tenant->id)->where('name', 'Abebe Kebede')->value('department_id'))->toBe($finance->id);
});

it('still reads a column name the mapping does not mention as ETHR reads it', function () {
    [$tenant] = Staging::organisation();

    $read = test()->postJson(Staging::url($tenant, 'read'), [
        'grid' => [['Emp Nm', 'Email', 'Odd'], ['Abebe Kebede', 'abebe@acme.et', 'x']],
        'mapping' => [['header' => 'emp nm', 'column' => 'name']],
    ])->assertOk()->json();

    expect($read['people'])->toBe(1)
        ->and($read['columns'])->toBe(['name', 'email'])
        ->and($read['ignored'])->toBe(['Odd']);
});

it('says once that no column is the name, and stages nothing', function () {
    [$tenant] = Staging::organisation();
    $columns = [['header' => 'Emp Nm', 'column' => null], ['header' => 'Cell', 'column' => 'phone']];

    $read = test()->postJson(Staging::url($tenant, 'read'), ['grid' => PAYROLL_EXPORT, 'mapping' => $columns])
        ->assertOk()->json();

    expect($read['needs_name'])->toBeTrue()
        ->and($read['people'])->toBe(0)
        ->and($read['problems'])->toBe([]);

    test()->postJson(Staging::url($tenant, 'rows'), ['grid' => PAYROLL_EXPORT, 'mapping' => $columns])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['mapping']);
});

it('reads a matched date column in the calendar its own name says', function () {
    [$tenant] = Staging::organisation();

    $staged = test()->postJson(Staging::url($tenant, 'rows'), [
        'grid' => [['Emp Nm', 'DOJ (E.C.)'], ['Abebe Kebede', '2011-10-30']],
        'mapping' => [['header' => 'Emp Nm', 'column' => 'name'], ['header' => 'DOJ (E.C.)', 'column' => 'hire_date']],
    ])->assertCreated()->json();

    // 30 Sene 2011 is 7 July 2019.
    expect(MigrationStagingRow::where('public_id', $staged['rows'][0]['public_id'])->firstOrFail()->raw['hire_date'])
        ->toBe('2019-07-07');
});

it('refuses a column that is not one of the template\'s', function () {
    [$tenant] = Staging::organisation();

    test()->postJson(Staging::url($tenant, 'read'), [
        'grid' => PAYROLL_EXPORT,
        'mapping' => [['header' => 'Emp Nm', 'column' => 'salary_cents']],
    ])->assertStatus(422)->assertJsonValidationErrors(['mapping.0.column']);
});

it('saves a source and reads its next export without being told the columns again', function () {
    [$tenant] = Staging::organisation();
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);

    $source = test()->postJson(Staging::url($tenant, 'sources'), ['name' => 'Peachtree Payroll', 'mapping' => PAYROLL_COLUMNS])
        ->assertCreated()
        ->assertJsonPath('name', 'Peachtree Payroll')
        ->json();

    expect($source['columns'])->toBe(PAYROLL_COLUMNS)
        ->and(ImportMapping::where('public_id', $source['public_id'])->value('slug'))->toBe('peachtree-payroll');

    test()->getJson(Staging::url($tenant, 'sources'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.public_id', $source['public_id']);

    // Next month: the columns in another order, one renamed in case only.
    $read = test()->postJson(Staging::url($tenant, 'read'), [
        'source' => $source['public_id'],
        'grid' => [
            ['CELL', 'Emp Nm', 'Pers Ref'],
            ['0933445566', 'Sara Tesfaye', 'P-1050'],
        ],
    ])->assertOk()->json();

    expect($read)->toMatchArray([
        'people' => 1,
        'columns' => ['phone', 'name', 'external_id'],
        'source' => $source['public_id'],
        'suggested_source' => null,
    ]);
});

it('lets one request overrule a saved column without changing what is saved', function () {
    [$tenant] = Staging::organisation();
    $source = test()->postJson(Staging::url($tenant, 'sources'), ['name' => 'Peachtree', 'mapping' => PAYROLL_COLUMNS])->json();

    $read = test()->postJson(Staging::url($tenant, 'read'), [
        'source' => $source['public_id'],
        'grid' => PAYROLL_EXPORT,
        'mapping' => [['header' => 'Pers Ref', 'column' => 'employee_code']],
    ])->assertOk()->json();

    expect($read['columns'])->toContain('employee_code')->not->toContain('external_id')
        ->and(ImportMapping::where('public_id', $source['public_id'])->firstOrFail()->map()['pers_ref'])->toBe('external_id');
});

it('recognises a saved source by its column names and offers it', function () {
    [$tenant] = Staging::organisation();
    $source = test()->postJson(Staging::url($tenant, 'sources'), ['name' => 'Peachtree', 'mapping' => PAYROLL_COLUMNS])->json();
    test()->postJson(Staging::url($tenant, 'sources'), ['name' => 'Other', 'mapping' => [
        ['header' => 'Emp Nm', 'column' => 'name'],
        ['header' => 'Somewhere else', 'column' => 'phone'],
    ]])->assertCreated();

    test()->postJson(Staging::url($tenant, 'read'), ['grid' => PAYROLL_EXPORT])
        ->assertOk()
        ->assertJsonPath('suggested_source', $source['public_id']);

    // Next month's export: a column fewer, one more, another order.
    test()->postJson(Staging::url($tenant, 'read'), ['grid' => [
        ['Pers Ref', 'Emp Nm', 'Grade'],
        ['P-1050', 'Sara Tesfaye', 'G7'],
    ]])->assertOk()->assertJsonPath('suggested_source', $source['public_id']);

    // A source that does not know which column is the name is no help.
    test()->postJson(Staging::url($tenant, 'read'), ['grid' => [
        ['Pers Ref', 'Full Nm', 'Cell'],
        ['P-1050', 'Sara Tesfaye', '0911223344'],
    ]])->assertOk()->assertJsonPath('suggested_source', null);

    // A list ETHR reads whole needs no source.
    test()->postJson(Staging::url($tenant, 'read'), ['grid' => [['Name', 'Phone'], ['Abebe Kebede', '0911223344']]])
        ->assertOk()
        ->assertJsonPath('suggested_source', null);
});

it('keeps an id from the other system against its source, so the same id elsewhere is someone else', function () {
    [$tenant] = Staging::organisation();
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
    $source = test()->postJson(Staging::url($tenant, 'sources'), ['name' => 'Peachtree', 'mapping' => PAYROLL_COLUMNS])->json();
    $url = Staging::url($tenant, 'rows');

    $first = test()->postJson($url, ['source' => $source['public_id'], 'grid' => PAYROLL_EXPORT])->assertCreated()->json();
    expect($first['source_ref'])->toBe('peachtree');
    test()->postJson(Staging::url($tenant, "batches/{$first['public_id']}/commit"))->assertOk();

    $abebe = Employee::where('tenant_id', $tenant->id)->where('name', 'Abebe Kebede')->firstOrFail();
    expect(EmployeeExternalIdentity::where('employee_id', $abebe->id)->where('source_type', EmployeeImportRow::EXTERNAL_SOURCE)->first())
        ->source_ref->toBe('peachtree')
        ->identifier_value->toBe('P-1042');

    // The same source, later: nothing else about the person is the same.
    $again = test()->postJson($url, ['source' => $source['public_id'], 'grid' => [
        ['Emp Nm', 'Pers Ref'],
        ['Abebe K. Tadesse', 'P-1042'],
    ]])->assertCreated()->json();
    expect($again['rows'][0])->toMatchArray(['match_outcome' => 'matched', 'resolved_employee_public_id' => $abebe->public_id]);

    // The same id with no source named is not that system's id.
    $elsewhere = test()->postJson($url, ['grid' => [
        ['Name', 'Legacy ID'],
        ['Somebody Else', 'P-1042'],
    ]])->assertCreated()->json();
    expect($elsewhere['rows'][0]['match_outcome'])->toBe('new');
});

it('keeps an id against the source when the people arrive already keyed by field', function () {
    [$tenant] = Staging::organisation();
    $source = test()->postJson(Staging::url($tenant, 'sources'), ['name' => 'Peachtree', 'mapping' => PAYROLL_COLUMNS])->json();

    $staged = test()->postJson(Staging::url($tenant, 'rows'), [
        'source' => $source['public_id'],
        'rows' => [['name' => 'Abebe Kebede', 'external_id' => 'P-1042']],
    ])->assertCreated()->json();

    expect(MigrationStagingRow::where('public_id', $staged['rows'][0]['public_id'])->firstOrFail()->raw)
        ->toMatchArray(['source_type' => EmployeeImportRow::EXTERNAL_SOURCE, 'source_ref' => 'peachtree', 'identifier_value' => 'P-1042']);
});

it('keeps the ids linked when a source is renamed', function () {
    [$tenant] = Staging::organisation();
    $source = test()->postJson(Staging::url($tenant, 'sources'), ['name' => 'Peachtree', 'mapping' => PAYROLL_COLUMNS])->json();

    test()->putJson(Staging::url($tenant, "sources/{$source['public_id']}"), ['name' => 'Old payroll'])
        ->assertOk()
        ->assertJsonPath('name', 'Old payroll')
        ->assertJsonPath('columns', PAYROLL_COLUMNS);

    expect(ImportMapping::where('public_id', $source['public_id'])->value('slug'))->toBe('peachtree');

    test()->putJson(Staging::url($tenant, "sources/{$source['public_id']}"), ['mapping' => [
        ['header' => 'Emp Nm', 'column' => 'name'],
        ['header' => 'emp-nm', 'column' => 'name_am'],
        ['header' => 'Cell', 'column' => 'phone'],
    ]])->assertOk()->assertJsonPath('columns', [
        ['header' => 'Emp Nm', 'column' => 'name'],
        ['header' => 'Cell', 'column' => 'phone'],
    ]);
});

it('refuses a second source of the same name, and one with no name column', function () {
    [$tenant] = Staging::organisation();
    $url = Staging::url($tenant, 'sources');
    $first = test()->postJson($url, ['name' => 'Peachtree Payroll', 'mapping' => PAYROLL_COLUMNS])->assertCreated()->json();

    foreach (['Peachtree Payroll', 'peachtree payroll', 'Peachtree-Payroll!'] as $name) {
        test()->postJson($url, ['name' => $name, 'mapping' => PAYROLL_COLUMNS])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    test()->postJson($url, ['name' => 'Nameless', 'mapping' => [['header' => 'Cell', 'column' => 'phone']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['mapping']);

    // Saving a source under its own name again is not a second source.
    test()->putJson("{$url}/{$first['public_id']}", ['name' => 'Peachtree payroll'])->assertOk();

    expect(ImportMapping::where('tenant_id', $tenant->id)->count())->toBe(1);
});

it('gives a name with no Latin letters a slug of its own, the same every time', function () {
    expect(ImportMapping::slugFor('የድሮ ደመወዝ ሥርዓት'))
        ->toStartWith('source-')
        ->toBe(ImportMapping::slugFor(' የድሮ ደመወዝ ሥርዓት '))
        ->not->toBe(ImportMapping::slugFor('ሌላ ሥርዓት'));
});

it('forgets a source, and a source saved again under that name finds its people', function () {
    [$tenant] = Staging::organisation();
    $url = Staging::url($tenant, 'sources');
    $source = test()->postJson($url, ['name' => 'Peachtree', 'mapping' => PAYROLL_COLUMNS])->json();
    $first = test()->postJson(Staging::url($tenant, 'rows'), ['source' => $source['public_id'], 'grid' => [
        ['Emp Nm', 'Pers Ref'],
        ['Abebe Kebede', 'P-1042'],
    ]])->assertCreated()->json();
    test()->postJson(Staging::url($tenant, "batches/{$first['public_id']}/commit"))->assertOk();

    test()->deleteJson("{$url}/{$source['public_id']}")->assertNoContent();
    test()->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(1);

    $saved = test()->postJson($url, ['name' => 'Peachtree', 'mapping' => PAYROLL_COLUMNS])->assertCreated()->json();
    $again = test()->postJson(Staging::url($tenant, 'rows'), ['source' => $saved['public_id'], 'grid' => [
        ['Emp Nm', 'Pers Ref'],
        ['A. Kebede', 'P-1042'],
    ]])->assertCreated()->json();

    expect($again['rows'][0]['match_outcome'])->toBe('matched');
});

it('answers another organisation\'s source as it answers one that does not exist', function () {
    [$other] = Staging::organisation();
    $theirs = test()->postJson(Staging::url($other, 'sources'), ['name' => 'Peachtree', 'mapping' => PAYROLL_COLUMNS])->json();

    app()->forgetScopedInstances();
    [$tenant] = Staging::organisation();

    $unknown = test()->postJson(Staging::url($tenant, 'read'), ['source' => '01HZZZZZZZZZZZZZZZZZZZZZZZ', 'grid' => PAYROLL_EXPORT])
        ->assertStatus(422)->json('errors.source');
    $foreign = test()->postJson(Staging::url($tenant, 'read'), ['source' => $theirs['public_id'], 'grid' => PAYROLL_EXPORT])
        ->assertStatus(422)->json('errors.source');

    expect($foreign)->toBe($unknown);

    test()->postJson(Staging::url($tenant, 'rows'), ['source' => $theirs['public_id'], 'grid' => PAYROLL_EXPORT])
        ->assertStatus(422)->assertJsonValidationErrors(['source']);
    test()->putJson(Staging::url($tenant, "sources/{$theirs['public_id']}"), ['name' => 'Mine now'])->assertNotFound();
    test()->deleteJson(Staging::url($tenant, "sources/{$theirs['public_id']}"))->assertNotFound();
    test()->getJson(Staging::url($tenant, 'sources'))->assertOk()->assertJsonCount(0, 'data');
});

it('keeps saved sources to people who may add employees', function () {
    [$tenant] = Staging::organisation(UserRole::EMPLOYEE);

    test()->getJson(Staging::url($tenant, 'sources'))->assertForbidden();
    test()->postJson(Staging::url($tenant, 'sources'), ['name' => 'Peachtree', 'mapping' => PAYROLL_COLUMNS])->assertForbidden();
});

it('stops at the most sources an organisation keeps', function () {
    [$tenant] = Staging::organisation();
    foreach (range(1, ImportMapping::LIMIT) as $n) {
        ImportMapping::query()->create([
            'tenant_id' => $tenant->id,
            'name' => "Source {$n}",
            'slug' => "source-{$n}",
            'columns' => [['header' => 'Name', 'column' => 'name']],
        ]);
    }

    test()->postJson(Staging::url($tenant, 'sources'), ['name' => 'One more', 'mapping' => PAYROLL_COLUMNS])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});
