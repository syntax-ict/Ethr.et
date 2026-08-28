<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;

describe('POST /api/v1/auth/login', function () {
    it('authenticates with valid credentials', function () {
        $tenant = createTenant();
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'user@test.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@test.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
                'mfa_required',
            ]);

        expect($response->json('token_type'))->toBe('Bearer');
        expect($response->json('mfa_required'))->toBeFalse();
    });

    it('rejects invalid credentials', function () {
        $tenant = createTenant();
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'user@test.com',
            'password' => bcrypt('password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@test.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    it('blocks inactive accounts', function () {
        $tenant = createTenant();
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'suspended@test.com',
            'password' => bcrypt('password'),
            'status' => 'suspended',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@test.com',
            'password' => 'password',
        ])->assertForbidden();
    });

    it('validates required fields', function () {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    });

    it('authenticates a super admin without a resolved tenant', function () {
        User::factory()->create([
            'tenant_id' => null,
            'email' => 'superadmin@ethr.et',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'superadmin@ethr.et',
            'password' => 'password',
        ]);

        $response->assertOk()->assertJsonStructure(['access_token']);
    });

    it('authenticates a super admin even when a tenant is also resolved', function () {
        // Mirrors the real login form: the "Organization subdomain" field
        // is visible whenever the host itself doesn't resolve a tenant, and
        // a user may fill it in out of habit even when logging in as a
        // platform-wide super admin. The tenant-scoped lookup must not
        // shadow the super admin's tenant_id=null row in this case.
        createTenant(['subdomain' => 'demo']);
        User::factory()->create([
            'tenant_id' => null,
            'email' => 'superadmin@ethr.et',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'superadmin@ethr.et',
            'password' => 'password',
            'tenant' => 'demo',
        ]);

        $response->assertOk()->assertJsonStructure(['access_token']);
    });

    it('rejects a super admin with the wrong password when no tenant is resolved', function () {
        User::factory()->create([
            'tenant_id' => null,
            'email' => 'superadmin@ethr.et',
            'password' => bcrypt('password'),
            'role' => UserRole::SUPER_ADMIN,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'superadmin@ethr.et',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant']);
    });

    it('still requires a tenant for a non-super-admin email when none is resolved', function () {
        $tenant = Tenant::factory()->create();
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'user@test.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
        ]);

        // Deliberately not calling createTenant() — no tenant is resolved
        // for this request, and this user's tenant_id is not null, so the
        // super-admin fallback must not match them.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@test.com',
            'password' => 'password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant']);
    });
});

describe('POST /api/v1/auth/logout', function () {
    it('logs out authenticated user', function () {
        $user = actingAsUser();

        $this->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out successfully.']);
    });

    it('rejects unauthenticated logout', function () {
        $this->postJson('/api/v1/auth/logout')
            ->assertUnauthorized();
    });
});

describe('GET /api/v1/auth/me', function () {
    it('returns the current user profile', function () {
        $user = actingAsUser(['role' => UserRole::HR_ADMIN]);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonStructure([
                'user' => ['public_id', 'email', 'role', 'mfa_enabled', 'locale'],
            ]);

        expect($response->json('user.role'))->toBe('hr_admin');
        expect($response->json('user'))->not->toHaveKey('id');
    });

    it('rejects unauthenticated requests', function () {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    });

    it('returns the resolved permission list for the role', function () {
        actingAsUser(['role' => UserRole::EMPLOYEE]);

        $permissions = $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('permissions');

        expect($permissions)->toBeArray()
            ->toContain('attendance.checkIn')
            ->not->toContain('employee.create')
            ->not->toContain('settings.manage');
    });

    it('returns a custom role permission set instead of the base role set', function () {
        $tenant = createTenant();

        $customRole = CustomRole::create([
            'tenant_id' => $tenant->id,
            'name' => 'Custom Viewer',
        ]);
        $customRole->permissions()->sync(
            Permission::whereIn('name', ['employee.viewAny', 'employee.view'])->pluck('id')
        );

        actingAsUser([
            'role' => UserRole::EMPLOYEE,
            'custom_role_id' => $customRole->id,
        ], $tenant);

        $permissions = $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('permissions');

        // The custom role replaces the base set — attendance.checkIn is an
        // employee-role ability the custom role does not grant.
        expect($permissions)->toEqualCanonicalizing(['employee.viewAny', 'employee.view'])
            ->not->toContain('attendance.checkIn');
    });

    it('returns the full catalogue for super_admin', function () {
        actingAsUser(['role' => UserRole::SUPER_ADMIN]);

        $permissions = $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('permissions');

        expect($permissions)->toEqualCanonicalizing(Permission::allNames())
            ->toContain('admin.manage');
    });
});
