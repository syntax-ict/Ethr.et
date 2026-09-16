<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Services\CurrentTenant;

/**
 * Audit entries written from a queue worker.
 *
 * `AuditLog::record()` takes its tenant from `CurrentTenant` and nothing else.
 * `audit_log.tenant_id` is nullable, so when no tenant is resolved the insert
 * succeeds and the row is simply written with no owner — no error, no warning,
 * nothing to notice.
 *
 * The consequence is not a missing row; it is a row the tenant cannot see. The
 * audit view scopes by `tenant_id`, so a NULL never matches, while the
 * platform-admin view (`withoutGlobalScopes()`) shows it unattributed.
 *
 * `ProcessPayrollJob` records `payroll.processed` and `payroll.failed` from a
 * queued context. In an HCM product those are close to the most audit-relevant
 * events there are, and they were landing unattributed.
 *
 * `record()` already receives the auditable model, and that model carries the
 * tenant. The fix is to use it when the context has none, which makes all 206
 * call sites correct without touching any of them.
 */
function auditTenantFixture(): array
{
    $tenant = Tenant::factory()->create(['subdomain' => 'acme']);
    app(CurrentTenant::class)->set($tenant);

    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);

    return [$tenant, $run];
}

it('attributes an audit entry to the auditable owner when no tenant is resolved', function () {
    [$tenant, $run] = auditTenantFixture();

    // The state a queue worker is in.
    app(CurrentTenant::class)->forget();

    AuditLog::record('payroll.processed', $run, ['period' => '2026-09']);

    $entry = AuditLog::withoutGlobalScopes()
        ->where('action', 'payroll.processed')
        ->firstOrFail();

    expect($entry->tenant_id)->toBe($tenant->id);
});

it('leaves the entry visible to the tenant whose payroll it was', function () {
    [$tenant, $run] = auditTenantFixture();

    app(CurrentTenant::class)->forget();
    AuditLog::record('payroll.processed', $run, ['period' => '2026-09']);

    // Back in a request, reading their own trail. An unattributed row never
    // matches the tenant predicate, so the entry would simply not be there.
    app(CurrentTenant::class)->set($tenant);

    expect(AuditLog::where('action', 'payroll.processed')->exists())->toBeTrue();
});

it('still prefers the resolved tenant when there is one', function () {
    [$tenant, $run] = auditTenantFixture();

    AuditLog::record('payroll.processed', $run, ['period' => '2026-09']);

    $entry = AuditLog::withoutGlobalScopes()
        ->where('action', 'payroll.processed')
        ->firstOrFail();

    expect($entry->tenant_id)->toBe($tenant->id);
});

it('records a tenantless action with no owner rather than inventing one', function () {
    // Platform-level actions genuinely have no tenant, and `tenant_id` is
    // nullable for them. The fallback must not manufacture an owner when there
    // is no auditable to take one from.
    app(CurrentTenant::class)->forget();

    AuditLog::record('platform.maintenance_started');

    $entry = AuditLog::withoutGlobalScopes()
        ->where('action', 'platform.maintenance_started')
        ->firstOrFail();

    expect($entry->tenant_id)->toBeNull();
});
