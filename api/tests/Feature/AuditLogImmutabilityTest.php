<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Convention #5 — the audit log is append-only — enforced by the database, not
 * only by developer discipline.
 *
 * The migration that was supposed to guarantee this used a table-level REVOKE,
 * which on MySQL/MariaDB **cannot** subtract from the database-level grant that
 * `MARIADB_USER`/`MARIADB_DATABASE` creates. It failed with "There is no such
 * grant defined", so the protection had never actually been in force — and before
 * that it aborted the whole migration run on a least-privilege user. Triggers work
 * regardless of how privileges were granted, and these tests pin that.
 */
test('an audit log row can be written', function () {
    $tenant = createTenant();

    $id = DB::table('audit_log')->insertGetId([
        'tenant_id' => $tenant->id,
        'action' => 'test.written',
        'auditable_type' => 'App\Models\Employee',
        'auditable_id' => 1,
        'created_at' => now(),
    ]);

    expect($id)->toBeGreaterThan(0);
    expect(DB::table('audit_log')->where('action', 'test.written')->count())->toBe(1);
});

test('an audit log row cannot be updated', function () {
    $tenant = createTenant();

    DB::table('audit_log')->insert([
        'tenant_id' => $tenant->id,
        'action' => 'test.immutable',
        'auditable_type' => 'App\Models\Employee',
        'auditable_id' => 1,
        'created_at' => now(),
    ]);

    expect(fn () => DB::table('audit_log')
        ->where('action', 'test.immutable')
        ->update(['action' => 'test.tampered']))
        ->toThrow(QueryException::class);

    // And the original value survived the attempt.
    expect(DB::table('audit_log')->where('action', 'test.immutable')->count())->toBe(1);
    expect(DB::table('audit_log')->where('action', 'test.tampered')->count())->toBe(0);
});

test('an audit log row cannot be deleted', function () {
    $tenant = createTenant();

    DB::table('audit_log')->insert([
        'tenant_id' => $tenant->id,
        'action' => 'test.undeletable',
        'auditable_type' => 'App\Models\Employee',
        'auditable_id' => 1,
        'created_at' => now(),
    ]);

    expect(fn () => DB::table('audit_log')->where('action', 'test.undeletable')->delete())
        ->toThrow(QueryException::class);

    expect(DB::table('audit_log')->where('action', 'test.undeletable')->count())->toBe(1);
});

test('the AuditLog::record helper still writes through the guard', function () {
    $tenant = createTenant();
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);

    AuditLog::record('test.helper', $employee, ['field' => 'value']);

    expect(DB::table('audit_log')->where('action', 'test.helper')->count())->toBe(1);
});
