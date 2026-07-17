<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Events\AttendanceRecorded;
use App\Events\PayrollProcessed;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\PayrollRun;

function workforceHeadcount(string $subdomain): int
{
    $response = test()->getJson("http://{$subdomain}.ethr.test/api/v1/dashboard/executive/workforce");

    return collect($response->json('by_gender'))->sum('count');
}

test('executive dashboard workforce endpoint is cached between requests', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'gender' => 'male']);

    expect(workforceHeadcount($tenant->subdomain))->toBe(2);

    // New employee created, but nothing invalidates the cache yet.
    Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'female']);

    expect(workforceHeadcount($tenant->subdomain))->toBe(2);
});

test('AttendanceRecorded invalidates the cached executive dashboard', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    expect(workforceHeadcount($tenant->subdomain))->toBe(2);

    $newEmployee = Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'female']);
    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $newEmployee->id,
    ]);

    event(new AttendanceRecorded($record));

    expect(workforceHeadcount($tenant->subdomain))->toBe(3);
});

test('PayrollProcessed invalidates the cached executive dashboard', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    Employee::factory()->count(2)->create(['tenant_id' => $tenant->id, 'gender' => 'male']);
    expect(workforceHeadcount($tenant->subdomain))->toBe(2);

    Employee::factory()->create(['tenant_id' => $tenant->id, 'gender' => 'female']);
    $run = PayrollRun::factory()->create(['tenant_id' => $tenant->id]);

    event(new PayrollProcessed($run));

    expect(workforceHeadcount($tenant->subdomain))->toBe(3);
});

test('cache invalidation for one tenant does not affect another', function () {
    $tenantA = createTenant();
    $tenantB = createTenant();

    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenantA);
    Employee::factory()->count(2)->create(['tenant_id' => $tenantA->id, 'gender' => 'male']);
    expect(workforceHeadcount($tenantA->subdomain))->toBe(2);

    // Bump tenant B's cache version only.
    $runB = PayrollRun::factory()->create(['tenant_id' => $tenantB->id]);
    event(new PayrollProcessed($runB));

    Employee::factory()->create(['tenant_id' => $tenantA->id, 'gender' => 'female']);

    // Tenant A's cache should still be stale — untouched by tenant B's invalidation.
    expect(workforceHeadcount($tenantA->subdomain))->toBe(2);
});
