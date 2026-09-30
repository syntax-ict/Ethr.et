<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\AccountActivationNotification;
use App\Notifications\PasswordResetLinkNotification;

/*
 * The reset and activation emails were built from config('app.frontend_url'),
 * a key no config file defined, with 'http://localhost:3000' as the fallback.
 * In production every such email therefore linked to a host no user can reach.
 */

function mailActionUrl(object $notification, User $user): string
{
    return $notification->toMail($user)->actionUrl;
}

it('links password reset mail to the configured frontend url', function () {
    config(['app.frontend_url' => 'https://www.ethr.test']);
    $user = User::factory()->make(['email' => 'a@acme.test']);

    $url = mailActionUrl(new PasswordResetLinkNotification('tok123', 'acme'), $user);

    expect($url)->toStartWith('https://www.ethr.test/login/reset?token=tok123')
        ->and($url)->toContain('email=a%40acme.test')
        ->and($url)->toContain('tenant=acme');
});

it('links activation mail to the configured frontend url', function () {
    config(['app.frontend_url' => 'https://www.ethr.test/']);
    $user = User::factory()->make(['email' => 'a@acme.test']);

    $url = mailActionUrl(new AccountActivationNotification('tok123', 'acme', 'Acme Ltd'), $user);

    expect($url)->toStartWith('https://www.ethr.test/login/reset?token=tok123');
});

it('resolves to the application url, never localhost:3000, outside a local environment', function () {
    // phpunit.xml pins FRONTEND_URL to blank and APP_ENV to testing: the
    // production shape. It is pinned rather than assumed because CI builds its
    // .env from .env.example, which sets FRONTEND_URL for local development —
    // the first version of this test read the ambient value and failed there.
    expect(env('FRONTEND_URL'))->toBeEmpty()
        ->and(app()->environment('local'))->toBeFalse()
        ->and(config('app.frontend_url'))->toBe(config('app.url'))
        ->and(config('app.frontend_url'))->not->toContain('localhost:3000');
});
