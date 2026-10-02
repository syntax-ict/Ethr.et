<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Tenant;

/*
 * Audit N6: `UpdateSettingsRequest` validated three keys and accepted any
 * other, merging it into the tenant's settings JSON. That JSON also holds keys
 * other code acts on — `login_identifiers` decides how users sign in,
 * `password_policy` sets password rules — and each was writable here,
 * unvalidated, by anyone holding settings.manage.
 */

function n6SettingsAdmin(array $settings = []): Tenant
{
    $tenant = createTenant(['settings' => $settings]);
    // Enrolled, so a stored "required" policy does not confine the admin
    // reading it back.
    actingAsUser(['role' => UserRole::TENANT_ADMIN, 'mfa_enabled' => true], $tenant);

    return $tenant;
}

function n6SettingsUrl(Tenant $tenant): string
{
    return "http://{$tenant->subdomain}.ethr.test/api/v1/settings";
}

test('an unknown key is refused and nothing is stored', function () {
    $tenant = n6SettingsAdmin();

    test()->putJson(n6SettingsUrl($tenant), ['settings' => [
        'run_day' => 20,
        'grace_period_minutes' => 10,
    ]])->assertUnprocessable()->assertJsonValidationErrors(['settings.grace_period_minutes']);

    expect($tenant->fresh()->settings)->not->toHaveKey('grace_period_minutes')
        ->and($tenant->fresh()->settings)->not->toHaveKey('run_day');
});

test('keys owned by other endpoints cannot be written through PUT /settings', function (string $key, mixed $value) {
    $tenant = n6SettingsAdmin();

    test()->putJson(n6SettingsUrl($tenant), ['settings' => [$key => $value]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["settings.{$key}"]);

    expect($tenant->fresh()->settings ?? [])->not->toHaveKey($key);
})->with([
    'login identifiers' => ['login_identifiers', ['employee_number']],
    'password policy' => ['password_policy', ['min_length' => 1]],
    'notification templates' => ['notification_templates', []],
]);

test('the security settings are validated', function (array $settings, string $error) {
    $tenant = n6SettingsAdmin();

    test()->putJson(n6SettingsUrl($tenant), ['settings' => $settings])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$error]);
})->with([
    'unknown policy' => [['mfa_policy' => 'sometimes'], 'settings.mfa_policy'],
    'timeout too short' => [['session_timeout_minutes' => 2], 'settings.session_timeout_minutes'],
    'timeout past the session lifetime' => [['session_timeout_minutes' => 481], 'settings.session_timeout_minutes'],
    'timeout not a number' => [['session_timeout_minutes' => 'thirty'], 'settings.session_timeout_minutes'],
    'run day past 28' => [['run_day' => 31], 'settings.run_day'],
    'working day out of range' => [['working_days' => [1, 8]], 'settings.working_days.1'],
]);

test('valid settings are stored and read back as enforced', function () {
    $tenant = n6SettingsAdmin();

    test()->putJson(n6SettingsUrl($tenant), ['settings' => [
        'mfa_policy' => 'required',
        'session_timeout_minutes' => 30,
        'run_day' => 20,
        'working_days' => [1, 2, 3, 4, 5, 6],
    ]])->assertOk();

    test()->getJson(n6SettingsUrl($tenant))
        ->assertOk()
        ->assertJsonPath('security.mfa_policy', 'required')
        ->assertJsonPath('security.session_timeout_minutes', 30)
        ->assertJsonPath('payroll.run_day', 20)
        ->assertJsonPath('leave.working_days', [1, 2, 3, 4, 5, 6]);
});

test('a value stored before validation existed is shown as it is enforced', function () {
    $tenant = n6SettingsAdmin(['mfa_policy' => 'Required ', 'session_timeout_minutes' => 'never']);

    test()->getJson(n6SettingsUrl($tenant))
        ->assertOk()
        ->assertJsonPath('security.mfa_policy', 'required')
        ->assertJsonPath('security.session_timeout_minutes', 480);
});
