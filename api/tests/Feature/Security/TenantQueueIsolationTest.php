<?php

declare(strict_types=1);

use App\Jobs\ScanAttendanceAnomaliesJob;
use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use App\Services\Attendance\AttendanceIntelligence;
use App\Services\CurrentTenant;

/**
 * Queued jobs and tenant context.
 *
 * A job runs with no HTTP request behind it, so `ResolveTenant` never fires and
 * `CurrentTenant` starts unresolved. Two patterns are used across the 14 jobs in
 * this codebase, and both are correct only if they are actually followed:
 *
 *   1. inject CurrentTenant and set() it from a serialised tenant id;
 *   2. query with withoutGlobalScopes() and an explicit where('tenant_id').
 *
 * The audit marked this PASS by reading the jobs. That is the same evidence
 * standard that passed the `device.*` channel (a permission that did not exist)
 * and MinIO (a prefix a `..` could climb out of), so the properties are asserted
 * here instead.
 *
 * The risk pattern 2 carries is worth naming: it disables the safety net, so a
 * query that forgets its where-clause reads every tenant and nothing complains.
 */
function tenantWithAttendance(string $subdomain): array
{
    $tenant = Tenant::factory()->create(['subdomain' => $subdomain]);
    app(CurrentTenant::class)->set($tenant);

    $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
    $employee = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'branch_id' => $branch->id,
    ]);
    $shift = Shift::factory()->create(['tenant_id' => $tenant->id]);

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'shift_id' => $shift->id,
        'date' => '2026-08-10',
    ]);

    return [$tenant, $record];
}

it('runs with the tenant it was dispatched for, not whatever was last resolved', function () {
    [$habru] = tenantWithAttendance('habru');
    [$woldia] = tenantWithAttendance('woldia');

    $currentTenant = app(CurrentTenant::class);

    // Leave a *different* tenant resolved than the job is for. A worker process
    // handles many tenants in sequence, so the previous job's context is exactly
    // what would leak if a job trusted ambient state instead of its own payload.
    $currentTenant->set($woldia);

    (new ScanAttendanceAnomaliesJob($habru->id, '2026-08-10'))
        ->handle(app(AttendanceIntelligence::class), $currentTenant);

    expect($currentTenant->id())->toBe($habru->id);
});

it('starts from no tenant at all and still scopes itself', function () {
    [$habru] = tenantWithAttendance('habru');
    $currentTenant = app(CurrentTenant::class);

    // The real worker case: nothing resolved, because no request preceded it.
    $currentTenant->forget();
    expect($currentTenant->resolved())->toBeFalse();

    (new ScanAttendanceAnomaliesJob($habru->id, '2026-08-10'))
        ->handle(app(AttendanceIntelligence::class), $currentTenant);

    expect($currentTenant->id())->toBe($habru->id);
});

it('does nothing for a tenant that no longer exists', function () {
    $currentTenant = app(CurrentTenant::class);
    $currentTenant->forget();

    // A deleted tenant must not leave the job running against whatever context
    // happened to be lying around.
    (new ScanAttendanceAnomaliesJob(999999, '2026-08-10'))
        ->handle(app(AttendanceIntelligence::class), $currentTenant);

    expect($currentTenant->resolved())->toBeFalse();
});

it('scopes its query to its own tenant once context is set', function () {
    [$habru] = tenantWithAttendance('habru');
    [$woldia] = tenantWithAttendance('woldia');

    $currentTenant = app(CurrentTenant::class);
    $currentTenant->set($habru);

    // With habru resolved, the global scope must hide woldia's rows entirely —
    // this is the guarantee every job of pattern 1 leans on.
    $visible = AttendanceRecord::query()->pluck('tenant_id')->unique()->values()->all();

    expect($visible)->toBe([$habru->id])
        ->and($visible)->not->toContain($woldia->id);
});
