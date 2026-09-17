<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\PlatformSetting;

/**
 * Super-admin editing of the facts the public site states about ETHR.
 *
 * Same surface and same audit entry as the bank details — these columns were
 * added to `platform_settings` rather than to a new table precisely so they
 * inherit the permission, the audit and the tenant-isolation decision already
 * made for it.
 */
it('lets a super admin set the contact details the public site shows', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', [
        'platform_name' => 'ETHR',
        'tagline' => 'HR for Ethiopian organisations',
        'tagline_am' => 'ለኢትዮጵያ ድርጅቶች የሰው ሃብት',
        'contact_email' => 'hello@ethr.et',
        'contact_phone' => '+251 11 000 0000',
        'office_address' => 'Addis Ababa, Ethiopia',
    ])->assertOk();

    $settings = PlatformSetting::current();
    expect($settings->contact_email)->toBe('hello@ethr.et')
        ->and($settings->tagline_am)->toBe('ለኢትዮጵያ ድርጅቶች የሰው ሃብት');

    $this->assertDatabaseHas('audit_log', ['action' => 'platform.settings.updated']);
});

it('refuses a logo or social link that is not https', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    // Every consumer renders these into an <img src> or an <a href> on an HTTPS
    // page, so http:// is a mixed-content warning at best and a blocked image
    // at worst.
    $this->putJson('/api/v1/admin/platform-settings', [
        'logo_url' => 'http://example.com/logo.png',
    ])->assertStatus(422);

    $this->putJson('/api/v1/admin/platform-settings', [
        'social_linkedin' => 'not a url',
    ])->assertStatus(422);

    expect(PlatformSetting::current()->logo_url)->toBeNull();
});

it('accepts a published metric and reports it as published', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', [
        'metric_organisations' => 12,
    ])->assertOk();

    // Twelve is a number somebody can stand behind. The point of the column is
    // that it starts empty rather than starting at "500+".
    expect(PlatformSetting::current()->hasPublishedMetrics())->toBeTrue()
        ->and(PlatformSetting::current()->metric_organisations)->toBe(12);
});

it('does not let a tenant admin edit the public site', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', ['tagline' => 'hijacked'])
        ->assertForbidden();

    expect(PlatformSetting::current()->tagline)->not->toBe('hijacked');
});

it('refuses a customer quote with no name and no recorded consent', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    // The invented testimonial this replaces was three strings in a component.
    // Making them columns would have changed nothing on its own — the same
    // three strings, typed into a form. `required_with:testimonial_quote` is
    // what makes the difference: a quote needs someone to have said it and a
    // date they agreed to be quoted, or it is not a testimonial.
    $this->putJson('/api/v1/admin/platform-settings', [
        'testimonial_quote' => 'ETHR replaced three separate systems for us.',
    ])->assertStatus(422)->assertJsonValidationErrors([
        'testimonial_author',
        'testimonial_consented_on',
    ]);
});

it('refuses consent dated in the future', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    // Consent that has not happened yet is not consent.
    $this->putJson('/api/v1/admin/platform-settings', [
        'testimonial_quote' => 'ETHR replaced three separate systems for us.',
        'testimonial_author' => 'A real customer',
        'testimonial_consented_on' => now()->addDay()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors(['testimonial_consented_on']);
});

it('stores a complete testimonial and audits the change', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::SUPER_ADMIN, 'mfa_enabled' => true], $tenant);

    $this->putJson('/api/v1/admin/platform-settings', [
        'testimonial_quote' => 'ETHR replaced three separate systems for us.',
        'testimonial_author' => 'A real customer',
        'testimonial_role' => 'HR Director',
        'testimonial_organisation' => 'A real organisation',
        'testimonial_consented_on' => '2026-09-01',
    ])->assertOk();

    expect(PlatformSetting::current()->hasPublishedTestimonial())->toBeTrue();

    $this->assertDatabaseHas('audit_log', ['action' => 'platform.settings.updated']);
});
