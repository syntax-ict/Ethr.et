<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeExternalIdentity;
use App\Services\Migration\StagedChanges;
use App\Support\EmployeeImportChoices;
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

test('every profile column is in the template, in one group, and described like the staged review describes it', function () {
    $byKey = array_column(EmployeeImportRow::columnMeta(), null, 'key');

    foreach (EmployeeImportRow::PROFILE as $column) {
        $groups = array_keys(array_filter(EmployeeImportRow::GROUPS, fn (array $headers): bool => in_array($column, $headers, true)));

        expect(EmployeeImportRow::TEMPLATE)->toContain($column)
            ->and(EmployeeImportRow::MAPPABLE)->toContain($column)
            ->and(EmployeeImportRow::COLUMNS)->toContain($column)
            ->and($groups)->toHaveCount(1, $column)
            ->and(EmployeeImportRow::header($column))->toBe($column);
    }

    expect($byKey['grade'])->toMatchArray(['group' => 'job', 'kind' => 'list', 'pay' => false])
        ->and($byKey['cost_center'])->toMatchArray(['group' => 'job', 'kind' => 'list'])
        ->and($byKey['contract_type'])->toMatchArray(['group' => 'job', 'kind' => 'list'])
        ->and($byKey['probation_end_date'])->toMatchArray(['group' => 'job', 'kind' => 'date'])
        ->and($byKey['contract_end_date'])->toMatchArray(['group' => 'job', 'kind' => 'date'])
        ->and($byKey['bank_name'])->toMatchArray(['group' => 'pay', 'pay' => true, 'masked' => false])
        ->and($byKey['bank_account'])->toMatchArray(['group' => 'pay', 'kind' => 'id', 'pay' => true, 'masked' => true])
        ->and($byKey['emergency_phone'])->toMatchArray(['group' => 'contact', 'kind' => 'id'])
        ->and(EmployeeImportRow::DATES)->toContain('probation_end_date', 'contract_end_date')
        ->and(EmployeeImportRow::TEXT_COLUMNS)->toContain('bank_account');
});

test('the file import keeps the rules it had: none of the profile columns is among them', function () {
    expect(array_intersect(EmployeeImportRow::PROFILE, array_keys(EmployeeImportRow::rules())))->toBe([])
        ->and(array_intersect(EmployeeImportRow::PROFILE, array_keys(EmployeeImportRow::rules('rows.*.'))))->toBe([]);
});

test('the parts of a name are mappable and read by a list, and are not columns of the template or of a person', function () {
    $source = file_get_contents(base_path('../src/src/features/employees/employee-columns.ts'));

    foreach (EmployeeImportRow::NAME_PARTS as $part) {
        expect(EmployeeImportRow::MAPPABLE)->toContain($part)
            ->and(EmployeeImportRow::TEMPLATE)->not->toContain($part)
            ->and(EmployeeImportRow::COLUMNS)->not->toContain($part)
            ->and(EmployeeImportRow::PROFILE)->not->toContain($part)
            ->and(EmployeeImportRow::reads($part))->toBeTrue()
            ->and(EmployeeImportRow::column($part)[0])->toBe($part)
            ->and($source)->toMatch('/^\s+"?'.preg_quote($part, '/').'"?:/m', $part);
    }

    expect(EmployeeImportRow::MAPPABLE[0])->toBe('name')
        ->and(array_values(array_diff(EmployeeImportRow::MAPPABLE, EmployeeImportRow::COLUMNS)))->toBe(EmployeeImportRow::NAME_PARTS);
})->skip(fn () => ! is_dir(base_path('../src/src/features')), 'Needs the frontend source beside api/, as in a full checkout.');

test('the sign-in column is in the template after the phone, in the contact group, a yes or no list, and read by a staged list only', function () {
    $template = EmployeeImportRow::TEMPLATE;
    $byKey = array_column(EmployeeImportRow::columnMeta(), null, 'key');
    $groups = array_keys(array_filter(EmployeeImportRow::GROUPS, fn (array $headers): bool => in_array('sign_in', $headers, true)));

    expect($template[array_search('phone', $template, true) + 1])->toBe('sign_in')
        ->and(EmployeeImportRow::MAPPABLE)->toContain('sign_in')
        ->and(EmployeeImportRow::COLUMNS)->toContain('sign_in')
        ->and($groups)->toBe(['contact'])
        ->and($byKey['sign_in'])->toMatchArray(['group' => 'contact', 'kind' => 'list', 'required' => false, 'pay' => false, 'masked' => false])
        ->and(EmployeeImportChoices::forTenant(null)['sign_in'])->toBe(['yes', 'no'])
        ->and(EmployeeImportRow::reads('sign_in'))->toBeTrue()
        // The file import keeps the rules it had: the staged rule is not among them.
        ->and(array_keys(EmployeeImportRow::rules()))->not->toContain('sign_in')
        ->and(array_keys(EmployeeImportRow::rules('rows.*.')))->not->toContain('rows.*.sign_in')
        ->and(EmployeeImportRow::stagedRules()['sign_in'])->toBe(['nullable', 'string', 'in:yes,no']);
});

test('a header reads as the sign-in column however it is written, and only a staged list reads the extra spellings', function (string $header) {
    expect(EmployeeImportRow::column($header)[0])->toBe('sign_in');
})->with(['Sign in', 'Sign-in', 'sign_in', 'SIGN IN', 'Signin', 'Invite', 'Invitation', 'ግብዣ']);

test('the file import reads its headers as it always did', function () {
    expect(EmployeeImportRow::header('Sign in'))->toBe('sign_in')
        ->and(EmployeeImportRow::header('Invite'))->toBe('invite')
        ->and(EmployeeImportRow::header('Invitation'))->toBe('invitation')
        ->and(EmployeeImportRow::header('Login'))->toBe('login');
});

test('a column no one has named may be suggested as the sign-in column, and no suggestion that existed changes', function () {
    expect(EmployeeImportRow::suggest('Login'))->not->toBe('sign_in')
        ->and(EmployeeImportRow::suggest('Last login'))->not->toBe('sign_in')
        ->and(EmployeeImportRow::suggest('የመግቢያ ቀን'))->not->toBe('sign_in')
        ->and(EmployeeImportRow::suggest('Invite'))->toBe('sign_in')
        ->and(EmployeeImportRow::suggest('ግብዣ'))->toBe('sign_in')
        ->and(EmployeeImportRow::suggest('Invite them'))->toBe('sign_in')
        ->and(EmployeeImportRow::suggest('Role'))->toBe('position_code')
        ->and(EmployeeImportRow::suggest('Account'))->toBe('bank_account')
        ->and(EmployeeImportRow::suggest('Account number'))->toBe('bank_account')
        ->and(EmployeeImportRow::suggest('Login email'))->toBe('email')
        ->and(EmployeeImportRow::suggest('Login ID'))->toBe('employee_code')
        ->and(EmployeeImportRow::suggest('Status'))->toBe('status');

    // A bare "Login" is neither read nor suggested: a legacy export's login is a username.
    expect(EmployeeImportRow::column('Login')[0])->toBe('login');
});

test('the sign-in column reads yes and no in three languages, and leaves the rest for the rule to refuse', function (string $written, string $read) {
    expect(EmployeeImportRow::normalize(['sign_in' => $written])['sign_in'])->toBe($read);
})->with([
    ['Yes', 'yes'], ['YES', 'yes'], ['y', 'yes'], ['TRUE', 'yes'], ['1', 'yes'], ['Invite', 'yes'],
    ['አዎ', 'yes'], ['አዎን', 'yes'],
    ['Eeyyee', 'yes'], ['eyyee', 'yes'],
    ['No', 'no'], ['n', 'no'], ['false', 'no'], ['0', 'no'],
    ['አይ', 'no'], ['አይደለም', 'no'],
    ['Lakki', 'no'], ['miti', 'no'],
    ['maybe', 'maybe'], ['2', '2'],
]);

test('a blank sign-in cell stays blank and a value that is not yes or no is a problem of its own column, in every language', function (string $locale, string $message) {
    app()->setLocale($locale);
    $rules = EmployeeImportRow::stagedRules();

    expect(EmployeeImportRow::problems(EmployeeImportRow::normalize(['name' => 'Abebe', 'sign_in' => '  ']), $rules))->toBe([])
        ->and(EmployeeImportRow::problems(EmployeeImportRow::normalize(['name' => 'Abebe', 'sign_in' => 'yes']), $rules))->toBe([])
        ->and(EmployeeImportRow::problems(EmployeeImportRow::normalize(['name' => 'Abebe', 'sign_in' => 'maybe']), $rules))
        ->toBe([['column' => 'sign_in', 'code' => 'in', 'message' => $message]]);
})->with([
    ['en', 'Sign in must be yes or no, or left empty.'],
    ['am', 'የመግቢያ ዓምድ አዎ ወይም አይ መሆን አለበት፤ ወይም ባዶ ሊተው ይችላል።'],
    ['om', 'Kutaa Seensaa keessatti Eeyyee yookaan Lakki qofa barreessi, yookaan duwwaa dhiisi.'],
]);

test('a staged row keeps the sign-in answer and nothing about a person reads it', function () {
    $row = EmployeeImportRow::staged(['name' => 'Abebe', 'sign_in' => 'no']);

    expect($row['sign_in'])->toBe('no')
        ->and(array_keys(StagedChanges::FIELDS))->not->toContain('sign_in')
        ->and(EmployeeImportRow::staged(['name' => 'Abebe']))->not->toHaveKey('sign_in');
});
