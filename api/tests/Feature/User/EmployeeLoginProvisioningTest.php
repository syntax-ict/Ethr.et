<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use Illuminate\Support\Facades\Notification;

describe('employee create → login provisioning', function () {
    it('provisions an invited login when create_login is set and an email exists', function () {
        Notification::fake();
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->postJson('/api/v1/employees', [
            'name' => 'Abebe Kebede',
            'email' => 'abebe@acme.test',
            'hire_date' => '2026-01-01',
            'create_login' => true,
            'user_role' => 'employee',
        ]);

        $response->assertCreated()->assertJsonPath('meta.login_invited', true);

        $user = User::where('email', 'abebe@acme.test')->first();
        expect($user)->not->toBeNull();
        expect($user->status)->toBe('invited');
        expect($user->employee_id)->not->toBeNull();

        Notification::assertSentTo($user, AccountActivationNotification::class);
    });

    it('does not provision a login when the employee has no email', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'No Email Worker',
            'hire_date' => '2026-01-01',
            'create_login' => true,
        ])->assertCreated()->assertJsonPath('meta.login_invited', false);
    });

    it('does not provision a login by default', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'Plain Employee',
            'email' => 'plain@acme.test',
            'hire_date' => '2026-01-01',
        ])->assertCreated()->assertJsonPath('meta.login_invited', false);

        expect(User::where('email', 'plain@acme.test')->exists())->toBeFalse();
    });

    it('clamps a requested login role to employee when it exceeds the creator level', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'Sneaky Promote',
            'email' => 'sneaky@acme.test',
            'hire_date' => '2026-01-01',
            'create_login' => true,
            'user_role' => 'tenant_admin',
        ])->assertCreated();

        $user = User::where('email', 'sneaky@acme.test')->first();
        expect($user->role)->toBe(UserRole::EMPLOYEE);
    });

    // Production, 2026-10-12 (attendance QA A7): an organisation's first admin
    // registers before any employee exists, and nothing could link the two —
    // so they could not check in by any route.
    it('links the creator\'s own account when they add themselves with create_login', function () {
        Notification::fake();
        $tenant = createTenant();
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN, 'email' => 'owner@acme.test'], $tenant);

        $response = $this->postJson('/api/v1/employees', [
            'name' => 'The Owner',
            'email' => 'Owner@acme.test',
            'hire_date' => '2026-01-01',
            'create_login' => true,
            'user_role' => 'employee',
        ])->assertCreated()->assertJsonPath('meta.login_invited', false);

        $admin->refresh();
        expect($admin->employee?->public_id)->toBe($response->json('public_id'))
            ->and($admin->role)->toBe(UserRole::TENANT_ADMIN)
            ->and(User::where('email', 'owner@acme.test')->count())->toBe(1);

        Notification::assertNothingSent();
        $this->assertDatabaseHas('audit_log', ['action' => 'user.employee_linked', 'auditable_id' => $admin->id]);

        $this->postJson('/api/v1/attendance/check-in', ['idempotency_key' => 'first-admin-in'])
            ->assertCreated();
    });

    it('does not link an account that already has an employee, or one above the creator', function (UserRole $role, bool $hasEmployee) {
        $tenant = createTenant();
        $other = createUser([
            'role' => $role,
            'email' => 'taken@acme.test',
            'employee_id' => $hasEmployee ? Employee::factory()->create(['tenant_id' => $tenant->id])->id : null,
        ], $tenant);
        $before = $other->employee_id;
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'Same Address',
            'email' => 'taken@acme.test',
            'hire_date' => '2026-01-01',
            'create_login' => true,
        ])->assertCreated();

        expect($other->refresh()->employee_id)->toBe($before);
        $this->assertDatabaseMissing('audit_log', ['action' => 'user.employee_linked']);
    })->with([
        'already linked' => [UserRole::EMPLOYEE, true],
        'above the creator' => [UserRole::TENANT_ADMIN, false],
    ]);

    it('does not link without create_login', function () {
        $tenant = createTenant();
        $admin = actingAsUser(['role' => UserRole::TENANT_ADMIN, 'email' => 'owner@acme.test'], $tenant);

        $this->postJson('/api/v1/employees', [
            'name' => 'The Owner',
            'email' => 'owner@acme.test',
            'hire_date' => '2026-01-01',
        ])->assertCreated();

        expect($admin->refresh()->employee_id)->toBeNull();
    });
});

describe('employee CSV import → login provisioning', function () {
    it('creates invited logins only for imported rows that carry an email', function () {
        Notification::fake();
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $response = $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'imp_test_1',
            'create_logins' => true,
            'rows' => [
                ['name' => 'With Email', 'email' => 'we@acme.test', 'hire_date' => '2026-01-01', 'employee_code' => 'E1'],
                ['name' => 'No Email', 'hire_date' => '2026-01-01', 'employee_code' => 'E2'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('created', 2)
            ->assertJsonPath('users_created', 1);

        expect(User::where('email', 'we@acme.test')->where('status', 'invited')->exists())->toBeTrue();
    });

    it('does not create logins when create_logins is omitted', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

        $this->postJson('/api/v1/employees/import/commit', [
            'import_key' => 'imp_test_2',
            'rows' => [
                ['name' => 'With Email', 'email' => 'noinvite@acme.test', 'hire_date' => '2026-01-01', 'employee_code' => 'E9'],
            ],
        ])->assertCreated()->assertJsonPath('users_created', 0);

        expect(User::where('email', 'noinvite@acme.test')->exists())->toBeFalse();
    });
});
