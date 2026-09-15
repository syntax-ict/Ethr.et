<?php

declare(strict_types=1);

use App\Models\Employee;
use App\Models\Tenant;
use App\Services\CurrentTenant;
use App\Services\Import\EmployeeImporter;

/**
 * Employee-import isolation, on the write path.
 *
 * `import status does not count another tenant rows` already covered the read
 * path (EmployeeImportController::status). The write path had no equivalent,
 * and did not deserve the benefit of the doubt: EmployeeImporter::commit()
 * de-duplicated on `import_key` through withoutGlobalScopes() with no tenant
 * predicate, so it read every tenant's rows.
 *
 * That mattered because both halves of the key are caller-supplied and
 * unconstrained — `import_key` is `required|string|max:50` with no uniqueness
 * and no tenant binding, `employee_code` is `nullable|string|max:30` — so
 * values like `batch-1` + `EMP001` collide by accident, not just by attack. A
 * collision skipped the row and reported it as `skipped`, which is a
 * cross-tenant existence oracle and silent data loss in one.
 *
 * What these tests prove: the de-duplication query is tenant-scoped, and
 * scoping it did not cost the two behaviours the bypass was there for —
 * same-tenant de-duplication, and matching soft-deleted rows.
 *
 * What they do not prove: that every other `withoutGlobalScopes()` call site is
 * scoped. There are 147 of them and nothing enforces the predicate; this covers
 * the one that was measurably wrong.
 */
function importTenant(string $subdomain): Tenant
{
    $tenant = Tenant::factory()->create(['subdomain' => $subdomain]);
    app(CurrentTenant::class)->set($tenant);

    return $tenant;
}

/** @return array<int, array<string, mixed>> */
function importRow(string $code = 'EMP001'): array
{
    return [[
        'name' => 'Almaz Tesfaye',
        'employee_code' => $code,
        'hire_date' => '2026-01-15',
    ]];
}

it('does not skip a row because another tenant used the same import key', function () {
    $alpha = importTenant('alpha');
    Employee::factory()->create([
        'tenant_id' => $alpha->id,
        'import_key' => 'batch-1_EMP001',
    ]);

    $beta = importTenant('beta');

    $result = app(EmployeeImporter::class)->commit('batch-1', importRow());

    // Before the fix this returned created: 0, skipped: 1 — beta's employee was
    // silently dropped because alpha happened to own the key.
    expect($result['created'])->toBe(1)
        ->and($result['skipped'])->toBe(0);

    expect(Employee::withoutGlobalScopes()
        ->where('tenant_id', $beta->id)
        ->where('import_key', 'batch-1_EMP001')
        ->exists())->toBeTrue();
});

it('leaks nothing through the skipped count when a key exists elsewhere', function () {
    $alpha = importTenant('alpha');
    Employee::factory()->create([
        'tenant_id' => $alpha->id,
        'import_key' => 'probe_EMP001',
    ]);

    importTenant('beta');

    $seen = app(EmployeeImporter::class)->commit('probe', importRow());
    $unseen = app(EmployeeImporter::class)->commit('never-used', importRow('EMP002'));

    // Two probes, one against a key another tenant owns and one against a key
    // nobody owns. Identical results, so the response answers nothing about
    // alpha.
    expect($seen['skipped'])->toBe($unseen['skipped'])
        ->and($seen['created'])->toBe($unseen['created']);
});

it('still de-duplicates within the same tenant', function () {
    $alpha = importTenant('alpha');
    Employee::factory()->create([
        'tenant_id' => $alpha->id,
        'import_key' => 'batch-1_EMP001',
    ]);

    $result = app(EmployeeImporter::class)->commit('batch-1', importRow());

    expect($result['created'])->toBe(0)
        ->and($result['skipped'])->toBe(1);
});

it('still matches a soft-deleted row, which is why the scope bypass stays', function () {
    $alpha = importTenant('alpha');
    $employee = Employee::factory()->create([
        'tenant_id' => $alpha->id,
        'import_key' => 'batch-1_EMP001',
    ]);
    $employee->delete();

    $result = app(EmployeeImporter::class)->commit('batch-1', importRow());

    // withoutGlobalScopes() drops the soft-delete scope as well as the tenant
    // scope. Dropping the bypass entirely — rather than adding the tenant
    // predicate — would have re-applied it and duplicated this row.
    expect($result['created'])->toBe(0)
        ->and($result['skipped'])->toBe(1);
});
