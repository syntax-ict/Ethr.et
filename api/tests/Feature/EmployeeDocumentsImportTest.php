<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ──────────────────────────── Documents ────────────────────────────

describe('employee documents', function () {
    it('lists documents for an employee', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employee->documents()->create([
            'tenant_id' => $tenant->id,
            'type' => 'contract',
            'title' => 'Employment Contract',
            'file_path' => 'tenants/test/documents/contract.pdf',
            'file_size' => 1024,
            'mime_type' => 'application/pdf',
        ]);

        $response = $this->getJson("/api/v1/employees/{$employee->public_id}/documents");

        $response->assertOk();
        expect($response->json())->toHaveCount(1);
        expect($response->json('0.title'))->toBe('Employment Contract');
        expect($response->json('0.type'))->toBe('contract');
    });

    it('filters documents by type', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employee->documents()->createMany([
            ['tenant_id' => $tenant->id, 'type' => 'contract', 'title' => 'Contract', 'file_path' => 'a.pdf', 'file_size' => 100],
            ['tenant_id' => $tenant->id, 'type' => 'academic', 'title' => 'Degree', 'file_path' => 'b.pdf', 'file_size' => 200],
        ]);

        $response = $this->getJson("/api/v1/employees/{$employee->public_id}/documents?filter[type]=academic");

        $response->assertOk();
        expect($response->json())->toHaveCount(1);
        expect($response->json('0.title'))->toBe('Degree');
    });

    it('uploads a document', function () {
        Storage::fake('minio');

        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $file = UploadedFile::fake()->create('contract.pdf', 500, 'application/pdf');

        $response = $this->postJson("/api/v1/employees/{$employee->public_id}/documents", [
            'file' => $file,
            'title' => 'Employment Contract 2024',
            'type' => 'contract',
        ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'Employment Contract 2024')
            ->assertJsonPath('type', 'contract');

        expect($response->json())->toHaveKey('public_id');

        $this->assertDatabaseHas('employee_documents', [
            'employee_id' => $employee->id,
            'title' => 'Employment Contract 2024',
            'type' => 'contract',
        ]);
    });

    it('validates document upload', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson("/api/v1/employees/{$employee->public_id}/documents", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file', 'title', 'type']);
    });

    it('rejects invalid document type', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        $this->postJson("/api/v1/employees/{$employee->public_id}/documents", [
            'file' => $file,
            'title' => 'Test',
            'type' => 'invalid_type',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });

    it('deletes a document', function () {
        Storage::fake('minio');

        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $doc = $employee->documents()->create([
            'tenant_id' => $tenant->id,
            'type' => 'other',
            'title' => 'To Delete',
            'file_path' => 'tenants/test/to_delete.pdf',
            'file_size' => 100,
        ]);

        $this->deleteJson("/api/v1/employees/{$employee->public_id}/documents/{$doc->public_id}")
            ->assertNoContent();

        $this->assertSoftDeleted('employee_documents', ['public_id' => $doc->public_id]);
    });

    it('shows expiry status', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $employee->documents()->create([
            'tenant_id' => $tenant->id,
            'type' => 'certificate',
            'title' => 'Expired Cert',
            'file_path' => 'test.pdf',
            'file_size' => 100,
            'expiry_date' => now()->subDay(),
        ]);

        $response = $this->getJson("/api/v1/employees/{$employee->public_id}/documents");

        $response->assertOk();
        expect($response->json('0.is_expired'))->toBeTrue();
    });

    it('logs document upload in audit log', function () {
        Storage::fake('minio');

        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $file = UploadedFile::fake()->create('audit_test.pdf', 100, 'application/pdf');

        $this->postJson("/api/v1/employees/{$employee->public_id}/documents", [
            'file' => $file,
            'title' => 'Audit Doc',
            'type' => 'other',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'employee.document.uploaded',
            'tenant_id' => $tenant->id,
        ]);
    });
});

// ──────────────────────────── CSV Import ────────────────────────────

describe('employee CSV import', function () {
    it('returns import template', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->postJson('/api/v1/employees/import/template');

        $response->assertOk()
            ->assertJsonStructure(['template', 'headers']);

        expect($response->json('headers'))->toContain('name', 'hire_date', 'employee_code');
    });

    it('previews valid CSV', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $csv = "name,email,phone,employee_code,gender,hire_date\n";
        $csv .= "Abebe Kebede,abebe@test.com,+251911111111,EMP-001,male,2024-01-15\n";
        $csv .= "Tigist Hailu,tigist@test.com,+251922222222,EMP-002,female,2024-02-01\n";

        $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

        $response = $this->postJson('/api/v1/employees/import/preview', [
            'file' => $file,
        ]);

        $response->assertOk();
        expect($response->json('rows'))->toHaveCount(2);
        expect($response->json('errors'))->toBeEmpty();
        expect($response->json('rows.0.name'))->toBe('Abebe Kebede');
    });

    it('previews CSV with validation errors', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $csv = "name,email,hire_date\n";
        $csv .= ",invalid-email,not-a-date\n";
        $csv .= "Valid Name,valid@test.com,2024-01-15\n";

        $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

        $response = $this->postJson('/api/v1/employees/import/preview', [
            'file' => $file,
        ]);

        $response->assertOk();
        expect($response->json('rows'))->toHaveCount(2);
        expect($response->json('errors'))->not->toBeEmpty();
    });

    it('rejects CSV missing required columns', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $csv = "email,phone\n";
        $csv .= "test@test.com,+251911111111\n";

        $file = UploadedFile::fake()->createWithContent('bad.csv', $csv);

        $response = $this->postJson('/api/v1/employees/import/preview', [
            'file' => $file,
        ]);

        $response->assertOk();
        expect($response->json('errors'))->not->toBeEmpty();
    });

    it('commits valid import data', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'code' => 'ENG']);
        $branch = Branch::factory()->create(['tenant_id' => $tenant->id, 'code' => 'HQ']);

        $response = $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'batch-001',
            'rows' => [
                [
                    'name' => 'Import Employee 1',
                    'email' => 'import1@test.com',
                    'hire_date' => '2024-01-15',
                    'employee_code' => 'IMP-001',
                    'department_code' => 'ENG',
                    'branch_code' => 'HQ',
                ],
                [
                    'name' => 'Import Employee 2',
                    'email' => 'import2@test.com',
                    'hire_date' => '2024-02-01',
                    'employee_code' => 'IMP-002',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('created', 2)
            ->assertJsonPath('skipped', 0);

        $this->assertDatabaseHas('employees', [
            'name' => 'Import Employee 1',
            'tenant_id' => $tenant->id,
            'department_id' => $dept->id,
            'branch_id' => $branch->id,
        ]);

        $this->assertDatabaseHas('employees', [
            'name' => 'Import Employee 2',
            'tenant_id' => $tenant->id,
        ]);
    });

    it('import is idempotent', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $payload = [
            'import_key' => 'batch-idem',
            'rows' => [
                ['name' => 'Idem Employee', 'hire_date' => '2024-01-15', 'employee_code' => 'IDEM-001'],
            ],
        ];

        $this->postJson('/api/v1/employees/import/commit', $payload)->assertCreated();

        $response = $this->postJson('/api/v1/employees/import/commit', $payload);

        $response->assertCreated()
            ->assertJsonPath('created', 0)
            ->assertJsonPath('skipped', 1);

        expect(Employee::where('name', 'Idem Employee')->count())->toBe(1);
    });

    it('logs import commit in audit log', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'batch-audit',
            'rows' => [
                ['name' => 'Audit Import', 'hire_date' => '2024-01-15'],
            ],
        ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'employee.import.committed',
            'tenant_id' => $tenant->id,
        ]);
    });
});

// ──────────────────────────── Bulk Update ────────────────────────────

describe('employee bulk update', function () {
    it('bulk updates department for selected employees', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);
        $emp1 = Employee::factory()->create(['tenant_id' => $tenant->id]);
        $emp2 = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson('/api/v1/employees/bulk-update', [
            'employee_ids' => [$emp1->public_id, $emp2->public_id],
            'department_id' => $dept->public_id,
        ]);

        $response->assertOk()
            ->assertJsonPath('updated', 2);

        $emp1->refresh();
        $emp2->refresh();
        expect($emp1->department_id)->toBe($dept->id);
        expect($emp2->department_id)->toBe($dept->id);
    });

    it('bulk updates branch', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $response = $this->postJson('/api/v1/employees/bulk-update', [
            'employee_ids' => [$emp->public_id],
            'branch_id' => $branch->public_id,
        ]);

        $response->assertOk()
            ->assertJsonPath('updated', 1);

        $emp->refresh();
        expect($emp->branch_id)->toBe($branch->id);
    });

    it('rejects bulk update with no fields', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $emp = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson('/api/v1/employees/bulk-update', [
            'employee_ids' => [$emp->public_id],
        ])->assertUnprocessable();
    });

    it('logs bulk update in audit log', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $dept = Department::factory()->create(['tenant_id' => $tenant->id]);
        $emp = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson('/api/v1/employees/bulk-update', [
            'employee_ids' => [$emp->public_id],
            'department_id' => $dept->public_id,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'employee.bulk_updated',
            'tenant_id' => $tenant->id,
        ]);
    });
});

// ──────────────────────────── Export ────────────────────────────

describe('employee export', function () {
    it('exports employees as CSV', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        Employee::factory()->count(3)->create(['tenant_id' => $tenant->id]);

        $response = $this->getJson('/api/v1/employees/export');

        $response->assertOk()
            ->assertJsonPath('count', 3);

        expect($response->json('csv'))->toContain('name,email,phone');
    });

    it('exports employees with status filter', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::CONFIRMED]);
        Employee::factory()->create(['tenant_id' => $tenant->id, 'status' => EmployeeStatus::PROBATION, 'probation_end_date' => now()->addMonths(3)]);

        $response = $this->getJson('/api/v1/employees/export?filter[status]=confirmed');

        $response->assertOk()
            ->assertJsonPath('count', 2);
    });

    it('denies export to employee role', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->getJson('/api/v1/employees/export')->assertForbidden();
    });
});

// ──────────────────────────── Authorization ────────────────────────────

describe('documents and import authorization', function () {
    it('denies import to supervisor', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $this->postJson('/api/v1/employees/import/template')->assertForbidden();
    });

    it('denies bulk update to supervisor', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::SUPERVISOR], $tenant);

        $emp = Employee::factory()->create(['tenant_id' => $tenant->id]);

        $this->postJson('/api/v1/employees/bulk-update', [
            'employee_ids' => [$emp->public_id],
            'status' => 'confirmed',
        ])->assertForbidden();
    });

    it('requires authentication for all endpoints', function () {
        $this->postJson('/api/v1/employees/import/template')->assertUnauthorized();
        $this->postJson('/api/v1/employees/import/preview')->assertUnauthorized();
        $this->postJson('/api/v1/employees/import/commit')->assertUnauthorized();
        $this->postJson('/api/v1/employees/bulk-update')->assertUnauthorized();
        $this->getJson('/api/v1/employees/export')->assertUnauthorized();
    });
});
