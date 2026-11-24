<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Access\PositionAccess;
use App\Services\CurrentTenant;
use App\Services\UserProvisioningService;
use App\Support\BackgroundWork;

// An account's access follows its person's job title, by one rule: a title
// change does automatically only what the person making it could have done by
// hand on the Users page. Anything else stays as it was and shows as waiting.

/** A position, optionally giving $access. */
function titleOf(Tenant $tenant, ?string $access = null, string $title = 'Clerk'): Position
{
    return Position::factory()->create(['tenant_id' => $tenant->id, 'title' => $title, 'access_role' => $access]);
}

/** An employee holding $position, with a login that follows the title. */
function holderOf(Tenant $tenant, ?Position $position, UserRole $role = UserRole::EMPLOYEE, bool $follows = true): User
{
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'position_id' => $position?->id]);

    return User::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'role' => $role,
        'role_follows_position' => $follows,
    ]);
}

function moveTo(User $holder, Position $position): void
{
    test()->patchJson('/api/v1/employees/'.$holder->employee->public_id, ['position_id' => $position->public_id])->assertOk();
}

describe('mapping a job title to access', function () {
    it('lets a tenant admin map a title and raises the accounts that follow it, not the ones set by hand', function () {
        $tenant = createTenant();
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $position = titleOf($tenant);
        $following = holderOf($tenant, $position);
        $byHand = holderOf($tenant, $position, UserRole::EMPLOYEE, follows: false);

        $this->patchJson('/api/v1/organization/positions/'.$position->public_id, ['access_role' => 'finance_admin'])
            ->assertOk()->assertJsonPath('access_role', 'finance_admin');

        expect($following->fresh()->role)->toBe(UserRole::FINANCE_ADMIN)
            ->and($byHand->fresh()->role)->toBe(UserRole::EMPLOYEE);

        $audit = AuditLog::query()->where('action', 'user.role_from_title')->latest('id')->first();
        expect($audit->payload['before'])->toBe('employee')
            ->and($audit->payload['after'])->toBe('finance_admin')
            ->and($audit->payload['by'])->toBe($admin->id);
        expect(AuditLog::query()->where('action', 'position.access_changed')->exists())->toBeTrue();
    });

    it('lets an hr admin map supervisor and dept_admin but refuses hr_admin and finance_admin', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $position = titleOf($tenant);

        $this->patchJson('/api/v1/organization/positions/'.$position->public_id, ['access_role' => 'dept_admin'])->assertOk();
        $this->patchJson('/api/v1/organization/positions/'.$position->public_id, ['access_role' => 'supervisor'])->assertOk();
        $this->patchJson('/api/v1/organization/positions/'.$position->public_id, ['access_role' => 'hr_admin'])
            ->assertUnprocessable()->assertJsonValidationErrors(['access_role']);
        $this->patchJson('/api/v1/organization/positions/'.$position->public_id, ['access_role' => 'finance_admin'])
            ->assertUnprocessable()->assertJsonValidationErrors(['access_role']);
    });

    it('refuses an hr admin clearing a title that gives a role they may not give', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $position = titleOf($tenant, 'finance_admin');
        $holder = holderOf($tenant, $position, UserRole::FINANCE_ADMIN);

        $this->patchJson('/api/v1/organization/positions/'.$position->public_id, ['access_role' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['access_role']);

        expect($holder->fresh()->role)->toBe(UserRole::FINANCE_ADMIN);
    });

    it('refuses someone without users.update, even with org.update', function () {
        $tenant = createTenant();
        $user = actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);
        $position = titleOf($tenant);
        $role = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Org only', 'org_scope' => 'tenant']);
        $role->permissions()->sync(Permission::whereIn('name', ['org.update', 'org.viewAny'])->pluck('id'));
        $user->update(['custom_role_id' => $role->id]);

        $this->patchJson('/api/v1/organization/positions/'.$position->public_id, ['access_role' => 'supervisor'])
            ->assertUnprocessable()->assertJsonValidationErrors(['access_role']);
        $this->patchJson('/api/v1/organization/positions/'.$position->public_id, ['title' => 'Renamed'])->assertOk();
    });

    it('rejects tenant_admin and super_admin as a value', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $position = titleOf($tenant);

        foreach (['tenant_admin', 'super_admin', 'employee'] as $value) {
            $this->patchJson('/api/v1/organization/positions/'.$position->public_id, ['access_role' => $value])
                ->assertUnprocessable()->assertJsonValidationErrors(['access_role']);
        }
    });

    it('takes the access on create, under the same rule', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/organization/positions', ['title' => 'Section head', 'access_role' => 'finance_admin'])
            ->assertUnprocessable()->assertJsonValidationErrors(['access_role']);
        $this->postJson('/api/v1/organization/positions', ['title' => 'Section head', 'access_role' => 'dept_admin'])
            ->assertCreated()->assertJsonPath('access_role', 'dept_admin');
    });

    it('never touches another organisation\'s accounts', function () {
        $a = createTenant();
        $b = createTenant();
        app(CurrentTenant::class)->set($a);
        $theirs = holderOf($b, titleOf($b, 'supervisor'));
        $mine = titleOf($a);
        $mineHolder = holderOf($a, $mine);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $a);

        $this->patchJson('/api/v1/organization/positions/'.$mine->public_id, ['access_role' => 'dept_admin'])->assertOk();

        expect($mineHolder->fresh()->role)->toBe(UserRole::DEPT_ADMIN)
            ->and($theirs->fresh()->role)->toBe(UserRole::EMPLOYEE);
    });
});

describe('moving a person to another job title', function () {
    it('applies a dept_admin title when an hr admin moves someone onto it', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $holder = holderOf($tenant, titleOf($tenant));
        $head = titleOf($tenant, 'dept_admin', 'Head');

        moveTo($holder, $head);

        expect($holder->fresh()->role)->toBe(UserRole::DEPT_ADMIN);
    });

    it('holds a finance_admin title for an hr admin, lists it, and lets only a tenant admin apply it', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $holder = holderOf($tenant, titleOf($tenant));
        $accountant = titleOf($tenant, 'finance_admin', 'Accountant');

        moveTo($holder, $accountant);

        expect($holder->fresh()->role)->toBe(UserRole::EMPLOYEE);

        $held = $this->getJson('/api/v1/users?filter[title_access]=held')->assertOk();
        expect(collect($held->json('data'))->pluck('public_id')->all())->toBe([$holder->public_id]);
        expect($held->json('data.0.title_role'))->toBe('finance_admin')
            ->and($held->json('data.0.role_follows_position'))->toBeTrue();

        $this->postJson('/api/v1/users/'.$holder->public_id.'/title-access')
            ->assertUnprocessable()->assertJsonPath('status', 422);
        expect($holder->fresh()->role)->toBe(UserRole::EMPLOYEE);

        $this->actingAs(User::factory()->create(['tenant_id' => $tenant->id, 'role' => UserRole::TENANT_ADMIN]));
        $this->postJson('/api/v1/users/'.$holder->public_id.'/title-access')
            ->assertOk()->assertJsonPath('role', 'finance_admin');

        expect($this->getJson('/api/v1/users?filter[title_access]=held')->json('data'))->toBe([]);
    });

    it('does not change an hr admin\'s own role when they change their own job title', function () {
        $tenant = createTenant();
        $hr = holderOf($tenant, titleOf($tenant), UserRole::HR_ADMIN);
        $this->actingAs($hr);
        $own = titleOf($tenant, 'dept_admin', 'Own');

        moveTo($hr, $own);

        expect($hr->fresh()->role)->toBe(UserRole::HR_ADMIN);
    });

    it('lowers a person to employee when they move off a title that gave a role', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $holder = holderOf($tenant, titleOf($tenant, 'dept_admin'), UserRole::DEPT_ADMIN);
        $plain = titleOf($tenant, null, 'Plain');

        moveTo($holder, $plain);

        expect($holder->fresh()->role)->toBe(UserRole::EMPLOYEE);
    });

    it('never changes the organisation owner', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $owner = holderOf($tenant, titleOf($tenant), UserRole::TENANT_ADMIN);
        $tenant->update(['owner_user_id' => $owner->id]);

        moveTo($owner, titleOf($tenant, 'supervisor', 'Sup'));

        expect($owner->fresh()->role)->toBe(UserRole::TENANT_ADMIN)
            ->and(app(PositionAccess::class)->titleRole($owner->fresh()))->toBeNull();
    });

    it('never changes an account with a custom role', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $custom = CustomRole::create(['tenant_id' => $tenant->id, 'name' => 'Custom', 'org_scope' => 'tenant']);
        $holder = holderOf($tenant, titleOf($tenant));
        $holder->update(['custom_role_id' => $custom->id]);

        moveTo($holder, titleOf($tenant, 'supervisor', 'Sup'));

        expect($holder->fresh()->role)->toBe(UserRole::EMPLOYEE);
    });

    it('holds inside background work even with an administrator signed in', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $holder = holderOf($tenant, titleOf($tenant));
        $supervisor = titleOf($tenant, 'supervisor', 'Sup');

        BackgroundWork::run(fn () => $holder->employee->update(['position_id' => $supervisor->id]));

        expect($holder->fresh()->role)->toBe(UserRole::EMPLOYEE)
            ->and(app(PositionAccess::class)->heldQuery()->pluck('users.id')->all())->toBe([$holder->id]);
    });

    it('holds when nobody is signed in, as a queue run is', function () {
        $tenant = createTenant();
        $holder = holderOf($tenant, titleOf($tenant));

        $holder->employee->update(['position_id' => titleOf($tenant, 'supervisor', 'Sup')->id]);

        expect($holder->fresh()->role)->toBe(UserRole::EMPLOYEE)
            ->and(app(PositionAccess::class)->heldQuery()->pluck('users.id')->all())->toBe([$holder->id]);
    });
});

describe('following the job title by hand', function () {
    it('turns following off when the role is edited by hand', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $holder = holderOf($tenant, titleOf($tenant));

        $this->patchJson('/api/v1/users/'.$holder->public_id, ['role' => 'supervisor'])
            ->assertOk()->assertJsonPath('role_follows_position', false);

        expect($holder->fresh()->role_follows_position)->toBeFalse();
    });

    it('keeps following when the same role is sent again', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $holder = holderOf($tenant, titleOf($tenant));

        $this->patchJson('/api/v1/users/'.$holder->public_id, ['role' => 'employee'])->assertOk();

        expect($holder->fresh()->role_follows_position)->toBeTrue();
    });

    it('syncs when following is turned on, and ignores a role in the same request', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $holder = holderOf($tenant, titleOf($tenant, 'dept_admin'), UserRole::EMPLOYEE, follows: false);

        $this->patchJson('/api/v1/users/'.$holder->public_id, ['role_follows_position' => true, 'role' => 'finance_admin'])
            ->assertOk()->assertJsonPath('role', 'dept_admin')->assertJsonPath('role_follows_position', true);

        $audit = AuditLog::query()->where('action', 'user.updated')->latest('id')->first();
        expect($audit->payload['before']['role_follows_position'])->toBeFalse()
            ->and($audit->payload['after']['role_follows_position'])->toBeTrue();
    });

    it('refuses turning following on for the organisation owner', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $owner = holderOf($tenant, titleOf($tenant, 'supervisor'), UserRole::TENANT_ADMIN, follows: false);
        $tenant->update(['owner_user_id' => $owner->id]);

        $this->patchJson('/api/v1/users/'.$owner->public_id, ['role_follows_position' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_follows_position']);
    });

    it('refuses an hr admin turning following on for a peer', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $peer = holderOf($tenant, titleOf($tenant), UserRole::HR_ADMIN, follows: false);

        $this->patchJson('/api/v1/users/'.$peer->public_id, ['role_follows_position' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['role_follows_position']);
    });

    it('refuses an hr admin applying a title to a higher account', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $boss = holderOf($tenant, titleOf($tenant), UserRole::TENANT_ADMIN);

        $this->postJson('/api/v1/users/'.$boss->public_id.'/title-access')->assertForbidden();
    });
});

describe('a new login', function () {
    it('gets the title\'s role when the creator may give it', function () {
        $tenant = createTenant();
        $creator = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'position_id' => titleOf($tenant, 'dept_admin')->id, 'email' => 'new@acme.test']);

        $user = app(UserProvisioningService::class)->provision(
            email: 'new@acme.test', role: UserRole::EMPLOYEE, employee: $employee,
            invitedBy: $creator->id, sendActivation: false, followsPosition: true,
        );

        expect($user->fresh()->role)->toBe(UserRole::DEPT_ADMIN)->and($user->fresh()->role_follows_position)->toBeTrue();
    });

    it('stays an employee and shows as waiting when the creator may not give the role', function () {
        $tenant = createTenant();
        $creator = actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'position_id' => titleOf($tenant, 'finance_admin')->id, 'email' => 'fin@acme.test']);

        $user = app(UserProvisioningService::class)->provision(
            email: 'fin@acme.test', role: UserRole::EMPLOYEE, employee: $employee,
            invitedBy: $creator->id, sendActivation: false, followsPosition: true,
        );

        expect($user->fresh()->role)->toBe(UserRole::EMPLOYEE);
        expect(app(PositionAccess::class)->heldQuery()->pluck('users.id')->all())->toBe([$user->id]);
    });

    it('does not follow when a person chose the role', function () {
        $tenant = createTenant();
        $creator = actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);
        $employee = Employee::factory()->create(['tenant_id' => $tenant->id, 'position_id' => titleOf($tenant, 'dept_admin')->id, 'email' => 'chosen@acme.test']);

        $user = app(UserProvisioningService::class)->provision(
            email: 'chosen@acme.test', role: UserRole::SUPERVISOR, employee: $employee, invitedBy: $creator->id, sendActivation: false,
        );

        expect($user->fresh()->role)->toBe(UserRole::SUPERVISOR)->and($user->fresh()->role_follows_position)->toBeFalse();
    });

    it('follows when the add-employee form names no role, and not when it does', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
        $position = titleOf($tenant, 'supervisor');
        $body = fn (string $email, string $code) => [
            'name' => 'Person '.$code, 'email' => $email, 'employee_code' => $code, 'gender' => 'male',
            'date_of_birth' => '1990-03-15', 'nationality' => 'Ethiopian', 'marital_status' => 'single',
            'hire_date' => '2024-01-15', 'position_id' => $position->public_id, 'create_login' => true,
        ];

        $this->postJson('/api/v1/employees', $body('a@acme.test', 'EMP-9001'))->assertCreated();
        $this->postJson('/api/v1/employees', $body('b@acme.test', 'EMP-9002') + ['user_role' => 'employee'])->assertCreated();

        $a = User::where('email', 'a@acme.test')->first();
        $b = User::where('email', 'b@acme.test')->first();
        expect($a->role)->toBe(UserRole::SUPERVISOR)->and($a->role_follows_position)->toBeTrue()
            ->and($b->role)->toBe(UserRole::EMPLOYEE)->and($b->role_follows_position)->toBeFalse();
    });
});
