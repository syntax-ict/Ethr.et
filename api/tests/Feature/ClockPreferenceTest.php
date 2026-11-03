<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ClockPreference;

/*
 * Ethiopian time of day (06:00 = 12:00 ጠዋት) is a display preference, set and
 * inherited exactly like the calendar (CalendarPreferenceTest): the user's
 * choice, else the organisation's, else `auto`. `auto` is decided by whoever
 * writes the time, in the language they write it in.
 */

function clockUrl(Tenant $tenant, string $path): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1{$path}";
}

function clockAdmin(array $settings = []): array
{
    $tenant = createTenant(['settings' => $settings]);
    $user = actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

    return [$tenant, $user];
}

test('a user and organisation that never chose are on auto', function () {
    [$tenant] = clockAdmin();

    test()->getJson(clockUrl($tenant, '/auth/me'))
        ->assertOk()
        ->assertJsonPath('user.preferences.clock', 'auto');

    test()->getJson(clockUrl($tenant, '/settings'))
        ->assertOk()
        ->assertJsonPath('display.clock', 'auto');
});

test('a user who has not chosen inherits the organisation clock', function () {
    [$tenant] = clockAdmin(['clock' => 'ethiopian']);

    test()->getJson(clockUrl($tenant, '/auth/me'))
        ->assertJsonPath('user.preferences.clock', 'ethiopian');
});

test("a user's own choice overrides the organisation clock, and auto hands it back", function () {
    [$tenant] = clockAdmin(['clock' => 'ethiopian']);

    test()->putJson(clockUrl($tenant, '/profile/preferences'), ['clock' => '24h'])
        ->assertOk()
        ->assertJsonPath('clock', '24h');

    test()->putJson(clockUrl($tenant, '/profile/preferences'), ['clock' => 'auto'])
        ->assertOk()
        ->assertJsonPath('clock', 'ethiopian');
});

test('saving the clock keeps the calendar', function () {
    [$tenant] = clockAdmin();

    test()->putJson(clockUrl($tenant, '/profile/preferences'), ['calendar' => 'gregorian'])->assertOk();
    test()->putJson(clockUrl($tenant, '/profile/preferences'), ['clock' => 'ethiopian'])
        ->assertOk()
        ->assertJsonPath('calendar', 'gregorian')
        ->assertJsonPath('clock', 'ethiopian');
});

test('the organisation clock is saved through PUT /settings', function () {
    [$tenant] = clockAdmin();

    test()->putJson(clockUrl($tenant, '/settings'), ['settings' => ['clock' => 'ethiopian']])
        ->assertOk();

    expect($tenant->fresh()->settings['clock'])->toBe('ethiopian');
    test()->getJson(clockUrl($tenant, '/settings'))->assertJsonPath('display.clock', 'ethiopian');
});

test('only a clock the app can show is accepted', function (string $value) {
    [$tenant] = clockAdmin();

    test()->putJson(clockUrl($tenant, '/settings'), ['settings' => ['clock' => $value]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['settings.clock']);
    test()->putJson(clockUrl($tenant, '/profile/preferences'), ['clock' => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['clock']);
})->with(['12h', 'ethiopic', 'AUTO']);

test('auto is Ethiopian time only for Amharic on the Ethiopian calendar', function (string $preference, string $calendar, string $locale, string $clock) {
    expect(ClockPreference::resolve($preference, $calendar, $locale))->toBe($clock);
})->with([
    ['auto', 'ethiopian', 'am', 'ethiopian'],
    ['auto', 'ethiopian', 'en', '24h'],
    ['auto', 'gregorian', 'am', '24h'],
    ['ethiopian', 'gregorian', 'en', 'ethiopian'],
    ['24h', 'ethiopian', 'am', '24h'],
]);

test('a user with no organisation is on auto', function () {
    $platformUser = User::factory()->make(['tenant_id' => null, 'preferences' => null]);

    expect(ClockPreference::forUser($platformUser))->toBe('auto');
});
