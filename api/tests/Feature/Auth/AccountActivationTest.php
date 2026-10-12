<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\UserProvisioningService;
use App\Support\PasswordTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

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

/*
 * Production QA 2026-10-11, P11: invitation links expired after 60 minutes —
 * the password-reset lifetime — so an invitee who opened the email the next
 * day could not activate. Invitations now come from their own broker.
 */
describe('invitation link lifetime', function () {
    function p11Invitee(): User
    {
        $tenant = createTenant(['subdomain' => 'acme']);

        return User::factory()->invited()->create([
            'tenant_id' => $tenant->id,
            'email' => 'invitee@acme.test',
        ]);
    }

    function p11SetPassword(string $token): TestResponse
    {
        return test()->postJson('/api/v1/auth/password/reset', [
            'tenant' => 'acme',
            'token' => $token,
            'email' => 'invitee@acme.test',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
        ]);
    }

    it('still activates the next day', function () {
        $user = p11Invitee();
        $token = PasswordTokens::activationBroker()->createToken($user);

        $this->travel(48)->hours();

        p11SetPassword($token)->assertOk();
        expect($user->refresh()->status)->toBe('active');
    });

    it('expires after 72 hours', function () {
        $user = p11Invitee();
        $token = PasswordTokens::activationBroker()->createToken($user);

        $this->travel(73)->hours();

        p11SetPassword($token)->assertUnprocessable()
            ->assertJsonPath('type', 'https://ethr.et/errors/invalid-token');
    });

    it('sends the invitation from the activation broker, not the reset broker', function () {
        Notification::fake();
        $user = p11Invitee();

        app(UserProvisioningService::class)->sendActivationLink($user);

        expect(DB::table('account_activation_tokens')->where('email', 'invitee@acme.test')->exists())->toBeTrue();
        expect(DB::table('password_reset_tokens')->where('email', 'invitee@acme.test')->exists())->toBeFalse();
    });

    it('leaves password-reset links at 60 minutes', function () {
        $user = p11Invitee();
        $user->update(['status' => 'active']);
        $token = PasswordTokens::broker()->createToken($user);

        $this->travel(61)->minutes();

        p11SetPassword($token)->assertUnprocessable();
    });
});
