<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;

/**
 * Audit rows named their actor and subject by numeric id — `user_id: 42`,
 * `auditable_id: 17` — which convention 4 forbids exposing, and which the
 * page rendered as "User #42". They now carry the actor's public id and name
 * and the subject's public id.
 */
/** A user's name is their employee's, so a named actor needs one. */
function namedUser(Tenant $tenant, string $name, array $attributes = []): User
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => $name]);

    return createUser(['employee_id' => $employee->id, ...$attributes], $tenant);
}

function auditRow(array $attributes): AuditLog
{
    return AuditLog::query()->forceCreate([
        'action' => 'test.action',
        'payload' => [],
        'created_at' => now(),
        ...$attributes,
    ]);
}

test('the tenant audit log names actor and subject by public id, never a numeric key', function () {
    $tenant = createTenant();
    $admin = namedUser($tenant, 'Selam Bekele', ['role' => UserRole::TENANT_ADMIN]);
    test()->actingAs($admin);
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    auditRow([
        'tenant_id' => $tenant->id,
        'user_id' => $admin->id,
        'auditable_type' => Department::class,
        'auditable_id' => $department->id,
    ]);

    $row = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/audit-logs?filter[action]=test.")
        ->assertOk()
        ->json('data.0');

    expect($row)->not->toHaveKey('user_id')
        ->and($row)->not->toHaveKey('auditable_id')
        ->and($row['user'])->toBe(['public_id' => $admin->public_id, 'name' => 'Selam Bekele'])
        ->and($row['auditable_public_id'])->toBe($department->public_id);
});

test('a soft-deleted subject is still named', function () {
    $tenant = createTenant();
    $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $department = Department::factory()->create(['tenant_id' => $tenant->id]);
    $department->delete();
    auditRow([
        'tenant_id' => $tenant->id,
        'user_id' => $admin->id,
        'auditable_type' => Department::class,
        'auditable_id' => $department->id,
    ]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/audit-logs?filter[action]=test.")
        ->assertOk()
        ->assertJsonPath('data.0.auditable_public_id', $department->public_id);
});

test('a row whose subject class no longer exists does not break the page', function () {
    $tenant = createTenant();
    $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    auditRow([
        'tenant_id' => $tenant->id,
        'user_id' => $admin->id,
        'auditable_type' => 'App\\Models\\LoginAttempt',
        'auditable_id' => 1,
    ]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/audit-logs?filter[action]=test.")
        ->assertOk()
        ->assertJsonPath('data.0.auditable_type', 'App\\Models\\LoginAttempt')
        ->assertJsonPath('data.0.auditable_public_id', null);
});

test('a row cannot name a user or subject from another tenant', function () {
    $other = createTenant();
    $stranger = namedUser($other, 'Other Tenant User');
    $foreignDepartment = Department::factory()->create(['tenant_id' => $other->id]);

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    // A row that is this tenant's, but whose keys point into the other one.
    auditRow([
        'tenant_id' => $tenant->id,
        'user_id' => $stranger->id,
        'auditable_type' => Department::class,
        'auditable_id' => $foreignDepartment->id,
    ]);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/audit-logs?filter[action]=test.")
        ->assertOk()
        ->assertJsonPath('data.0.user', null)
        ->assertJsonPath('data.0.auditable_public_id', null);
});

test('the platform audit log names actors in every tenant', function () {
    $other = createTenant();
    $actor = namedUser($other, 'Hana Tesfaye');
    auditRow(['tenant_id' => $other->id, 'user_id' => $actor->id]);

    $home = createTenant();
    $superAdmin = createUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $home);
    test()->actingAs($superAdmin);
    // The platform view runs with no tenant resolved.
    app(CurrentTenant::class)->forget();

    $rows = collect(
        test()->getJson("http://{$home->subdomain}.ethr.test/api/v1/admin/audit")
            ->assertOk()
            ->json('data')
    );

    expect($rows->firstWhere('user.public_id', $actor->public_id)['user']['name'] ?? null)
        ->toBe('Hana Tesfaye')
        ->and($rows->every(fn (array $row): bool => ! array_key_exists('user_id', $row)))->toBeTrue();
});

test('the user filter takes a public id', function () {
    $tenant = createTenant();
    $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    $other = createUser([], $tenant);
    auditRow(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'action' => 'test.mine']);
    auditRow(['tenant_id' => $tenant->id, 'user_id' => $other->id, 'action' => 'test.theirs']);

    $actions = collect(
        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/audit-logs?filter[action]=test.&filter[user_id]={$other->public_id}")
            ->assertOk()
            ->json('data')
    )->pluck('action');

    expect($actions->all())->toBe(['test.theirs']);
});
