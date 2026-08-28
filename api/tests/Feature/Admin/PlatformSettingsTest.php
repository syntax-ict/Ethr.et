<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\PlatformSetting;

/**
 * Platform settings hold the bank account every tenant pays ETHR into. Two things
 * matter: only the platform operator can change it, and tenants can read it without
 * it ever having been hardcoded into the client.
 */
it('lets a super admin read the platform settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->getJson('/api/v1/admin/platform-settings')
        ->assertOk()
        ->assertJsonPath('is_configured', false)
        ->assertJsonStructure(['public_id', 'bank_name', 'bank_account_number', 'is_configured']);
});

it('lets a super admin set the payment details', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', [
        'bank_name' => 'Commercial Bank of Ethiopia',
        'bank_account_number' => '1000 5555 6666 77',
        'bank_account_name' => 'ETHR Technologies PLC',
    ])
        ->assertOk()
        ->assertJsonPath('is_configured', true)
        ->assertJsonPath('bank_account_name', 'ETHR Technologies PLC');

    expect(PlatformSetting::current()->bank_name)->toBe('Commercial Bank of Ethiopia');
});

it('records an audit entry when the payment destination changes', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', [
        'bank_name' => 'Awash Bank',
        'bank_account_number' => '0100 2222 3333',
        'bank_account_name' => 'ETHR Technologies PLC',
    ])->assertOk();

    $this->assertDatabaseHas('audit_log', ['action' => 'platform.settings.updated']);
});

it('rejects an account number that is not digits', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', [
        'bank_account_number' => 'ask finance for it',
    ])->assertStatus(422)->assertJsonPath('errors.bank_account_number.0', fn ($m) => is_string($m));

    expect(PlatformSetting::current()->bank_account_number)->toBeNull();
});

it('forbids a tenant admin from reading or changing platform settings', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->getJson('/api/v1/admin/platform-settings')->assertForbidden();

    $this->putJson('/api/v1/admin/platform-settings', [
        'bank_account_number' => '9999 9999 9999',
    ])->assertForbidden();

    expect(PlatformSetting::current()->bank_account_number)->toBeNull();
});

it('exposes the payment details to a tenant through the billing dashboard', function () {
    $tenant = createTenant();

    PlatformSetting::current()->update([
        'bank_name' => 'Commercial Bank of Ethiopia',
        'bank_account_number' => '1000 5555 6666 77',
        'bank_account_name' => 'ETHR Technologies PLC',
    ]);

    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->getJson('/api/v1/billing/dashboard')
        ->assertOk()
        ->assertJsonPath('payment_details.bank_name', 'Commercial Bank of Ethiopia')
        ->assertJsonPath('payment_details.account_number', '1000 5555 6666 77');
});

it('sends no payment details at all until an operator has configured them', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    // Half-filled bank details on the screen that tells customers where to wire
    // money are worse than none, so the payload stays null until it is complete.
    PlatformSetting::current()->update(['bank_name' => 'Commercial Bank of Ethiopia']);

    $this->getJson('/api/v1/billing/dashboard')
        ->assertOk()
        ->assertJsonPath('payment_details', null);
});

it('reports trial state so a trialling tenant is not shown an empty plan', function () {
    $tenant = createTenant(['trial_ends_at' => now()->addDays(30)]);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->getJson('/api/v1/billing/dashboard')
        ->assertOk()
        ->assertJsonPath('trial_days_remaining', 30)
        ->assertJsonPath('tenant_status', $tenant->status->value);
});
