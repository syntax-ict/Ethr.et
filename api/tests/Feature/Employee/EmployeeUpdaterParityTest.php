<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Jobs\DispatchWebhookJob;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\EmployeeUpdater;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;

/*
 * `EmployeeUpdater` is the edit, lifted out of `EmployeeController::update` so
 * a reviewed import (One employee identity, PR 5) can change an employee and
 * leave exactly what an ordinary edit leaves. These tests hold the two paths
 * together: the same change through the route and through the class must give
 * the same row, the same audit record and the same webhook.
 */

/**
 * Two employees that differ only in what must be unique.
 *
 * Read back from the database, as the route's model binding reads its own: a
 * model fresh from a factory does not carry the column defaults, so its
 * `before` would differ for a reason that is the test's and not the code's.
 */
function parityEmployee(Tenant $tenant): Employee
{
    $created = Employee::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Before Name',
        'phone' => null,
        'gender' => 'female',
        'date_of_birth' => '1990-01-01',
        'marital_status' => 'single',
        'hire_date' => '2023-01-01',
        'salary_cents' => 1_000_000,
        'national_id' => 'NID-BEFORE-1',
        'tin' => '0000000001',
    ]);

    return Employee::query()->findOrFail($created->id);
}

/** @return array<string, mixed> */
function parityEdit(Department $department): array
{
    return [
        'name' => 'Edited Name',
        'phone' => '0911223344',
        'nationality_status' => 'ethiopian',
        'national_id' => 'NID-AFTER-2',
        'hire_date' => '2024-03-01',
        'salary_cents' => 4_200_000,
        'tin' => '0000000002',
        'department_id' => $department->public_id,
    ];
}

/** @return array<string, mixed> */
function parityRow(Employee $employee): array
{
    return $employee->fresh()->only([
        'name', 'phone', 'nationality_status', 'nationality_needs_review', 'national_id',
        'national_id_hash', 'hire_date', 'salary_cents', 'tin', 'department_id',
    ]);
}

function parityAudit(Employee $employee): AuditLog
{
    return AuditLog::query()
        ->where('auditable_type', Employee::class)
        ->where('auditable_id', $employee->id)
        ->where('action', 'employee.updated')
        ->sole();
}

it('leaves the same row, audit record and webhook as an edit through the route', function () {
    Queue::fake();

    $tenant = createTenant();
    $actor = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    Webhook::factory()->create(['tenant_id' => $tenant->id, 'events' => ['employee.updated']]);

    $viaRoute = parityEmployee($tenant);
    $viaUpdater = parityEmployee($tenant);

    $this->putJson("/api/v1/employees/{$viaRoute->public_id}", parityEdit($department))->assertOk();

    $returned = app(EmployeeUpdater::class)->update($viaUpdater, parityEdit($department), $actor);

    // The row.
    expect($returned->is($viaUpdater))->toBeTrue()
        ->and(parityRow($viaUpdater))->toEqual(parityRow($viaRoute))
        ->and($viaUpdater->fresh()->phone)->toBe('+251911223344')
        ->and($viaUpdater->fresh()->department_id)->toBe($department->id)
        ->and($viaUpdater->fresh()->nationality_needs_review)->toBeFalse();

    // The audit record: one each, same actor, same keys, same recorded values.
    $routeAudit = parityAudit($viaRoute);
    $updaterAudit = parityAudit($viaUpdater);

    expect($updaterAudit->user_id)->toBe($routeAudit->user_id)
        ->and($updaterAudit->tenant_id)->toBe($routeAudit->tenant_id)
        ->and(array_keys($updaterAudit->payload))->toBe(array_keys($routeAudit->payload))
        ->and(array_keys($updaterAudit->payload['before']))->toBe(array_keys($routeAudit->payload['before']))
        ->and(array_keys($updaterAudit->payload['after']))->toBe(array_keys($routeAudit->payload['after']))
        ->and($updaterAudit->payload)->toEqual($routeAudit->payload);

    // The webhook: one delivery each, carrying the same two fields. Sorted by
    // key before comparing, because MySQL 8 does not keep a JSON object in the
    // order it was written.
    $deliveries = WebhookDelivery::query()->where('event', 'employee.updated')->orderBy('id')->get();
    $sent = $deliveries->map(function (WebhookDelivery $delivery): array {
        $data = $delivery->payload['data'];
        ksort($data);

        return $data;
    });

    expect($deliveries)->toHaveCount(2)
        ->and($sent[0])->toBe(['name' => 'Edited Name', 'public_id' => $viaRoute->public_id])
        ->and($sent[1])->toBe(['name' => 'Edited Name', 'public_id' => $viaUpdater->public_id]);

    Queue::assertPushed(DispatchWebhookJob::class, 2);
});

it('refuses a pay change without employee.updateFinancial on both paths, and writes nothing', function () {
    $tenant = createTenant();
    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Edits people', 'org_scope' => 'all']);
    $role->permissions()->sync(
        Permission::whereIn('name', ['employee.viewAny', 'employee.view', 'employee.update'])->pluck('id')
    );
    $actor = actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant);

    $viaRoute = parityEmployee($tenant);
    $viaUpdater = parityEmployee($tenant);

    $this->putJson("/api/v1/employees/{$viaRoute->public_id}", ['name' => 'Renamed', 'tin' => '0000000009'])
        ->assertForbidden();

    expect(fn () => app(EmployeeUpdater::class)->update($viaUpdater, ['name' => 'Renamed', 'tin' => '0000000009'], $actor))
        ->toThrow(AuthorizationException::class);

    expect($viaRoute->fresh()->name)->toBe('Before Name')
        ->and($viaUpdater->fresh()->name)->toBe('Before Name')
        ->and($viaUpdater->fresh()->tin)->toBe('0000000001')
        ->and(AuditLog::query()->where('action', 'employee.updated')->count())->toBe(0);
});

it('does not treat a resent, unchanged salary or TIN as a pay change on either path', function () {
    $tenant = createTenant();
    $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Edits people', 'org_scope' => 'all']);
    $role->permissions()->sync(
        Permission::whereIn('name', ['employee.viewAny', 'employee.view', 'employee.update'])->pluck('id')
    );
    $actor = actingAsUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $role->id], $tenant);

    $viaRoute = parityEmployee($tenant);
    $viaUpdater = parityEmployee($tenant);
    $resent = ['name' => 'Renamed', 'salary_cents' => 1_000_000, 'tin' => '0000000001'];

    $this->putJson("/api/v1/employees/{$viaRoute->public_id}", $resent)->assertOk();
    app(EmployeeUpdater::class)->update($viaUpdater, $resent, $actor);

    expect($viaRoute->fresh()->name)->toBe('Renamed')
        ->and($viaUpdater->fresh()->name)->toBe('Renamed')
        ->and(parityAudit($viaUpdater)->payload)->toEqual(parityAudit($viaRoute)->payload);
});
