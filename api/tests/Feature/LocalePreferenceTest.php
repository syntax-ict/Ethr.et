<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;

/*
 * Review 2026-10-30, D1: a language chosen in the header or on Preferences was
 * stored on the user and never applied on another device, so a user who chose
 * English signed in on a new phone to their organisation's default. The app
 * cannot apply `users.locale` as it stands: the column defaults to "en" and
 * several sign-up paths write "en", so it cannot tell a choice from a default.
 * Saving a language now records that it was chosen, and /auth/me says so.
 */

function d1Url(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

test('a user who never chose a language is not reported as having chosen one', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->getJson(d1Url($tenant, '/auth/me'))
        ->assertOk()
        ->assertJsonPath('user.preferences.locale', 'en')
        ->assertJsonPath('user.preferences.locale_chosen', false);
});

test('saving a language records that it was chosen', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->putJson(d1Url($tenant, '/profile/preferences'), ['locale' => 'am'])
        ->assertOk()
        ->assertJsonPath('locale', 'am')
        ->assertJsonPath('locale_chosen', true);

    test()->getJson(d1Url($tenant, '/auth/me'))
        ->assertOk()
        ->assertJsonPath('user.preferences.locale', 'am')
        ->assertJsonPath('user.preferences.locale_chosen', true);
});

test('saving another preference does not mark the language as chosen', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->putJson(d1Url($tenant, '/profile/preferences'), ['calendar' => 'gregorian'])
        ->assertOk()
        ->assertJsonPath('locale_chosen', false);
});

test('a later change of another preference keeps the language choice', function () {
    $tenant = createTenant();
    $user = actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->putJson(d1Url($tenant, '/profile/preferences'), ['locale' => 'en'])->assertOk();
    test()->putJson(d1Url($tenant, '/profile/preferences'), ['theme' => 'dark'])
        ->assertOk()
        ->assertJsonPath('locale_chosen', true);

    expect($user->fresh()->preferences)->toMatchArray(['locale_chosen' => true, 'theme' => 'dark']);
});
