<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Tenant;

// An employee sees and downloads their own documents. Until 2026-10-20 the
// only route was the HR screen, which needs employee.view (blueprint ESS8).

function myDocument(Tenant $tenant, Employee $employee, string $title, string $type = 'contract'): EmployeeDocument
{
    return EmployeeDocument::create([
        'tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'type' => $type, 'title' => $title,
        'file_path' => "employees/{$employee->public_id}/documents/{$title}.pdf",
        'file_size' => 100, 'mime_type' => 'application/pdf',
    ]);
}

function myDocsUrl(Tenant $tenant, string $path = ''): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/profile/documents{$path}";
}

it('lists the caller\'s own documents and nobody else\'s', function () {
    $tenant = createTenant();
    $me = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);
    myDocument($tenant, $me, 'My contract');
    myDocument($tenant, $me, 'My ID', 'id_copy');
    myDocument($tenant, $colleague, 'Their contract');
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id], $tenant);

    $titles = array_column(test()->getJson(myDocsUrl($tenant))->assertOk()->json('data'), 'title');

    expect($titles)->toEqualCanonicalizing(['My contract', 'My ID']);
});

it('gives a download link for an own document, and records it', function () {
    $tenant = createTenant();
    $me = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $doc = myDocument($tenant, $me, 'My contract');
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id], $tenant);

    test()->getJson(myDocsUrl($tenant, "/{$doc->public_id}"))
        ->assertOk()
        ->assertJsonStructure(['download_url', 'document' => ['public_id', 'title']]);

    $audit = AuditLog::where('action', 'employee.document.viewed')->sole();
    expect($audit->payload['self'] ?? null)->toBeTrue();
});

it('does not reach a colleague\'s document by its id', function () {
    $tenant = createTenant();
    $me = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $colleague = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $theirs = myDocument($tenant, $colleague, 'Their contract');
    actingAsUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $me->id], $tenant);

    test()->getJson(myDocsUrl($tenant, "/{$theirs->public_id}"))->assertNotFound();
    expect(AuditLog::where('action', 'employee.document.viewed')->exists())->toBeFalse();
});

it('answers 404 to an account with no employee record', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'employee_id' => null], $tenant);

    test()->getJson(myDocsUrl($tenant))->assertNotFound();
});
