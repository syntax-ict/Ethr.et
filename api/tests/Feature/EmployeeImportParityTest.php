<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Services\Migration\StagedChanges;
use App\Support\EmployeeImportGrid;
use App\Support\EmployeeImportRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/*
 * Audit N11: the employee import's preview and commit validated differently.
 * The page sends only the rows the preview passed, so a row the preview passed
 * and the commit refused failed the whole batch with a 422. Both now read
 * App\Support\EmployeeImportRow.
 */

function previewEmployeeCsv(string $csv): TestResponse
{
    return test()->postJson('/api/v1/employees/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
    ]);
}

/**
 * The rows the preview passed, keyed exactly as the page sends them back.
 *
 * @return list<array<string, mixed>>
 */
function previewPassedRows(TestResponse $preview): array
{
    $errors = $preview->json('errors') ?? [];
    $passed = [];
    foreach ($preview->json('rows') as $i => $row) {
        if (! array_key_exists((string) ($i + 2), $errors) && ! array_key_exists($i + 2, $errors)) {
            $passed[] = $row;
        }
    }

    return $passed;
}

test('every row the preview passes is a row the commit accepts', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $csv = "name,phone,employee_code,hire_date,salary_cents\n"
        ."Abebe Kebede,+251911111111,EMP-001,2024-01-15,1500000\n"
        // Each of these passed the old preview and failed the commit request.
        ."Grouped Salary,+251911111112,EMP-002,2024-01-15,\"1,500\"\n"
        ."Long Phone,+2519111111111111111111,EMP-003,2024-01-15,100\n"
        .'Long Code,+251911111114,'.str_repeat('C', 31).",2024-01-15,100\n";

    $preview = previewEmployeeCsv($csv)->assertOk();

    expect($preview->json('errors'))->toHaveKeys(['3', '4', '5']);

    $rows = previewPassedRows($preview);
    expect($rows)->toHaveCount(1);

    test()->postJson('/api/v1/employees/import/commit', [
        'import_key' => 'parity-1',
        'rows' => $rows,
    ])->assertCreated()->assertJsonPath('created', 1);
});

test('an empty optional cell previews as null, the value the commit receives', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $preview = previewEmployeeCsv("name,email,gender,hire_date\n  Hana Tesfaye  ,,,2024-03-01\n")->assertOk();

    expect($preview->json('errors'))->toBeEmpty()
        ->and($preview->json('rows.0.name'))->toBe('Hana Tesfaye')
        ->and($preview->json('rows.0.email'))->toBeNull()
        ->and($preview->json('rows.0.gender'))->toBeNull();
});

test('a national id in the file reaches the employee record', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    test()->postJson('/api/v1/employees/import/commit', [
        'import_key' => 'nid-1',
        'rows' => [[
            'name' => 'Selam Bekele',
            'hire_date' => '2024-01-15',
            'employee_code' => 'NID-001',
            'national_id' => 'FAN-1234-5678',
        ]],
    ])->assertCreated()->assertJsonPath('created', 1);

    expect(Employee::where('employee_code', 'NID-001')->first()->national_id)->toBe('FAN-1234-5678');
});

test('the template headers list every column the template row carries', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $response = test()->postJson('/api/v1/employees/import/template')->assertOk();

    expect($response->json('headers'))->toContain('national_id')
        ->and(trim((string) $response->json('template')))->toBe(implode(',', $response->json('headers')));
});

test('a file saved by Excel — BOM and CRLF — previews', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $csv = "\xEF\xBB\xBFname,hire_date\r\nAbebe Kebede,2024-01-15\r\nTigist Hailu,2024-02-01\r\n";

    $preview = previewEmployeeCsv($csv)->assertOk();

    expect($preview->json('headers'))->toBe(['name', 'hire_date'])
        ->and($preview->json('errors'))->toBeEmpty()
        ->and($preview->json('rows'))->toHaveCount(2);
});

test('a CR-only file previews every row', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $preview = previewEmployeeCsv("name,hire_date\rAbebe Kebede,2024-01-15\rTigist Hailu,2024-02-01\r")->assertOk();

    expect($preview->json('errors'))->toBeEmpty()
        ->and($preview->json('rows'))->toHaveCount(2);
});

/*
 * One parser (2026-11-11). A pasted list had its own reading of a column name
 * and a date, in the browser. A list and a file now go through the same
 * definition, so the same line is the same person either way.
 */
test('a pasted list and a file read the same line the same way', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $header = ['Full name', 'Staff ID', 'Start date', 'Mobile', 'Badge', 'Salary (ETB)', 'Sex'];
    $line = ['Abebe Kebede', 'EMP-001', '15/03/2024', '0911223344', 'B-7', '"12,500 ETB"', 'M'];

    $file = previewEmployeeCsv(implode(',', $header)."\n".implode(',', $line)."\n")->assertOk();
    expect($file->json('errors'))->toBeEmpty();

    $pasted = (new EmployeeImportGrid($tenant->id))->read([$header, array_map(fn (string $cell): string => trim($cell, '"'), $line)]);
    expect($pasted->problems)->toBeEmpty();

    $fromFile = $file->json('rows.0');
    $fromList = $pasted->rows[0];

    foreach (['name', 'employee_code', 'hire_date', 'phone', 'badge_number', 'gender'] as $field) {
        expect($fromList[$field])->toBe($fromFile[$field], $field);
    }
    expect($fromList['hire_date'])->toBe('2024-03-15')
        ->and($fromList['salary_cents'])->toBe(EmployeeImportRow::salaryCents($fromFile))
        ->and($fromList['salary_cents'])->toBe(1250000);
});

test('the file import brings a person in with the status and the other system\'s id their row names', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $preview = previewEmployeeCsv("name,hire_date,status,legacy id\nSelam Bekele,2020-01-15,Active,HR-7\n")->assertOk();
    expect($preview->json('errors'))->toBeEmpty();

    test()->postJson('/api/v1/employees/import/commit', [
        'import_key' => 'ext-1',
        'rows' => previewPassedRows($preview),
    ])->assertCreated()->assertJsonPath('created', 1);

    $selam = Employee::where('tenant_id', $tenant->id)->firstOrFail();
    expect($selam->status)->toBe(EmployeeStatus::CONFIRMED)
        ->and($selam->confirmation_date?->toDateString())->toBe(now()->toDateString())
        ->and(EmployeeExternalIdentity::where('employee_id', $selam->id)->value('identifier_value'))->toBe('HR-7');

    // The same id under another name and number is the same person.
    test()->postJson('/api/v1/employees/import/commit', [
        'import_key' => 'ext-2',
        'rows' => [['name' => 'Selam B. Haile', 'hire_date' => '2020-01-15', 'employee_code' => 'NEW-9', 'external_id' => 'HR-7']],
    ])->assertCreated()->assertJsonPath('created', 0)->assertJsonPath('matched', 1);

    expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(1);
});

test('every template header is in exactly one group', function () {
    $seen = [];
    foreach (EmployeeImportRow::GROUPS as $headers) {
        foreach ($headers as $header) {
            $seen[$header] = ($seen[$header] ?? 0) + 1;
        }
    }

    expect(array_keys($seen))->toEqualCanonicalizing(EmployeeImportRow::TEMPLATE)
        ->and(array_unique(array_values($seen)))->toBe([1]);
});

test('the template serves one entry per column, in the template order', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $template = test()->postJson('/api/v1/employees/import/template')->assertOk()->json();

    $keys = array_column($template['columns'], 'key');
    $required = array_column(array_filter($template['columns'], fn (array $c): bool => $c['required']), 'key');

    expect($keys)->toBe(EmployeeImportRow::TEMPLATE)
        ->and($keys)->toBe($template['headers'])
        ->and($template['required'])->toBe($required)
        ->and($required)->toBe(['name']);

    foreach ($template['columns'] as $column) {
        expect(EmployeeImportRow::header($column['key']))->toBe($column['column'])
            ->and(EmployeeImportRow::GROUPS[$column['group']])->toContain($column['key']);
    }
});

test('a column is masked or pay exactly as the staged review treats it', function () {
    foreach (EmployeeImportRow::columnMeta() as $column) {
        $staged = $column['column'] === 'salary' ? 'salary_cents' : $column['column'];

        expect($column['masked'])->toBe(in_array($staged, StagedChanges::MASKED, true), $column['key'])
            ->and($column['pay'])->toBe(in_array($staged, StagedChanges::PAY, true), $column['key']);
    }

    $byKey = array_column(EmployeeImportRow::columnMeta(), null, 'key');

    expect($byKey['salary'])->toMatchArray(['kind' => 'number', 'pay' => true, 'masked' => true])
        ->and($byKey['tin'])->toMatchArray(['kind' => 'id', 'pay' => true, 'masked' => true])
        ->and($byKey['national_id'])->toMatchArray(['pay' => false, 'masked' => true])
        ->and($byKey['hire_date']['kind'])->toBe('date')
        ->and($byKey['department']['kind'])->toBe('list')
        ->and($byKey['nationality']['kind'])->toBe('text');
});

test('every column the server reads, and every staged field, has words in the browser', function () {
    $file = base_path('../src/src/features/employees/employee-columns.ts');
    $source = file_get_contents($file);

    // The map is keyed by internal column. A template spelling ("department")
    // and a staged key ("salary_cents") are names for one of its entries.
    $named = [...EmployeeImportRow::MAPPABLE, ...array_keys(StagedChanges::FIELDS), ...EmployeeImportRow::TEMPLATE];
    $entries = array_unique(array_map(
        fn (string $key): string => $key === 'salary_cents' ? 'salary' : EmployeeImportRow::header($key),
        $named,
    ));

    foreach ($entries as $key) {
        expect($source)->toMatch('/^\s+"?'.preg_quote($key, '/').'"?:/m', $key);
    }
})->skip(fn () => ! is_dir(base_path('../src/src/features')), 'Needs the frontend source beside api/, as in a full checkout.');
