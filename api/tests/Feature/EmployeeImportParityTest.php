<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
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
