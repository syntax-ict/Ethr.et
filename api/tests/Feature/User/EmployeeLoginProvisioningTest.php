<?php

declare(strict_types=1);

use App\Enums\UserRole;
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
