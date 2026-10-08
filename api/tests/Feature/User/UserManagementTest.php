<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\CustomRole;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

describe('POST /api/v1/users', function () {
    it('lets an hr admin invite a department admin and emails an activation link', function () {
        Notification::fake();
        actingAsUser(['role' => UserRole::HR_ADMIN]);

        $response = $this->postJson('/api/v1/users', [
            'email' => 'newdept@acme.test',
            'role' => 'dept_admin',
        ]);

        $response->assertCreated()
            ->assertJsonPath('email', 'newdept@acme.test')
            ->assertJsonPath('role', 'dept_admin')
            ->assertJsonPath('status', 'invited');

        $user = User::where('email', 'newdept@acme.test')->first();
        expect($user)->not->toBeNull();
        expect($user->status)->toBe('invited');
        expect($response->json())->not->toHaveKey('id');

        Notification::assertSentTo($user, AccountActivationNotification::class);
    });

    it('lets a tenant admin invite a finance admin', function () {
        Notification::fake();
        actingAsUser(['role' => UserRole::TENANT_ADMIN]);

        $this->postJson('/api/v1/users', [
            'email' => 'fin@acme.test',
            'role' => 'finance_admin',
        ])->assertCreated()->assertJsonPath('role', 'finance_admin');
    });

    it('prevents assigning a role above your own level', function () {
        actingAsUser(['role' => UserRole::HR_ADMIN]);

        $this->postJson('/api/v1/users', [
            'email' => 'boss@acme.test',
            'role' => 'tenant_admin',
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);
    });

    it('never allows assigning super_admin', function () {
        actingAsUser(['role' => UserRole::TENANT_ADMIN]);

        $this->postJson('/api/v1/users', [
            'email' => 'god@acme.test',
            'role' => 'super_admin',
        ])->assertUnprocessable()->assertJsonValidationErrors(['role']);
    });

    it('forbids users without the invite permission', function () {
        actingAsUser(['role' => UserRole::EMPLOYEE]);

        $this->postJson('/api/v1/users', [
            'email' => 'x@acme.test',
            'role' => 'employee',
        ])->assertForbidden();
    });

    it('rejects a duplicate email in the same tenant', function () {
        $tenant = createTenant();
        User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'dupe@acme.test']);
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/users', [
            'email' => 'dupe@acme.test',
            'role' => 'employee',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
    });
});

describe('user lifecycle management', function () {
    it('resends an activation link only for invited users', function () {
        Notification::fake();
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $invited = User::factory()->invited()->create(['tenant_id' => $tenant->id]);

        $this->postJson("/api/v1/users/{$invited->public_id}/resend-invite")
            ->assertOk()
            ->assertJsonPath('sent', true);

        Notification::assertSentTo($invited, AccountActivationNotification::class);
    });

    it('rejects resending an invite for an already-active user', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $active = User::factory()->create(['tenant_id' => $tenant->id, 'status' => 'active']);

        $this->postJson("/api/v1/users/{$active->public_id}/resend-invite")
            ->assertStatus(422);
    });

    it('updates a user role', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $target = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE]);

        $this->patchJson("/api/v1/users/{$target->public_id}", ['role' => 'supervisor'])
            ->assertOk()
            ->assertJsonPath('role', 'supervisor');
    });

    it('prevents an hr admin from modifying a tenant admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $boss = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]);

        $this->patchJson("/api/v1/users/{$boss->public_id}", ['status' => 'suspended'])
            ->assertForbidden();
    });

    it('deactivates a user but never yourself', function () {
        $tenant = createTenant();
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $target = User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::EMPLOYEE]);

        $this->deleteJson("/api/v1/users/{$target->public_id}")->assertNoContent();

        $target->refresh();
        expect($target->status)->toBe('inactive');
        expect($target->trashed())->toBeTrue();

        $this->deleteJson("/api/v1/users/{$admin->public_id}")->assertStatus(422);
    });
});

// A custom role could be assigned by anyone with users.update, whatever it
// granted, and another tenant's role passed validation and then silently
// cleared the user's role (audit N87).
describe('assigning a custom role', function () {
    function customRoleWith(int $tenantId, array $permissions): CustomRole
    {
        $role = CustomRole::query()->forceCreate([
            'tenant_id' => $tenantId,
            'name' => 'Role '.Str::random(6),
            'is_active' => true,
        ]);
        foreach ($permissions as $name) {
            DB::table('custom_role_permissions')->insert([
                'custom_role_id' => $role->id,
                'permission_id' => Permission::query()->where('name', $name)->value('id'),
            ]);
        }
        Permission::clearCache();

        return $role;
    }

    it('refuses an hr admin a role carrying abilities they lack, for themselves too', function () {
        $hr = actingAsUser(['role' => UserRole::HR_ADMIN]);
        $role = customRoleWith($hr->tenant_id, ['employee.view', 'settings.manage']);

        $this->patchJson("/api/v1/users/{$hr->public_id}", ['custom_role_id' => $role->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('custom_role_id');
        expect($hr->fresh()->custom_role_id)->toBeNull();
    });

    it('lets an hr admin assign a role within their own abilities', function () {
        $hr = actingAsUser(['role' => UserRole::HR_ADMIN]);
        $target = createUser(['role' => UserRole::EMPLOYEE], Tenant::find($hr->tenant_id));
        $role = customRoleWith($hr->tenant_id, ['employee.view', 'leave.approve']);

        $this->patchJson("/api/v1/users/{$target->public_id}", ['custom_role_id' => $role->public_id])
            ->assertOk();
        expect($target->fresh()->custom_role_id)->toBe($role->id);
    });

    it('refuses another tenant\'s role instead of silently clearing the user\'s', function () {
        // First: creating a tenant makes it the resolved one.
        $foreign = customRoleWith(createTenant()->id, ['employee.view']);
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN]);
        $own = customRoleWith($admin->tenant_id, ['employee.view']);
        $target = createUser(['role' => UserRole::EMPLOYEE, 'custom_role_id' => $own->id], Tenant::find($admin->tenant_id));

        $this->patchJson("/api/v1/users/{$target->public_id}", ['custom_role_id' => $foreign->public_id])
            ->assertUnprocessable()->assertJsonValidationErrors('custom_role_id');
        expect($target->fresh()->custom_role_id)->toBe($own->id);
    });
});
