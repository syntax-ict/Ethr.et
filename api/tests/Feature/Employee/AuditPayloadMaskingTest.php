<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Support\EmployeeAuditMask;
use Illuminate\Support\Facades\DB;

/*
 * `employees.national_id` and `employees.tin` are encrypted at rest. The
 * `employee.updated` audit record was built from `$employee->only(...)`, which
 * decrypts, so every edit of either copied both values, old and new, into
 * `audit_log.payload` in clear text (BASELINE §25k). The audit log is
 * append-only and is read by people who may not see pay data.
 *
 * These read the stored column, not the model, so a cast cannot hide a leak.
 */

/** Every stored payload in the audit log, as the database holds it. */
function storedAuditPayloads(): string
{
    return DB::table('audit_log')->pluck('payload')->filter()->implode("\n");
}

function updatedAudit(Employee $employee): AuditLog
{
    return AuditLog::query()
        ->where('auditable_type', Employee::class)
        ->where('auditable_id', $employee->id)
        ->where('action', 'employee.updated')
        ->latest('id')
        ->firstOrFail();
}

it('stores neither the old nor the new national id or TIN when an edit changes them', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'national_id' => 'NID-OLD-771133',
        'tin' => '0011223344',
    ]);

    $this->putJson("/api/v1/employees/{$employee->public_id}", [
        'name' => 'Still Recorded',
        'national_id' => 'NID-NEW-992255',
        'tin' => '0099887766',
    ])->assertOk();

    // The edit itself landed.
    expect($employee->fresh()->national_id)->toBe('NID-NEW-992255')
        ->and($employee->fresh()->tin)->toBe('0099887766');

    expect(storedAuditPayloads())
        ->not->toContain('NID-OLD-771133')
        ->not->toContain('NID-NEW-992255')
        ->not->toContain('0011223344')
        ->not->toContain('0099887766');

    // That each field changed is still on record, and the rest is untouched.
    $payload = updatedAudit($employee)->payload;

    expect($payload['before']['national_id'])->toBe(EmployeeAuditMask::REDACTED)
        ->and($payload['after']['national_id'])->toBe(EmployeeAuditMask::CHANGED)
        ->and($payload['before']['tin'])->toBe(EmployeeAuditMask::REDACTED)
        ->and($payload['after']['tin'])->toBe(EmployeeAuditMask::CHANGED)
        ->and($payload['after']['name'])->toBe('Still Recorded');
});

it('records a first value, a cleared value and a resent value without storing any of them', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'national_id' => null, 'tin' => null]);
    $url = "/api/v1/employees/{$employee->public_id}";

    // Set for the first time: nothing before, a change after.
    $this->putJson($url, ['national_id' => 'NID-FIRST-5511', 'tin' => '0055110000'])->assertOk();
    $first = updatedAudit($employee)->payload;

    expect($first['before'])->toBe(['national_id' => null, 'tin' => null])
        ->and($first['after'])->toBe(['national_id' => EmployeeAuditMask::CHANGED, 'tin' => EmployeeAuditMask::CHANGED]);

    // The form resends what is stored: not a change, and it says so.
    $this->putJson($url, ['national_id' => 'NID-FIRST-5511', 'tin' => '0055110000'])->assertOk();
    $resent = updatedAudit($employee)->payload;

    expect($resent['before'])->toBe(['national_id' => EmployeeAuditMask::REDACTED, 'tin' => EmployeeAuditMask::REDACTED])
        ->and($resent['after'])->toBe(['national_id' => EmployeeAuditMask::UNCHANGED, 'tin' => EmployeeAuditMask::UNCHANGED]);

    // Cleared: a value before, none after.
    $this->putJson($url, ['national_id' => null, 'tin' => null])->assertOk();
    $cleared = updatedAudit($employee)->payload;

    expect($cleared['before'])->toBe(['national_id' => EmployeeAuditMask::REDACTED, 'tin' => EmployeeAuditMask::REDACTED])
        ->and($cleared['after'])->toBe(['national_id' => null, 'tin' => null]);

    expect(storedAuditPayloads())
        ->not->toContain('NID-FIRST-5511')
        ->not->toContain('0055110000');
});

it('keeps both values out of the audit log on create, import and transition as well', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $created = $this->postJson('/api/v1/employees', [
        'name' => 'Added By Form',
        'hire_date' => '2026-01-15',
        'national_id' => 'NID-FORM-440011',
        'tin' => '0044001100',
    ])->assertCreated()->json('public_id');

    $this->postJson('/api/v1/employees/import/commit', [
        'import_key' => 'masking',
        'rows' => [[
            'name' => 'Added By File',
            'hire_date' => '2026-01-15',
            'national_id' => 'NID-FILE-660022',
            'tin' => '0066002200',
        ]],
    ])->assertCreated()->assertJsonPath('created', 1);

    $this->postJson("/api/v1/employees/{$created}/transition", [
        'to_status' => 'probation',
        'effective_date' => '2026-01-15',
        'reason' => 'Started',
    ])->assertSuccessful();

    // Each of the three did write an audit record, so the scan is not empty.
    expect(AuditLog::query()->whereIn('action', [
        'employee.created', 'employee.import.committed', 'employee.transitioned',
    ])->pluck('action')->unique()->count())->toBe(3);

    expect(storedAuditPayloads())
        ->not->toContain('NID-FORM-440011')
        ->not->toContain('0044001100')
        ->not->toContain('NID-FILE-660022')
        ->not->toContain('0066002200');
});
