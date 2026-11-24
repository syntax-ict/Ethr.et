<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Access\PositionAccess;
use App\Services\CurrentTenant;

// Who a job-title change counts as: only a person who could do it by hand on
// the Users page, never an integration, never someone without users.update.

function authorityTitle(Tenant $tenant, ?string $access): Position
{
    return Position::factory()->create(['tenant_id' => $tenant->id, 'title' => 'T'.uniqid(), 'access_role' => $access]);
}

function authorityHolder(Tenant $tenant, ?Position $position, UserRole $role = UserRole::EMPLOYEE, bool $follows = true): User
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'position_id' => $position?->id]);

    return User::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'role' => $role,
        'role_follows_position' => $follows,
    ]);
}

describe('the permission to manage users', function () {
    it('holds a role change when the person editing the employee lacks users.update', function () {
        $tenant = createTenant();
        $editor = createUser(['role' => UserRole::DEPT_ADMIN], $tenant);
        $custom = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'People editor', 'org_scope' => 'all']);
        $custom->permissions()->sync(Permission::whereIn('name', ['employee.update', 'employee.view', 'employee.viewAny'])->pluck('id'));
        $editor->update(['custom_role_id' => $custom->id]);
        $this->actingAs($editor);
        $holder = authorityHolder($tenant, authorityTitle($tenant, null));
        $supervisor = authorityTitle($tenant, 'supervisor');

        expect($editor->fresh()->hasPermission('users.update'))->toBeFalse();
        $this->patchJson('/api/v1/employees/'.$holder->employee->public_id, ['position_id' => $supervisor->public_id])->assertOk();

        expect($holder->fresh()->role)->toBe(UserRole::EMPLOYEE)
            ->and(app(PositionAccess::class)->heldQuery()->pluck('users.id')->all())->toBe([$holder->id]);
    });
});

describe('the add-employee form', function () {
    function employeeBody(string $email, string $code, Position $position): array
    {
        return [
            'name' => 'Person '.$code, 'email' => $email, 'employee_code' => $code, 'gender' => 'male',
            'date_of_birth' => '1990-03-15', 'nationality' => 'Ethiopian', 'marital_status' => 'single',
            'hire_date' => '2024-01-15', 'position_id' => $position->public_id,
            'create_login' => true, 'user_role' => 'employee',
        ];
    }

    it('gives a following login the title\'s role when the creator may give it', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', employeeBody('form@acme.test', 'EMP-8001', authorityTitle($tenant, 'dept_admin')))->assertCreated();

        $login = User::where('email', 'form@acme.test')->first();
        expect($login->role)->toBe(UserRole::DEPT_ADMIN)->and($login->role_follows_position)->toBeTrue();
    });

    it('leaves a following employee login that shows as held when the creator may not give the role', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', employeeBody('held@acme.test', 'EMP-8002', authorityTitle($tenant, 'finance_admin')))->assertCreated();

        $login = User::where('email', 'held@acme.test')->first();
        expect($login->role)->toBe(UserRole::EMPLOYEE)->and($login->role_follows_position)->toBeTrue()
            ->and(app(PositionAccess::class)->heldQuery()->pluck('users.id')->all())->toBe([$login->id]);
    });

    it('does not follow when the form names a role other than the plain one', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', ['user_role' => 'supervisor'] + employeeBody('chosen@acme.test', 'EMP-8003', authorityTitle($tenant, 'dept_admin')))->assertCreated();

        $login = User::where('email', 'chosen@acme.test')->first();
        expect($login->role)->toBe(UserRole::SUPERVISOR)->and($login->role_follows_position)->toBeFalse();
    });
});

describe('your own access', function () {
    it('cannot be pinned by switching off following', function () {
        $tenant = createTenant();
        $hr = authorityHolder($tenant, authorityTitle($tenant, 'hr_admin'), UserRole::HR_ADMIN);
        $this->actingAs($hr);

        $this->patchJson('/api/v1/users/'.$hr->public_id, ['role_follows_position' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_follows_position']);
        expect($hr->fresh()->role_follows_position)->toBeTrue();
    });

    it('cannot be pinned by a hand role change while it follows the title', function () {
        $tenant = createTenant();
        $hr = authorityHolder($tenant, authorityTitle($tenant, 'hr_admin'), UserRole::HR_ADMIN);
        $this->actingAs($hr);

        $this->patchJson('/api/v1/users/'.$hr->public_id, ['role' => 'dept_admin'])
            ->assertUnprocessable()->assertJsonValidationErrors(['role']);
        $this->patchJson('/api/v1/users/'.$hr->public_id, ['role' => 'hr_admin'])->assertOk();
    });

    it('cannot be switched to follow either', function () {
        $tenant = createTenant();
        $hr = authorityHolder($tenant, authorityTitle($tenant, null), UserRole::HR_ADMIN, follows: false);
        $this->actingAs($hr);

        $this->patchJson('/api/v1/users/'.$hr->public_id, ['role_follows_position' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_follows_position']);
    });

    it('is the organisation admin\'s to pin', function () {
        $tenant = createTenant();
        $admin = authorityHolder($tenant, authorityTitle($tenant, null), UserRole::TENANT_ADMIN);
        $this->actingAs($admin);

        $this->patchJson('/api/v1/users/'.$admin->public_id, ['role_follows_position' => false])->assertOk();
    });

    it('counts turning following off as a role change for a peer and for the owner', function () {
        $tenant = createTenant();
        $hr = createUser(['role' => UserRole::HR_ADMIN], $tenant);
        $this->actingAs($hr);
        $peer = authorityHolder($tenant, authorityTitle($tenant, 'hr_admin'), UserRole::HR_ADMIN);

        $this->patchJson('/api/v1/users/'.$peer->public_id, ['role_follows_position' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_follows_position']);

        $owner = authorityHolder($tenant, authorityTitle($tenant, null), UserRole::EMPLOYEE);
        $tenant->update(['owner_user_id' => $owner->id]);
        $this->actingAs(createUser(['role' => UserRole::TENANT_ADMIN], $tenant));
        $this->patchJson('/api/v1/users/'.$owner->public_id, ['role_follows_position' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_follows_position']);
    });
});

describe('an API key', function () {
    function keyedAs(Tenant $tenant, User $creator): array
    {
        $plain = 'ethr_'.bin2hex(random_bytes(20));
        ApiKey::factory()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $creator->id,
            'key_hash' => hash('sha256', $plain),
            'key_prefix' => substr($plain, 0, 12),
            'abilities' => ['write'],
        ]);
        app(CurrentTenant::class)->forget();

        return ['Authorization' => "Bearer {$plain}", 'Accept' => 'application/json'];
    }

    it('holds a role change made by moving someone to another job title', function () {
        $tenant = createTenant();
        $creator = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $holder = authorityHolder($tenant, authorityTitle($tenant, null));
        $supervisor = authorityTitle($tenant, 'supervisor');
        $employeeId = $holder->employee->public_id;
        $headers = keyedAs($tenant, $creator);

        $this->withHeaders($headers)->patchJson('/api/v1/employees/'.$employeeId, ['position_id' => $supervisor->public_id])->assertOk();

        app(CurrentTenant::class)->set($tenant);
        expect($holder->fresh()->role)->toBe(UserRole::EMPLOYEE)
            ->and(app(PositionAccess::class)->heldQuery()->pluck('users.id')->all())->toBe([$holder->id]);
    });

    it('creates a following employee login that is held on a mapped title', function () {
        $tenant = createTenant();
        $creator = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $position = authorityTitle($tenant, 'dept_admin');
        $headers = keyedAs($tenant, $creator);

        $this->withHeaders($headers)->postJson('/api/v1/employees', employeeBody('api@acme.test', 'EMP-8101', $position))->assertCreated();

        app(CurrentTenant::class)->set($tenant);
        $login = User::where('email', 'api@acme.test')->first();
        expect($login->role)->toBe(UserRole::EMPLOYEE)->and($login->role_follows_position)->toBeTrue()
            ->and(app(PositionAccess::class)->heldQuery()->pluck('users.id')->all())->toBe([$login->id]);
    });
});
