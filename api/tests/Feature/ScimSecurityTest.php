<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Illuminate\Support\Facades\DB;

/*
 * ScimProvisioningTest covers the SCIM happy paths and ScimAuth's refusals.
 * These cover what an identity provider â€” or someone holding its token â€” can
 * reach beyond that: other tenants' ids, role escalation through the payload,
 * malformed bodies, and what "deprovisioned" actually takes away.
 */

function scimSecurityToken(Tenant $tenant, array $overrides = []): string
{
    $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);
    $token = 'scim-sec-'.bin2hex(random_bytes(16));

    ApiKey::create([
        'tenant_id' => $tenant->id,
        'name' => 'SCIM Security',
        'key_hash' => hash('sha256', $token),
        'key_prefix' => substr($token, 0, 8),
        'abilities' => ['scim'],
        'created_by' => $admin->id,
        'expires_at' => now()->addYear(),
        ...$overrides,
    ]);

    return $token;
}

/** @return array<string, string> */
function scimSecurityHeaders(string $token): array
{
    return ['Authorization' => "Bearer {$token}"];
}

/** @return array{employee: Employee, user: User} */
function scimSecurityPerson(Tenant $tenant, string $email): array
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'email' => $email, 'name' => 'Person Under Test']);
    $user = User::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'email' => $email,
        'status' => 'active',
        'role' => UserRole::EMPLOYEE,
    ]);

    return ['employee' => $employee, 'user' => $user];
}

// â”€â”€ Tenant isolation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

it('answers another tenant\'s user id exactly as it answers a nonexistent one, on every verb', function () {
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);
    $other = createTenant();
    ['user' => $theirs, 'employee' => $theirEmployee] = scimSecurityPerson($other, 'theirs@other.test');

    foreach ([$theirs->public_id, '01JNOTAREALUSER00000000000'] as $id) {
        $this->getJson("/api/v1/scim/v2/Users/{$id}", scimSecurityHeaders($token))->assertNotFound();
        $this->putJson("/api/v1/scim/v2/Users/{$id}", ['active' => false, 'name' => ['givenName' => 'Hijacked']], scimSecurityHeaders($token))->assertNotFound();
        $this->deleteJson("/api/v1/scim/v2/Users/{$id}", [], scimSecurityHeaders($token))->assertNotFound();
    }

    $row = DB::table('users')->where('id', $theirs->id)->first();
    expect($row->status)->toBe('active')
        ->and(DB::table('employees')->where('id', $theirEmployee->id)->value('name'))->toBe('Person Under Test');
});

it('never lists another tenant\'s users, even when filtered by their exact userName', function () {
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);
    $other = createTenant();
    scimSecurityPerson($other, 'theirs@other.test');

    $response = $this->getJson('/api/v1/scim/v2/Users?filter=userName eq "theirs@other.test"', scimSecurityHeaders($token))
        ->assertOk();

    expect($response->json('totalResults'))->toBe(0)
        ->and($response->json('Resources'))->toBe([]);
});

it('ignores another tenant\'s user ids in a group\'s members', function () {
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);
    ['user' => $mine, 'employee' => $myEmployee] = scimSecurityPerson($tenant, 'mine@acme.test');

    $other = createTenant();
    ['user' => $theirs, 'employee' => $theirEmployee] = scimSecurityPerson($other, 'theirs@other.test');
    app(CurrentTenant::class)->set($tenant);

    $response = $this->postJson('/api/v1/scim/v2/Groups', [
        'displayName' => 'Finance',
        'members' => [['value' => $mine->public_id], ['value' => $theirs->public_id]],
    ], scimSecurityHeaders($token))->assertCreated();

    expect($response->json('members'))->toHaveCount(1)
        ->and($response->json('members.0.value'))->toBe($mine->public_id)
        ->and(DB::table('employees')->where('id', $theirEmployee->id)->value('department_id'))->toBeNull()
        ->and(DB::table('employees')->where('id', $myEmployee->id)->value('department_id'))->not->toBeNull();
});

it('answers another tenant\'s group id with 404 and leaves it alone', function () {
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);
    $other = createTenant();
    $theirs = Department::factory()->create(['tenant_id' => $other->id, 'name' => 'Their Dept', 'is_active' => true]);

    $this->getJson("/api/v1/scim/v2/Groups/{$theirs->public_id}", scimSecurityHeaders($token))->assertNotFound();
    $this->putJson("/api/v1/scim/v2/Groups/{$theirs->public_id}", ['displayName' => 'Renamed'], scimSecurityHeaders($token))->assertNotFound();
    $this->deleteJson("/api/v1/scim/v2/Groups/{$theirs->public_id}", [], scimSecurityHeaders($token))->assertNotFound();

    $row = DB::table('departments')->where('id', $theirs->id)->first();
    expect($row->name)->toBe('Their Dept')
        ->and((bool) $row->is_active)->toBeTrue();
});

// â”€â”€ What the payload can and cannot set â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

it('ignores a role in the payload and provisions at the tenant\'s SSO default role', function () {
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);

    $response = $this->postJson('/api/v1/scim/v2/Users', [
        'userName' => 'climber@acme.test',
        'role' => 'tenant_admin',
        'roles' => [['value' => 'tenant_admin', 'primary' => true]],
    ], scimSecurityHeaders($token))->assertCreated();

    $user = User::where('public_id', $response->json('id'))->firstOrFail();
    expect($user->role)->toBe(UserRole::EMPLOYEE);

    $this->putJson("/api/v1/scim/v2/Users/{$user->public_id}", [
        'roles' => [['value' => 'super_admin']],
        'role' => 'super_admin',
    ], scimSecurityHeaders($token))->assertOk();

    expect($user->fresh()->role)->toBe(UserRole::EMPLOYEE);
});

it('creates a provisioned login with no usable password', function () {
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);

    $this->postJson('/api/v1/scim/v2/Users', [
        'userName' => 'nopass@acme.test',
        'password' => 'chosen-by-the-idp',
    ], scimSecurityHeaders($token))->assertCreated();

    $this->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => 'nopass@acme.test',
        'password' => 'chosen-by-the-idp',
    ])->assertStatus(422);
});

it('stamps the key\'s last use', function () {
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);

    $this->getJson('/api/v1/scim/v2/Users', scimSecurityHeaders($token))->assertOk();

    expect(ApiKey::where('key_hash', hash('sha256', $token))->value('last_used_at'))->not->toBeNull();
});

it('refuses a revoked key', function () {
    $tenant = createTenant();
    $token = scimSecurityToken($tenant, ['revoked_at' => now()->subMinute()]);

    $this->getJson('/api/v1/scim/v2/Users', scimSecurityHeaders($token))->assertUnauthorized();
});

// â”€â”€ Malformed input â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

it('answers a body with no identifier with a SCIM 400, not a 500', function () {
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);

    $this->postJson('/api/v1/scim/v2/Users', ['name' => ['givenName' => 'Nobody']], scimSecurityHeaders($token))
        ->assertStatus(400)
        ->assertJsonPath('schemas.0', 'urn:ietf:params:scim:api:messages:2.0:Error');

    $this->postJson('/api/v1/scim/v2/Groups', ['members' => []], scimSecurityHeaders($token))
        ->assertStatus(400);
});

it('answers a wrongly-typed userName with a SCIM 400, not a 500', function () {
    // ScimUserController::store reads $request->all() with no validation
    // (lines 59-63): `userName` as an array flows into a where('email', [...])
    // and `emails` as a string is indexed like an array.
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);

    $this->postJson('/api/v1/scim/v2/Users', ['userName' => ['a@acme.test']], scimSecurityHeaders($token))
        ->assertStatus(400);
    $this->postJson('/api/v1/scim/v2/Users', ['emails' => 'a@acme.test'], scimSecurityHeaders($token))
        ->assertStatus(400);

    expect(DB::table('users')->where('tenant_id', $tenant->id)->where('email', 'like', '%a@acme.test%')->exists())->toBeFalse();
})->todo(note: 'DEFECT: ScimUserController::store (lines 59-63) does no input validation; wrongly-typed userName/emails reach the query layer (500 or a junk row) instead of a SCIM 400');

it('rejects a filter it does not understand instead of returning every user', function () {
    // applyFilter() (ScimUserController.php:227-240) returns the unfiltered
    // query for any expression it does not recognise. An IdP that checks
    // "does this user exist?" with, say, `name.familyName eq "X"` is told
    // every user matches, and typically links its identity to Resources[0].
    // RFC 7644 Â§3.4.2.2 / Â§3.12: unsupported filters are a 400 invalidFilter.
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);
    scimSecurityPerson($tenant, 'one@acme.test');
    scimSecurityPerson($tenant, 'two@acme.test');

    $this->getJson('/api/v1/scim/v2/Users?filter='.urlencode('name.familyName eq "Nobody"'), scimSecurityHeaders($token))
        ->assertStatus(400)
        ->assertJsonPath('scimType', 'invalidFilter');
})->todo(note: 'DEFECT: ScimUserController::applyFilter (lines 227-240) silently ignores unsupported filters and returns all users; RFC 7644 requires 400 invalidFilter');

// â”€â”€ Deprovisioning and seats â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

it('ends the sessions of a user the IdP deprovisions', function () {
    // DELETE /Users/{id} sets users.status = inactive (lines 162-175) but
    // leaves every personal access token in place, and nothing on the
    // authenticated routes checks users.status â€” only the login endpoints
    // do. POST /auth/refresh issues a fresh token from a live one, so a
    // deprovisioned employee keeps API access indefinitely.
    // UserController::destroy() does revoke: `$user->tokens()->delete()`.
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);
    ['user' => $user] = scimSecurityPerson($tenant, 'leaver@acme.test');
    $session = $user->createToken('auth', ['*'], now()->addMinutes(15))->plainTextToken;
    $me = "http://{$tenant->subdomain}.ethr.test/api/v1/auth/me";

    $this->getJson($me, ['Authorization' => "Bearer {$session}"])->assertOk();

    $this->deleteJson("/api/v1/scim/v2/Users/{$user->public_id}", [], scimSecurityHeaders($token))->assertNoContent();

    app('auth')->forgetGuards();
    $this->getJson($me, ['Authorization' => "Bearer {$session}"])->assertUnauthorized();
})->todo(note: 'DEFECT: ScimUserController::destroy/update(active=false) never revoke tokens, and no auth middleware checks users.status; a deprovisioned user keeps API access and can refresh it');

it('does not provision past the tenant\'s plan employee limit', function () {
    // EmployeeController::store and the import commit both call
    // PlanLimitService::assertCanAdd('employees'). ScimUserController::store
    // creates the Employee without it, so an IdP can push any number of
    // seats into an active tenant on a capped plan.
    $tenant = createTenant();
    $token = scimSecurityToken($tenant);
    $plan = Plan::factory()->create(['max_employees' => 1]);
    Subscription::factory()->for($tenant)->create(['plan_id' => $plan->id]);
    Employee::factory()->create(['tenant_id' => $tenant->id]);

    $this->postJson('/api/v1/scim/v2/Users', ['userName' => 'seat.two@acme.test'], scimSecurityHeaders($token))
        ->assertForbidden();

    expect(Employee::where('tenant_id', $tenant->id)->count())->toBe(1);
})->todo(note: 'DEFECT: ScimUserController::store (line 79) creates employees without PlanLimitService::assertCanAdd; SCIM bypasses the plan seat cap');
