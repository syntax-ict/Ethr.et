<?php

declare(strict_types=1);

use App\Enums\UserRole;

describe('PUT /api/v1/settings/branding', function () {
    it('updates the tenant logo and theme colors', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $response = $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/branding',
            [
                'logo_url' => 'https://cdn.example.et/acme-logo.png',
                'primary_color' => '#0F4C75',
                'accent_color' => '#E8A838',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('logo_url', 'https://cdn.example.et/acme-logo.png')
            ->assertJsonPath('theme.primary_color', '#0F4C75')
            ->assertJsonPath('theme.accent_color', '#E8A838');

        expect($tenant->fresh()->logo_path)->toBe('https://cdn.example.et/acme-logo.png');

        $this->assertDatabaseHas('audit_log', ['action' => 'settings.branding_updated']);
    });

    it('rejects colors that are not valid hex', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/branding',
            ['primary_color' => 'not-a-color']
        )->assertUnprocessable()
            ->assertJsonPath('errors.primary_color.0', fn ($m) => is_string($m));
    });

    it('denies branding changes to non-admin roles', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/branding',
            ['logo_url' => 'https://cdn.example.et/logo.png']
        )->assertForbidden();
    });
});
