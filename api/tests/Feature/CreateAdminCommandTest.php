<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('creates a super admin non-interactively', function () {
    $this->artisan('ethr:create-admin', [
        '--email' => 'admin@ethr.et',
        '--password' => 'change-me-please',
    ])->assertExitCode(0);

    $admin = User::withoutGlobalScopes()->where('email', 'admin@ethr.et')->firstOrFail();

    expect($admin->tenant_id)->toBeNull();
    expect($admin->role)->toBe(UserRole::SUPER_ADMIN);
    expect($admin->status)->toBe('active');
    expect(Hash::check('change-me-please', $admin->password))->toBeTrue();
});

test('rejects a password shorter than 12 characters', function () {
    $this->artisan('ethr:create-admin', [
        '--email' => 'admin@ethr.et',
        '--password' => 'short',
    ])->assertExitCode(1);

    expect(User::withoutGlobalScopes()->where('email', 'admin@ethr.et')->exists())->toBeFalse();
});

test('updates the password of an existing super admin with --force', function () {
    $this->artisan('ethr:create-admin', [
        '--email' => 'admin@ethr.et',
        '--password' => 'first-password-123',
    ])->assertExitCode(0);

    $this->artisan('ethr:create-admin', [
        '--email' => 'admin@ethr.et',
        '--password' => 'second-password-456',
        '--force' => true,
    ])->assertExitCode(0);

    $admin = User::withoutGlobalScopes()->where('email', 'admin@ethr.et')->firstOrFail();
    expect(Hash::check('second-password-456', $admin->password))->toBeTrue();
    expect(User::withoutGlobalScopes()->where('email', 'admin@ethr.et')->count())->toBe(1);
});
