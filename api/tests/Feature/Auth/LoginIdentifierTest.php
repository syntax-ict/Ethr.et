<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\AuthIdentifierResolver;
use Illuminate\Support\Facades\Hash;

function loginTenant(array $identifiers = ['email']): Tenant
{
    $tenant = createTenant();
    $tenant->update(['settings' => array_merge($tenant->settings ?? [], ['login_identifiers' => $identifiers])]);

    return $tenant;
}

describe('login by identifier', function () {
    it('still authenticates by email (default policy, backward compatible)', function () {
        $tenant = createTenant();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'admin@acme.test',
            'password' => Hash::make('secret123'),
            'status' => 'active',
            'role' => UserRole::HR_ADMIN,
        ]);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
            'email' => 'admin@acme.test',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['access_token']);

        expect($user->fresh()->last_login_at)->not->toBeNull();
    });

    it('authenticates by employee number when the tenant enables it', function () {
        $tenant = loginTenant(['email', 'employee_code']);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-500']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'email' => 'noemail-user@acme.test',
            'password' => Hash::make('secret123'),
            'status' => 'active',
            'role' => UserRole::EMPLOYEE,
        ]);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
            'identifier' => 'EMP-500',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['access_token']);

        expect($user->fresh()->last_login_at)->not->toBeNull();
    });

    it('authenticates by mobile number regardless of formatting', function () {
        $tenant = loginTenant(['phone']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'phone' => '0911 45 67 89',
            'password' => Hash::make('secret123'),
            'status' => 'active',
            'role' => UserRole::EMPLOYEE,
        ]);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
            'identifier' => '0911456789',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['access_token']);

        expect($user->fresh()->last_login_at)->not->toBeNull();
    });

    it('authenticates by username, case-insensitively, when enabled', function () {
        $tenant = loginTenant(['username']);
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'username' => 'abebe.k',
            'email' => 'abebe@acme.test',
            'password' => Hash::make('secret123'),
            'status' => 'active',
            'role' => UserRole::EMPLOYEE,
        ]);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
            'identifier' => 'ABEBE.K',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['access_token']);

        expect($user->fresh()->last_login_at)->not->toBeNull();
    });

    it('refuses an identifier type the tenant has not enabled', function () {
        $tenant = loginTenant(['email']); // employee_code NOT enabled
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'employee_code' => 'EMP-9']);
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'employee_id' => $employee->id,
            'email' => 'x@acme.test',
            'password' => Hash::make('secret123'),
            'status' => 'active',
        ]);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
            'identifier' => 'EMP-9',
            'password' => 'secret123',
        ])->assertStatus(422);
    });

    it('does not authenticate across tenants', function () {
        $other = createTenant();
        User::factory()->create([
            'tenant_id' => $other->id,
            'email' => 'shared@acme.test',
            'password' => Hash::make('secret123'),
            'status' => 'active',
        ]);

        $mine = createTenant();

        test()->postJson("http://{$mine->subdomain}.ethr.test/api/v1/auth/login", [
            'email' => 'shared@acme.test',
            'password' => 'secret123',
        ])->assertStatus(422);
    });

    it('requires either identifier or email', function () {
        $tenant = createTenant();

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
            'password' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'identifier']);
    });
});

describe('username provisioning', function () {
    it('sets a username when creating a user, lower-cased', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/users", [
            'email' => 'newhire@acme.test',
            'username' => 'Abebe.K',
            'role' => 'employee',
        ])->assertCreated()->assertJsonPath('username', 'abebe.k');

        expect(User::where('tenant_id', $tenant->id)->where('username', 'abebe.k')->exists())->toBeTrue();
    });

    it('rejects a duplicate username within the tenant', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        User::factory()->create(['tenant_id' => $tenant->id, 'username' => 'taken']);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/users", [
            'email' => 'other@acme.test',
            'username' => 'taken',
            'role' => 'employee',
        ])->assertStatus(422)->assertJsonValidationErrors(['username']);
    });

    it('allows the same username in a different tenant', function () {
        $other = createTenant();
        User::factory()->create(['tenant_id' => $other->id, 'username' => 'shared']);

        $mine = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $mine);

        test()->postJson("http://{$mine->subdomain}.ethr.test/api/v1/users", [
            'email' => 'me@acme.test',
            'username' => 'shared',
            'role' => 'employee',
        ])->assertCreated();
    });

    it('rejects a username with illegal characters', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/users", [
            'email' => 'bad@acme.test',
            'username' => 'has spaces!',
            'role' => 'employee',
        ])->assertStatus(422)->assertJsonValidationErrors(['username']);
    });

    it('updates a username and lets the user log in with it', function () {
        $tenant = loginTenant(['username']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $target = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'target@acme.test',
            'password' => Hash::make('secret123'),
            'status' => 'active',
            'role' => UserRole::EMPLOYEE,
        ]);

        test()->patchJson(
            "http://{$tenant->subdomain}.ethr.test/api/v1/users/{$target->public_id}",
            ['username' => 'Target.User']
        )->assertOk()->assertJsonPath('username', 'target.user');

        // The handle just set resolves as a login identifier, case-insensitively.
        // (The HTTP login path itself is covered by the by-username test above;
        // asserting through the resolver here avoids the sanctum guard state that
        // actingAs leaves behind in the same test.)
        $resolved = app(AuthIdentifierResolver::class)
            ->resolve($tenant->id, 'TARGET.USER', ['username']);

        expect($resolved?->id)->toBe($target->id);
    });
});

describe('access step configuration', function () {
    it('reads and updates the tenant login identifier policy', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $host = "http://{$tenant->subdomain}.ethr.test/api/v1";

        test()->getJson("{$host}/onboarding/access")
            ->assertOk()
            ->assertJsonPath('login_identifiers', ['email']);

        test()->putJson("{$host}/onboarding/access", [
            'login_identifiers' => ['email', 'phone', 'employee_code'],
            'role_defaults' => ['employee' => 'phone'],
        ])->assertOk()->assertJsonPath('login_identifiers', ['email', 'phone', 'employee_code']);

        expect($tenant->fresh()->settings['login_identifiers'])->toBe(['email', 'phone', 'employee_code']);
    });

    it('exposes username among the available identifier types', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/access")
            ->assertOk()
            ->assertJsonPath('available', ['email', 'phone', 'employee_code', 'username']);
    });

    it('rejects an unknown identifier type', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        test()->putJson("http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/access", [
            'login_identifiers' => ['fingerprint'],
        ])->assertStatus(422)->assertJsonValidationErrors(['login_identifiers.0']);
    });

    it('denies access configuration to employees', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/onboarding/access")
            ->assertForbidden();
    });
});
