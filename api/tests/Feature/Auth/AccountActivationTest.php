<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Password;

describe('invited account activation', function () {
    it('blocks login for an invited account until it is activated', function () {
        $tenant = createTenant(['subdomain' => 'acme']);
        User::factory()->invited()->create([
            'tenant_id' => $tenant->id,
            'email' => 'invitee@acme.test',
        ]);

        // Credentials are valid (factory password) but the account is not active.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'invitee@acme.test',
            'password' => 'password',
            'tenant' => 'acme',
        ])->assertForbidden();
    });

    it('activates the account when the invitee sets a password via the reset flow', function () {
        $tenant = createTenant(['subdomain' => 'acme']);
        $user = User::factory()->invited()->create([
            'tenant_id' => $tenant->id,
            'email' => 'invitee@acme.test',
        ]);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'tenant' => 'acme',
            'token' => $token,
            'email' => 'invitee@acme.test',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ])->assertOk();

        $user->refresh();
        expect($user->status)->toBe('active');
        expect($user->activated_at)->not->toBeNull();
        expect($user->email_verified_at)->not->toBeNull();

        // The account can now sign in with the freshly-set password.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', [
            'email' => 'invitee@acme.test',
            'password' => 'new-secret-123',
            'tenant' => 'acme',
        ])->assertOk()->assertJsonStructure(['access_token']);
    });
});
