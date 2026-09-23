<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Employee;

/*
 * The HasAuditLog trait -- which nothing calls.
 *
 * Measured 2026-09-23 (audit/BASELINE.md 12g): the trait is at 0.0% coverage,
 * and the reason is not that the audit log is untested. It is that `audit()` has
 * ZERO callers -- none in app/, none in tests/. All 210 audit writes call
 * AuditLog::record() directly.
 *
 * So these tests raise the number on code nothing uses, which is worth naming
 * rather than quietly banking. What they are actually worth: `audit()` is a
 * public method on 56 models, so it is API whether or not this codebase calls
 * it, and if someone reaches for the obvious-looking helper it should behave.
 * Before now, nothing said it did.
 *
 * The alternative is deleting the trait. That is an owner decision and is left
 * open; these tests do not argue for keeping it.
 */

test('audit() writes a row against the model it was called on', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $log = $employee->audit('employee.probed', ['field' => 'salary_cents']);

    expect($log)->toBeInstanceOf(AuditLog::class)
        ->and($log->action)->toBe('employee.probed')
        ->and($log->auditable_type)->toBe(Employee::class)
        // toEqual, not toBe: auditable_id and tenant_id are plain integer
        // columns with no cast, so whether they come back as int or string is
        // the driver's business. This suite runs on SQLite and on MariaDB.
        ->and($log->auditable_id)->toEqual($employee->id)
        ->and($log->payload)->toBe(['field' => 'salary_cents'])
        ->and($log->tenant_id)->toEqual($tenant->id);
});

test('audit() persists, rather than returning an unsaved model', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $employee->audit('employee.persisted');

    expect(AuditLog::where('action', 'employee.persisted')->count())->toBe(1);
});

test('an empty payload is stored as null, not as an empty array', function () {
    // record() does `$payload ?: null`. Asserted because the column is nullable
    // and a reader cannot tell from the signature's `array $payload = []`
    // which of the two lands in the row.
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    expect($employee->audit('employee.no-payload')->payload)->toBeNull();
});

test('the trait is on the models whose changes the product claims to audit', function () {
    // Not the full list of 56 -- these are the ones whose audit trail the
    // product's own documentation treats as load-bearing.
    foreach ([Employee::class, App\Models\PayrollRun::class, App\Models\User::class] as $model) {
        expect(class_uses_recursive($model))
            ->toContain(App\Traits\HasAuditLog::class, "{$model} must use HasAuditLog");
    }
});
