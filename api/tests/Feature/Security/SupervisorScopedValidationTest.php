<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Tenant;

/**
 * `supervisor_id` validation, scoped to the tenant.
 *
 * `docs/audit/BASELINE.md` §11d site 5: the rule was
 * `exists:employees,public_id`, which goes through Laravel's
 * DatabasePresenceVerifier. That verifier does **not** apply Eloquent global
 * scopes, so another tenant's `public_id` passed validation.
 *
 * It was never a read of another tenant's row —
 * `EmployeeController::resolveRelationIds()` resolves with a *scoped* query, so
 * a foreign `public_id` became `null`. The effect was a supervisor that
 * silently vanished: 201 Created, `supervisor_id` empty, no error anywhere.
 * Two layers disagreed about what a valid supervisor is, and only one of them
 * said so.
 *
 * `ValidatesSupervisorTenancy` now re-runs the resolver's own scoped lookup in a
 * `withValidator()` hook, so a value that validates is a value that resolves.
 * The check lives in a hook rather than in `rules()` because expressing it as a
 * rule needs `Rule::exists(...)->where(...)`, and `ChangePlanRequest` records
 * why this repository avoids that builder: Scramble derives the public schema
 * from `rules()` and the contract gate fails on drift.
 *
 * **This is a behaviour change, not a guard**, authorised deliberately: a
 * foreign `supervisor_id` used to return 201 with a null supervisor and now
 * returns 422. The tests below pin both halves — the rejection, and the fact
 * that the rejection cannot be used to probe another tenant.
 *
 * It also turns `ScanMissingPunchesJob:84` from accidentally safe into
 * enforced-safe: that job looks the supervisor up by integer id with the tenant
 * scope removed, which is sound only because `supervisor_id` can hold nothing
 * but a same-tenant id. Nothing guaranteed that before.
 */
function otherTenantEmployee(string $name = 'Foreign Supervisor'): Employee
{
    $other = Tenant::factory()->create();

    return Employee::factory()->create([
        'tenant_id' => $other->id,
        'name' => $name,
    ]);
}

test('a supervisor from another tenant is rejected instead of silently nulled', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $foreign = otherTenantEmployee();

    $this->postJson('/api/v1/employees', [
        'name' => 'Abebe Kebede',
        'hire_date' => '2024-01-15',
        'supervisor_id' => $foreign->public_id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['supervisor_id']);

    // The behaviour this replaces: the employee used to be created.
    $this->assertDatabaseMissing('employees', ['name' => 'Abebe Kebede']);
});

test('a supervisor from the same tenant is accepted and actually stored', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);

    $response = $this->postJson('/api/v1/employees', [
        'name' => 'Abebe Kebede',
        'hire_date' => '2024-01-15',
        'supervisor_id' => $supervisor->public_id,
    ])->assertCreated();

    $created = Employee::where('public_id', $response->json('public_id'))->firstOrFail();

    expect($created->supervisor_id)->toBe($supervisor->id);
});

test('rejecting a foreign supervisor says exactly what rejecting a nonexistent one says', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $foreign = otherTenantEmployee();

    $payload = fn (string $supervisorId) => [
        'name' => 'Abebe Kebede',
        'hire_date' => '2024-01-15',
        'supervisor_id' => $supervisorId,
    ];

    $foreignMessage = $this->postJson('/api/v1/employees', $payload($foreign->public_id))
        ->assertUnprocessable()
        ->json('errors.supervisor_id');

    $nonexistentMessage = $this->postJson('/api/v1/employees', $payload('01JNONEXISTENTPUBLICID0000'))
        ->assertUnprocessable()
        ->json('errors.supervisor_id');

    // The point of the scope is that a caller cannot learn whether a public_id
    // exists in some other tenant. A message distinguishing "no such employee"
    // from "employee exists elsewhere" would hand that back, so the two must be
    // byte-identical rather than merely both-422.
    expect($foreignMessage)->toBe($nonexistentMessage);
});

test('a soft-deleted supervisor in the same tenant is rejected too', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $supervisor = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $publicId = $supervisor->public_id;
    $supervisor->delete();

    // Not an extra rule — a consequence of checking with the resolver's own
    // query. resolveRelationIds() resolves through the SoftDeletes scope and
    // would find nothing here, so accepting this would re-create the silent
    // null for deleted supervisors that the whole change exists to remove.
    $this->postJson('/api/v1/employees', [
        'name' => 'Abebe Kebede',
        'hire_date' => '2024-01-15',
        'supervisor_id' => $publicId,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['supervisor_id']);
});

test('update rejects a foreign supervisor as well as create', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $foreign = otherTenantEmployee();

    $this->putJson("/api/v1/employees/{$employee->public_id}", [
        'supervisor_id' => $foreign->public_id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['supervisor_id']);

    expect($employee->fresh()->supervisor_id)->toBeNull();
});

test('omitting a supervisor is still allowed', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    $this->postJson('/api/v1/employees', [
        'name' => 'Abebe Kebede',
        'employee_code' => 'EMP-NOSUP-1',
        'hire_date' => '2024-01-15',
    ])->assertCreated();

    $this->postJson('/api/v1/employees', [
        'name' => 'Kebede Abebe',
        'employee_code' => 'EMP-NOSUP-2',
        'hire_date' => '2024-01-15',
        'supervisor_id' => null,
    ])->assertCreated();
});
