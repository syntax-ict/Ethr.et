<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\OrganisationRegisteredNotification;
use Illuminate\Support\Facades\Notification;

/*
 * Production QA 2026-10-11.
 *
 * P2: a newly registered admin was signed out about 15 minutes in, mid-setup.
 * Registration issued only the 15-minute bearer token; unlike a password
 * sign-in it never called Auth::login(), so once the token expired there was
 * no session to carry the browser and /auth/refresh (behind auth:sanctum)
 * could not renew it.
 *
 * P7: registration sent no email at all, so the owner of the address was never
 * told an organisation had been opened in their name.
 */

function p2Register(): void
{
    test()->withHeaders(['Origin' => 'http://localhost:3000'])
        ->withCredentials()
        ->postJson('/api/v1/auth/register', [
            'organization_name' => 'Acme Corp',
            'subdomain' => 'acme-corp',
            'admin_name' => 'Admin User',
            'admin_email' => 'admin@acme.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'organization_type' => 'general',
        ])
        ->assertCreated();

    app('auth')->forgetGuards();
}

test('a newly registered admin is still signed in after the token expires', function () {
    p2Register();

    $this->travel(16)->minutes();

    test()->withHeaders(['Origin' => 'http://localhost:3000', 'X-Tenant' => 'acme-corp'])
        ->withCredentials()
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.email', 'admin@acme.com');
});

test('registering emails the address it was opened with', function () {
    Notification::fake();

    p2Register();

    $admin = User::withoutGlobalScopes()->where('email', 'admin@acme.com')->firstOrFail();

    Notification::assertSentTo($admin, OrganisationRegisteredNotification::class);
});

test('the registration email names the organisation and its sign-in address', function () {
    Notification::fake();

    p2Register();

    $admin = User::withoutGlobalScopes()->where('email', 'admin@acme.com')->firstOrFail();

    Notification::assertSentTo($admin, OrganisationRegisteredNotification::class, function ($notification) use ($admin): bool {
        $mail = $notification->toMail($admin);
        $text = implode("\n", array_merge($mail->introLines, $mail->outroLines));

        return str_contains($mail->subject, 'Acme Corp')
            && str_contains((string) $mail->actionUrl, 'acme-corp')
            && str_contains($text, 'Acme Corp');
    });
});
