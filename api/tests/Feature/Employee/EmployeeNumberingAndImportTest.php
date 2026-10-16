<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Services\EmployeeNumbering;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/*
 * Employee numbers from the organisation's format, and the import read the
 * way people make files (2026-10-15). See EmployeeNumbering and
 * EmployeeImportRow.
 */

function previewCsv(string $csv): TestResponse
{
    return test()->postJson('/api/v1/employees/import/preview', [
        'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
    ]);
}

describe('employee numbers', function () {
    it('gives an employee added without a code the next number', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $first = $this->postJson('/api/v1/employees', ['name' => 'Abebe Kebede', 'hire_date' => '2026-01-15'])
            ->assertCreated()->json('employee_code');
        $second = $this->postJson('/api/v1/employees', ['name' => 'Tigist Hailu', 'hire_date' => '2026-01-15'])
            ->assertCreated()->json('employee_code');

        expect($first)->toBe('EMP-0001')->and($second)->toBe('EMP-0002');
    });

    it('keeps a code the form gives', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', ['name' => 'Abebe', 'hire_date' => '2026-01-15', 'employee_code' => 'X-77'])
            ->assertCreated()->assertJsonPath('employee_code', 'X-77');
    });

    it('follows the organisation format and counts past soft-deleted codes', function () {
        // Another tenant's higher number is not ours to count.
        $other = createTenant();
        Employee::factory()->create(['tenant_id' => $other->id, 'employee_code' => 'HC/500']);

        $tenant = createTenant();
        $tenant->update(['settings' => [...($tenant->settings ?? []), 'employee_number_format' => 'HC/{SEQ:3}']]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'HC/041']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'HC/007'])->delete();
        // Nor is a code in another shape.
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'OLD-900']);

        $this->getJson('/api/v1/employees/next-code')
            ->assertOk()
            ->assertJsonPath('employee_code', 'HC/042')
            ->assertJsonPath('format', 'HC/{SEQ:3}');
    });

    it('reads an underscore in the format literally', function () {
        $tenant = createTenant();
        $tenant->update(['settings' => [...($tenant->settings ?? []), 'employee_number_format' => 'HC_{SEQ:3}']]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'HC_041']);
        // A LIKE wildcard would let this through; the pattern must not count it.
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'HCX999']);

        $this->getJson('/api/v1/employees/next-code')->assertOk()->assertJsonPath('employee_code', 'HC_042');
    });

    it('falls back to the default for a format it cannot read', function () {
        $tenant = createTenant();
        $tenant->update(['settings' => [...($tenant->settings ?? []), 'employee_number_format' => 'no token']]);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->getJson('/api/v1/employees/next-code')->assertOk()->assertJsonPath('employee_code', 'EMP-0001');
    });

    it('refuses the next code to someone who cannot add employees', function () {
        actingAsUser(['role' => UserRole::EMPLOYEE], createTenant());

        $this->getJson('/api/v1/employees/next-code')->assertForbidden();
    });

    it('takes the next number when another writer took the first', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $tried = [];
        $code = app(EmployeeNumbering::class)->assign($tenant, function (string $code) use (&$tried): string {
            $tried[] = $code;
            if (count($tried) === 1) {
                throw new UniqueConstraintViolationException('sqlite', 'insert', [], new Exception('UNIQUE constraint failed: employees.tenant_id, employees.employee_code'));
            }

            return $code;
        });

        expect($tried)->toBe(['EMP-0001', 'EMP-0002'])->and($code)->toBe('EMP-0002');
    });

    it('lets an admin set the format, and refuses one without a single token', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->putJson('/api/v1/settings', ['settings' => ['employee_number_format' => 'ACME-{SEQ:5}']])->assertOk();
        expect($tenant->fresh()->settings['employee_number_format'])->toBe('ACME-{SEQ:5}');

        $this->putJson('/api/v1/settings', ['settings' => ['employee_number_format' => 'ACME-{SEQ:4}-{SEQ:2}']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.employee_number_format']);
    });
});

describe('employee import, read as people make files', function () {
    it('reads loose headers, day-first dates and a salary in birr', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = previewCsv(
            "Full Name,Employee Code,Start Date,Salary,Sex\n"
            ."Abebe Kebede,A-1,15/03/2024,\"12,500.50 ETB\",M\n"
        )->assertOk();

        expect($response->json('errors'))->toBeEmpty()
            ->and($response->json('rows.0'))->toMatchArray([
                'name' => 'Abebe Kebede',
                'employee_code' => 'A-1',
                'hire_date' => '2024-03-15',
                'salary' => '12500.50',
                'gender' => 'male',
            ]);

        $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'loose',
            'rows' => $response->json('rows'),
        ])->assertCreated()->assertJsonPath('created', 1);

        $employee = Employee::where('employee_code', 'A-1')->firstOrFail();
        expect($employee->salary_cents)->toBe(1250050)
            ->and($employee->hire_date->toDateString())->toBe('2024-03-15');
    });

    it('matches department, branch and position by name or code', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance', 'code' => 'FIN']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Head Office', 'code' => 'HQ']);
        $position = Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'Accountant', 'code' => 'ACC']);

        $rows = previewCsv(
            "name,hire_date,department,branch,position\n"
            ."Abebe,2024-01-15,finance,HQ,Accountant\n"
        )->assertOk()->assertJsonPath('errors', [])->json('rows');

        $this->postJson('/api/v1/employees/import/commit', ['import_key' => 'rel', 'rows' => $rows])
            ->assertCreated()->assertJsonPath('created', 1);

        $this->assertDatabaseHas('employees', [
            'name' => 'Abebe',
            'department_id' => $dept->id,
            'branch_id' => $branch->id,
            'position_id' => $position->id,
        ]);
    });

    it('reports a department the organisation does not have, instead of dropping it', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $errors = previewCsv("name,hire_date,department\nAbebe,2024-01-15,Marketing\n")->assertOk()->json('errors');
        expect($errors['2'][0])->toContain('Marketing');

        $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'missing-dept',
            'rows' => [['name' => 'Abebe', 'hire_date' => '2024-01-15', 'department_code' => 'Marketing']],
        ])->assertCreated()->assertJsonPath('created', 0);

        expect(Employee::where('name', 'Abebe')->exists())->toBeFalse();
    });

    it('asks for the code when two departments share a name', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales', 'code' => 'S1']);
        Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sales', 'code' => 'S2']);

        $errors = previewCsv("name,hire_date,department\nAbebe,2024-01-15,Sales\nTigist,2024-01-15,S2\n")->assertOk()->json('errors');

        expect($errors)->toHaveKey('2')->not->toHaveKey('3')
            ->and($errors['2'][0])->toBe(__('employee.import_relation_ambiguous', [
                'row' => 2, 'field' => __('employee.import_field_department_code'), 'value' => 'Sales',
            ]));
    });

    it('flags a code or email used twice in one file', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $errors = previewCsv(
            "name,hire_date,employee_code,email\n"
            ."Abebe,2024-01-15,E-1,a@test.com\n"
            ."Tigist,2024-01-15,e-1,A@test.com\n"
        )->assertOk()->json('errors');

        expect($errors)->not->toHaveKey('2')
            ->and($errors['3'])->toHaveCount(2);
    });

    it('numbers the rows that bring no code, in file order', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-0009']);

        $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'numbered',
            'rows' => [
                ['name' => 'One', 'hire_date' => '2024-01-15'],
                ['name' => 'Two', 'hire_date' => '2024-01-15', 'employee_code' => 'KEEP-1'],
                ['name' => 'Three', 'hire_date' => '2024-01-15'],
            ],
        ])->assertCreated()->assertJsonPath('created', 3);

        expect(Employee::where('name', 'One')->value('employee_code'))->toBe('EMP-0010')
            ->and(Employee::where('name', 'Two')->value('employee_code'))->toBe('KEEP-1')
            ->and(Employee::where('name', 'Three')->value('employee_code'))->toBe('EMP-0011');
    });

    it('refuses a row whose badge another employee holds, and keeps the rest of the batch', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'badge_number' => 'B-1', 'name' => 'Holder']);

        $response = $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'badge',
            'rows' => [
                ['name' => 'Clash', 'hire_date' => '2024-01-15', 'badge_number' => 'B-1'],
                ['name' => 'Fine', 'hire_date' => '2024-01-15'],
            ],
        ])->assertCreated()->assertJsonPath('created', 1);

        expect($response->json('errors.0.0'))->toBe(__('employee.import_already_taken', ['row' => 0]));
    });

    it('stores the columns the form has and the import used to drop', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'wide',
            'rows' => [[
                'name' => 'Abebe Kebede', 'name_am' => 'አበበ ከበደ', 'hire_date' => '2024-01-15',
                'tin' => '0012345678', 'badge_number' => 'B-9', 'date_of_birth' => '1990-05-01',
                'marital_status' => 'married',
            ]],
        ])->assertCreated()->assertJsonPath('created', 1);

        $employee = Employee::where('name', 'Abebe Kebede')->firstOrFail();
        expect($employee->name_am)->toBe('አበበ ከበደ')
            ->and($employee->tin)->toBe('0012345678')
            ->and($employee->badge_number)->toBe('B-9')
            ->and($employee->date_of_birth->toDateString())->toBe('1990-05-01')
            ->and($employee->marital_status)->toBe('married');
    });

    it('previews its own export without an error', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Finance']);
        Employee::factory()->count(3)->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id, 'phone' => '+251 91 123 4567']);

        $csv = $this->getJson('/api/v1/employees/export')->assertOk()->json('csv');

        $response = previewCsv($csv)->assertOk();
        expect($response->json('errors'))->toBeEmpty()
            ->and($response->json('rows'))->toHaveCount(3)
            ->and($response->json('rows.0.department_code'))->toBe('Finance')
            ->and($response->json('rows.0.phone'))->toBe('+251 91 123 4567');
    });

    it('exports with the filters and sort the list uses', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Bekele']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'name' => 'Almaz']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Elsewhere']);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Review', 'nationality_needs_review' => true]);

        $csv = $this->getJson('/api/v1/employees/export?filter[branch_id]='.$branch->public_id.'&sort=-name')
            ->assertOk()->assertJsonPath('count', 2)->json('csv');
        $lines = explode("\n", trim($csv));
        expect(str_starts_with($lines[1], 'Bekele,'))->toBeTrue()
            ->and(str_starts_with($lines[2], 'Almaz,'))->toBeTrue();

        $this->getJson('/api/v1/employees/export?filter[nationality_needs_review]=1')->assertOk()->assertJsonPath('count', 1);
    });

    it('serves the template outside the five-an-hour import limit', function () {
        actingAsUser(['role' => UserRole::HR_ADMIN], createTenant());

        foreach (range(1, 7) as $ignored) {
            $this->postJson('/api/v1/employees/import/template')->assertOk();
        }

        expect($this->postJson('/api/v1/employees/import/template')->json('headers'))
            ->toContain('department', 'salary', 'name_am', 'tin');
    });
});
