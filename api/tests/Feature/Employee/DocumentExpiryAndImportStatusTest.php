<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\EmployeeDocument;

/**
 * PHASE_02 S12 gaps: "Expiring documents query: documents expiring within 30 days
 * (for notification/dashboard)" and "GET /employees/import/status/{key}".
 *
 * The expiry *column* and an `is_expired` flag already existed; what was missing
 * was any way to ask "what is about to lapse" before it already had.
 */
function docFixture(): array
{
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Abebe Kebede']);
    $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);

    return [$tenant, $employee, $hr];
}

function makeDocument(int $tenantId, int $employeeId, ?string $expiry): EmployeeDocument
{
    return EmployeeDocument::create([
        'tenant_id' => $tenantId,
        'employee_id' => $employeeId,
        'type' => 'contract',
        'title' => 'Contract',
        'file_path' => 'documents/test.pdf',
        'file_size' => 1024,
        'mime_type' => 'application/pdf',
        'expiry_date' => $expiry,
    ]);
}

// ── Expiring documents ──

test('a document expiring inside the window is listed', function () {
    [$tenant, $employee, $hr] = docFixture();
    makeDocument($tenant->id, $employee->id, now()->addDays(10)->toDateString());

    test()->actingAs($hr);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/documents/expiring");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.expires_soon'))->toBeTrue();
    expect($response->json('data.0.is_expired'))->toBeFalse();
    // The reader needs to know whose document this is.
    expect($response->json('data.0.employee_name'))->toBe('Abebe Kebede');
});

test('a document expiring beyond the window is not listed', function () {
    [$tenant, $employee, $hr] = docFixture();
    makeDocument($tenant->id, $employee->id, now()->addDays(90)->toDateString());

    test()->actingAs($hr);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/documents/expiring");

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

test('an already-expired document is still listed as the more urgent case', function () {
    [$tenant, $employee, $hr] = docFixture();
    makeDocument($tenant->id, $employee->id, now()->subDays(5)->toDateString());

    test()->actingAs($hr);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/documents/expiring");

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.is_expired'))->toBeTrue();
    // Mutually exclusive, so the UI never has to pick between two true flags.
    expect($response->json('data.0.expires_soon'))->toBeFalse();
});

test('a document with no expiry date is never listed', function () {
    [$tenant, $employee, $hr] = docFixture();
    makeDocument($tenant->id, $employee->id, null);

    test()->actingAs($hr);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/documents/expiring")
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('the window is configurable', function () {
    [$tenant, $employee, $hr] = docFixture();
    makeDocument($tenant->id, $employee->id, now()->addDays(60)->toDateString());

    test()->actingAs($hr);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/documents/expiring?days=90")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

test('expiring documents never cross tenants', function () {
    [$tenantA, $employeeA] = docFixture();
    makeDocument($tenantA->id, $employeeA->id, now()->addDays(5)->toDateString());

    [$tenantB, , $hrB] = docFixture();

    test()->actingAs($hrB);

    test()->getJson("http://{$tenantB->subdomain}.ethr.test/api/v1/employees/documents/expiring")
        ->assertOk()
        ->assertJsonPath('data', []);
});

// ── Import status ──

test('a committed import can be recovered by key', function () {
    [$tenant, , $hr] = docFixture();

    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'import_key' => 'batch-123_EMP001',
    ]);
    Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'import_key' => 'batch-123_EMP002',
    ]);

    test()->actingAs($hr);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/import/status/batch-123");

    $response->assertOk();
    expect($response->json('status'))->toBe('completed');
    expect($response->json('imported'))->toBe(2);
});

test('an unknown import key reports not found', function () {
    [$tenant, , $hr] = docFixture();

    test()->actingAs($hr);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/import/status/never-ran")
        ->assertStatus(404)
        ->assertJsonPath('status', 'not_found');
});

test('import status does not count another tenant rows', function () {
    [$tenantA] = docFixture();
    Employee::factory()->create([
        'tenant_id' => $tenantA->id,
        'import_key' => 'shared-key_EMP001',
    ]);

    [$tenantB, , $hrB] = docFixture();

    test()->actingAs($hrB);

    test()->getJson("http://{$tenantB->subdomain}.ethr.test/api/v1/employees/import/status/shared-key")
        ->assertStatus(404);
});

test('a similarly-prefixed key is not miscounted', function () {
    [$tenant, , $hr] = docFixture();

    Employee::factory()->create(['tenant_id' => $tenant->id, 'import_key' => 'batch-1_EMP001']);
    Employee::factory()->create(['tenant_id' => $tenant->id, 'import_key' => 'batch-12_EMP001']);

    test()->actingAs($hr);

    // "batch-1" must not swallow "batch-12" — the underscore separator is what
    // makes the prefix match unambiguous.
    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees/import/status/batch-1");

    $response->assertOk();
    expect($response->json('imported'))->toBe(1);
});
