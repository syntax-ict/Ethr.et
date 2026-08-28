<?php

declare(strict_types=1);

use App\Enums\EmployeeStatus;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;

function createScimToken($tenant): string
{
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);
    $token = 'scim-test-token-'.uniqid();
    ApiKey::create([
        'tenant_id' => $tenant->id,
        'name' => 'SCIM Test',
        'key_hash' => hash('sha256', $token),
        'key_prefix' => substr($token, 0, 8),
        'abilities' => ['scim'],
        'created_by' => $admin->id,
        'expires_at' => now()->addYear(),
    ]);

    return $token;
}

// ──────────────────────── Auth ────────────────────────────────────────

test('scim endpoints reject requests without bearer token', function () {
    $response = $this->getJson('/api/v1/scim/v2/Users');

    $response->assertUnauthorized();
    expect($response->json('schemas.0'))->toBe('urn:ietf:params:scim:api:messages:2.0:Error');
});

test('scim endpoints reject invalid bearer token', function () {
    $response = $this->getJson('/api/v1/scim/v2/Users', [
        'Authorization' => 'Bearer invalid-token',
    ]);

    $response->assertUnauthorized();
});

test('scim endpoints reject non-scim api keys', function () {
    $tenant = createTenant();
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);
    $token = 'non-scim-token-'.uniqid();
    ApiKey::create([
        'tenant_id' => $tenant->id,
        'name' => 'Regular API Key',
        'key_hash' => hash('sha256', $token),
        'key_prefix' => substr($token, 0, 8),
        'abilities' => ['read'],
        'created_by' => $admin->id,
        'expires_at' => now()->addYear(),
    ]);

    $response = $this->getJson('/api/v1/scim/v2/Users', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertUnauthorized();
});

test('scim endpoints reject expired api keys', function () {
    $tenant = createTenant();
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);
    $token = 'expired-scim-token-'.uniqid();
    ApiKey::create([
        'tenant_id' => $tenant->id,
        'name' => 'Expired SCIM Key',
        'key_hash' => hash('sha256', $token),
        'key_prefix' => substr($token, 0, 8),
        'abilities' => ['scim'],
        'created_by' => $admin->id,
        'expires_at' => now()->subDay(),
    ]);

    $response = $this->getJson('/api/v1/scim/v2/Users', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertUnauthorized();
});

// ──────────────────────── Users: List ─────────────────────────────────

test('scim list users returns SCIM list response', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $response = $this->getJson('/api/v1/scim/v2/Users', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertOk();
    expect($response->json('schemas.0'))->toBe('urn:ietf:params:scim:api:messages:2.0:ListResponse');
    expect($response->json('totalResults'))->toBeInt();
    expect($response->json('Resources'))->toBeArray();
});

test('scim list users filters by userName', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'scim-filter@example.com']);
    User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'email' => 'scim-filter@example.com']);

    $response = $this->getJson('/api/v1/scim/v2/Users?filter=userName eq "scim-filter@example.com"', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertOk();
    expect($response->json('totalResults'))->toBe(1);
    expect($response->json('Resources.0.userName'))->toBe('scim-filter@example.com');
});

// ──────────────────────── Users: Create ───────────────────────────────

test('scim create user provisions employee and user', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $response = $this->postJson('/api/v1/scim/v2/Users', [
        'userName' => 'new.user@example.com',
        'name' => [
            'givenName' => 'Abebe',
            'familyName' => 'Kebede',
        ],
        'emails' => [['value' => 'new.user@example.com', 'type' => 'work', 'primary' => true]],
        'phoneNumbers' => [['value' => '+251911223344', 'type' => 'work']],
        'active' => true,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertCreated();
    expect($response->json('schemas.0'))->toBe('urn:ietf:params:scim:schemas:core:2.0:User');
    expect($response->json('userName'))->toBe('new.user@example.com');
    expect($response->json('name.givenName'))->toBe('Abebe');
    expect($response->json('name.familyName'))->toBe('Kebede');
    expect($response->json('active'))->toBeTrue();
    expect($response->json('id'))->toBeString();
    expect($response->json('meta.resourceType'))->toBe('User');

    $this->assertDatabaseHas('employees', ['email' => 'new.user@example.com', 'tenant_id' => $tenant->id]);
    $this->assertDatabaseHas('users', ['email' => 'new.user@example.com', 'tenant_id' => $tenant->id]);
});

test('scim create user rejects duplicate email', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dupe@example.com']);
    User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'email' => 'dupe@example.com']);

    $response = $this->postJson('/api/v1/scim/v2/Users', [
        'userName' => 'dupe@example.com',
        'name' => ['givenName' => 'Test', 'familyName' => 'User'],
    ], ['Authorization' => "Bearer $token"]);

    $response->assertStatus(409);
    expect($response->json('scimType'))->toBe('uniqueness');
});

// ──────────────────────── Users: Update ───────────────────────────────

test('scim update user modifies employee and user', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Old Name', 'email' => 'update-test@example.com']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'email' => 'update-test@example.com']);

    $response = $this->putJson("/api/v1/scim/v2/Users/{$user->public_id}", [
        'name' => ['givenName' => 'New', 'familyName' => 'Name'],
        'active' => false,
    ], ['Authorization' => "Bearer $token"]);

    $response->assertOk();
    expect($response->json('name.formatted'))->toBe('New Name');
    expect($response->json('active'))->toBeFalse();

    $employee->refresh();
    expect($employee->name)->toBe('New Name');
    expect($employee->status)->toBe(EmployeeStatus::SUSPENDED);
});

// ──────────────────────── Users: Deactivate ──────────────────────────

test('scim delete user deactivates instead of hard-deleting', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => 'deactivate@example.com']);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'email' => 'deactivate@example.com', 'status' => 'active']);

    $response = $this->deleteJson("/api/v1/scim/v2/Users/{$user->public_id}", [], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertNoContent();

    $user->refresh();
    $employee->refresh();
    expect($user->status)->toBe('inactive');
    expect($employee->status)->toBe(EmployeeStatus::SUSPENDED);
    expect($user->trashed())->toBeFalse();
});

// ──────────────────────── Groups: CRUD ───────────────────────────────

test('scim list groups returns departments', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);
    Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Marketing']);

    $response = $this->getJson('/api/v1/scim/v2/Groups', [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertOk();
    expect($response->json('totalResults'))->toBe(2);
    expect($response->json('Resources.0.schemas.0'))->toBe('urn:ietf:params:scim:schemas:core:2.0:Group');
});

test('scim create group creates department', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $response = $this->postJson('/api/v1/scim/v2/Groups', [
        'displayName' => 'Sales Team',
    ], ['Authorization' => "Bearer $token"]);

    $response->assertCreated();
    expect($response->json('displayName'))->toBe('Sales Team');
    $this->assertDatabaseHas('departments', ['name' => 'Sales Team', 'tenant_id' => $tenant->id]);
});

test('scim delete group soft-deactivates department', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'To Deactivate', 'is_active' => true]);

    $response = $this->deleteJson("/api/v1/scim/v2/Groups/{$dept->public_id}", [], [
        'Authorization' => "Bearer $token",
    ]);

    $response->assertNoContent();
    $dept->refresh();
    expect($dept->is_active)->toBeFalse();
});

// ──────────────────────── Groups: membership (PUT = full replace) ────

test('scim create group with members assigns them to the department', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    $user = User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

    $response = $this->postJson('/api/v1/scim/v2/Groups', [
        'displayName' => 'Sales',
        'members' => [['value' => $user->public_id]],
    ], ['Authorization' => "Bearer $token"]);

    $response->assertCreated();
    expect($response->json('members.0.value'))->toBe($user->public_id);
    $employee->refresh();
    $dept = Department::where('name', 'Sales')->firstOrFail();
    expect($employee->department_id)->toBe($dept->id);
});

test('scim group put removes a member who is no longer in the members array', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Engineering']);
    $stays = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id]);
    $staysUser = User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $stays->id]);
    $leaves = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id]);
    $leavesUser = User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $leaves->id]);

    // PUT is a full-replace: an IdP that dropped $leaves from the group and
    // pushed the group back down only lists $stays now. The old behaviour
    // only ever added members, so $leaves would have stayed in the
    // department forever — no SCIM group PUT could ever remove anyone.
    $response = $this->putJson("/api/v1/scim/v2/Groups/{$dept->public_id}", [
        'displayName' => 'Engineering',
        'members' => [['value' => $staysUser->public_id]],
    ], ['Authorization' => "Bearer $token"]);

    $response->assertOk();
    expect(collect($response->json('members'))->pluck('value')->all())
        ->toBe([$staysUser->public_id]);

    $stays->refresh();
    $leaves->refresh();
    expect($stays->department_id)->toBe($dept->id);
    expect($leaves->department_id)->toBeNull();
});

test('scim group put with an empty members array clears the whole department', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $dept = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Support']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $dept->id]);
    User::factory()->create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id]);

    $response = $this->putJson("/api/v1/scim/v2/Groups/{$dept->public_id}", [
        'displayName' => 'Support',
        'members' => [],
    ], ['Authorization' => "Bearer $token"]);

    $response->assertOk();
    expect($response->json('members'))->toBe([]);
    $employee->refresh();
    expect($employee->department_id)->toBeNull();
});

test('scim group put does not touch members of a different department', function () {
    $tenant = createTenant();
    $token = createScimToken($tenant);

    $deptA = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'A']);
    $deptB = Department::factory()->create(['tenant_id' => $tenant->id, 'name' => 'B']);
    $inB = Employee::factory()->create(['tenant_id' => $tenant->id, 'department_id' => $deptB->id]);

    $this->putJson("/api/v1/scim/v2/Groups/{$deptA->public_id}", [
        'displayName' => 'A',
        'members' => [],
    ], ['Authorization' => "Bearer $token"])->assertOk();

    $inB->refresh();
    expect($inB->department_id)->toBe($deptB->id);
});

// ──────────────────────── Tenant isolation ────────────────────────────

test('scim token from tenant A cannot access tenant B users', function () {
    $tenantA = createTenant();
    $tenantB = createTenant();
    $tokenA = createScimToken($tenantA);

    $employeeB = Employee::factory()->create(['tenant_id' => $tenantB->id, 'email' => 'b-user@example.com']);
    User::factory()->create(['tenant_id' => $tenantB->id, 'employee_id' => $employeeB->id, 'email' => 'b-user@example.com']);

    $response = $this->getJson('/api/v1/scim/v2/Users?filter=userName eq "b-user@example.com"', [
        'Authorization' => "Bearer $tokenA",
    ]);

    $response->assertOk();
    expect($response->json('totalResults'))->toBe(0);
});

// ──────────────────────── SCIM token generation ──────────────────────

test('tenant admin can generate scim token', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = $this->postJson('/api/v1/settings/scim-token', [
        'name' => 'Okta SCIM',
    ]);

    $response->assertCreated();
    expect($response->json('token'))->toBeString()->toHaveLength(64);
    expect($response->json('prefix'))->toBeString();
    expect($response->json('expires_at'))->toBeString();
});

test('employee cannot generate scim token', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    $response = $this->postJson('/api/v1/settings/scim-token', [
        'name' => 'Test',
    ]);

    $response->assertForbidden();
});
