<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\PlatformSetting;

/**
 * The "Powered by ETHR" mark at the foot of every organisation's sidebar.
 *
 * It is the platform's mark, shown inside organisations that do not own it, so
 * two things matter: the platform operator decides it in the console, and no
 * organisation can switch it off or reword it from anything it can reach.
 */
it('shows the mark by default, so the column changes nothing until an operator acts', function () {
    $content = $this->getJson('/api/v1/site-content')->assertOk()->json('data');

    expect($content['product_mark_enabled'])->toBeTrue()
        ->and($content['product_mark_label'])->toBeNull()
        ->and($content['product_mark_label_am'])->toBeNull()
        ->and($content['product_mark_url'])->toBeNull();
});

it('reports the mark as on from the very first read of the console', function () {
    // `current()` inserts the row on first read; a freshly inserted model holds
    // only what it was given, which is the case the model's default covers.
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->getJson('/api/v1/admin/platform-settings')
        ->assertOk()
        ->assertJsonPath('product_mark_enabled', true);
});

it('lets a platform admin turn the mark off and reword it', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', [
        'product_mark_enabled' => false,
        'product_mark_label' => 'Built on ETHR',
        'product_mark_label_am' => 'በETHR የተሰራ',
        'product_mark_url' => 'https://www.ethr.et/features',
    ])
        ->assertOk()
        ->assertJsonPath('product_mark_enabled', false)
        ->assertJsonPath('product_mark_label', 'Built on ETHR');

    // Every organisation reads it from the public payload, and an edit reaches
    // it at once: the model's saved hook clears the cache.
    $content = $this->getJson('/api/v1/site-content')->assertOk()->json('data');
    expect($content['product_mark_enabled'])->toBeFalse()
        ->and($content['product_mark_label_am'])->toBe('በETHR የተሰራ')
        ->and($content['product_mark_url'])->toBe('https://www.ethr.et/features');

    $this->assertDatabaseHas('audit_log', ['action' => 'platform.settings.updated']);
});

it('turns the mark back on', function () {
    PlatformSetting::current()->update(['product_mark_enabled' => false]);

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', ['product_mark_enabled' => true])
        ->assertOk();

    expect($this->getJson('/api/v1/site-content')->json('data.product_mark_enabled'))->toBeTrue();
});

it('stores a cleared label and link as null, the built-in wording and site', function () {
    PlatformSetting::current()->update([
        'product_mark_label' => 'Built on ETHR',
        'product_mark_url' => 'https://www.ethr.et/features',
    ]);

    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', [
        'product_mark_label' => '',
        'product_mark_url' => '',
    ])->assertOk();

    $settings = PlatformSetting::current();
    expect($settings->product_mark_label)->toBeNull()
        ->and($settings->product_mark_url)->toBeNull();
});

it('refuses a link that is not https and a label too long for the row', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', [
        'product_mark_url' => 'http://www.ethr.et',
    ])->assertStatus(422)->assertJsonValidationErrors('product_mark_url');

    $this->putJson('/api/v1/admin/platform-settings', [
        'product_mark_label' => str_repeat('a', 61),
    ])->assertStatus(422)->assertJsonValidationErrors('product_mark_label');

    $this->putJson('/api/v1/admin/platform-settings', [
        'product_mark_enabled' => 'sometimes',
    ])->assertStatus(422)->assertJsonValidationErrors('product_mark_enabled');
});

it('does not let anyone in an organisation change the mark', function (UserRole $role) {
    $tenant = createTenant();
    actingAsUser(['role' => $role, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', [
        'product_mark_enabled' => false,
        'product_mark_label' => 'Our own HR',
    ])->assertForbidden();

    $settings = PlatformSetting::current();
    expect($settings->product_mark_enabled)->toBeTrue()
        ->and($settings->product_mark_label)->toBeNull();
})->with([
    'tenant admin' => [UserRole::TENANT_ADMIN],
    'hr admin' => [UserRole::HR_ADMIN],
    'employee' => [UserRole::EMPLOYEE],
]);

it('ignores the mark on the organisation\'s own branding settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // The organisation's branding endpoint accepts its colours and logo; the
    // mark is not among them, so the keys are dropped rather than written to a
    // row the organisation does not own.
    $this->putJson('/api/v1/settings/branding', [
        'primary_color' => '#0a2e4a',
        'product_mark_enabled' => false,
        'product_mark_label' => 'Our own HR',
    ])->assertOk();

    expect(PlatformSetting::current()->product_mark_enabled)->toBeTrue()
        ->and(PlatformSetting::current()->product_mark_label)->toBeNull();
});
