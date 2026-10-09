<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CalendarPreference;

/*
 * Audit N33: the calendar preference was saved three ways and applied in none.
 * The profile's choice was stored and never read; a user who had not chosen was
 * told "gregorian" while the app displayed Ethiopian; and General Settings'
 * "Calendar System" only flipped the admin's own browser — `PUT /settings` did
 * not accept the key at all.
 *
 * The model now: the organisation sets a default (`tenants.settings.calendar`),
 * a user's own choice overrides it, and with neither the answer is Ethiopian.
 */

function n33Url(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

function n33Admin(array $settings = []): array
{
    $tenant = createTenant(['settings' => $settings]);
    // Enrolled, so no stored MFA policy confines the admin.
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

    return [$tenant, $user];
}

test('a user who has not chosen sees Ethiopian when the organisation has not either', function () {
    [$tenant] = n33Admin();

    test()->getJson(n33Url($tenant, '/auth/me'))
        ->assertOk()
        ->assertJsonPath('user.preferences.calendar', 'ethiopian');

    test()->getJson(n33Url($tenant, '/profile'))
        ->assertOk()
        ->assertJsonPath('preferences.calendar', 'ethiopian');
});

test('a user who has not chosen inherits the organisation calendar', function () {
    [$tenant] = n33Admin(['calendar' => 'gregorian']);

    test()->getJson(n33Url($tenant, '/auth/me'))
        ->assertOk()
        ->assertJsonPath('user.preferences.calendar', 'gregorian');
});

test("a user's own choice overrides the organisation calendar", function () {
    [$tenant, $user] = n33Admin(['calendar' => 'gregorian']);
    $user->update(['preferences' => ['calendar' => 'ethiopian']]);

    test()->getJson(n33Url($tenant, '/auth/me'))
        ->assertOk()
        ->assertJsonPath('user.preferences.calendar', 'ethiopian');
});

test('a stored dual choice is still read back as stored', function () {
    // The API keeps accepting `dual` so nothing already saved breaks; the
    // frontend shows it as Ethiopian until a dual display exists.
    [$tenant] = n33Admin(['calendar' => 'gregorian']);

    test()->putJson(n33Url($tenant, '/profile/preferences'), ['calendar' => 'dual'])
        ->assertOk()
        ->assertJsonPath('calendar', 'dual');
});

test('the organisation calendar is saved through PUT /settings and read back', function () {
    [$tenant] = n33Admin();

    test()->putJson(n33Url($tenant, '/settings'), ['settings' => ['calendar' => 'gregorian']])
        ->assertOk();

    expect($tenant->fresh()->settings['calendar'])->toBe('gregorian');

    test()->getJson(n33Url($tenant, '/settings'))
        ->assertOk()
        ->assertJsonPath('display.calendar', 'gregorian');

    // ...and reaches everyone who has not chosen their own.
    test()->getJson(n33Url($tenant, '/auth/me'))
        ->assertJsonPath('user.preferences.calendar', 'gregorian');
});

test('GET /settings shows Ethiopian for an organisation that never chose', function () {
    [$tenant] = n33Admin();

    test()->getJson(n33Url($tenant, '/settings'))
        ->assertOk()
        ->assertJsonPath('display.calendar', 'ethiopian');
});

test('the organisation calendar accepts only a calendar the app can display', function (string $value) {
    [$tenant] = n33Admin();

    test()->putJson(n33Url($tenant, '/settings'), ['settings' => ['calendar' => $value]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings.calendar']);

    expect($tenant->fresh()->settings ?? [])->not->toHaveKey('calendar');
})->with(['dual', 'julian', '']);

test('a refused calendar is named in words, in both languages', function (string $locale, string $name) {
    [$tenant] = n33Admin();
    app()->setLocale($locale);

    $message = test()->withHeader('Accept-Language', $locale)
        ->putJson(n33Url($tenant, '/settings'), ['settings' => ['calendar' => 'julian']])
        ->assertStatus(422)
        ->json('errors')['settings.calendar'][0];

    expect($message)->toContain($name)
        ->and($message)->not->toContain('settings.');
})->with([
    'English' => ['en', 'calendar system'],
    'Amharic' => ['am', 'የቀን መቁጠሪያ ሥርዓት'],
]);

test('only someone who manages settings can change the organisation calendar', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->putJson(n33Url($tenant, '/settings'), ['settings' => ['calendar' => 'gregorian']])
        ->assertForbidden();

    expect($tenant->fresh()->settings ?? [])->not->toHaveKey('calendar');
});

test('a user with no organisation is shown Ethiopian', function () {
    $platformUser = User::factory()->make(['tenant_id' => null, 'preferences' => null]);

    expect(CalendarPreference::forUser($platformUser))->toBe('ethiopian');
});
