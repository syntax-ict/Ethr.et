<?php

declare(strict_types=1);

use App\Enums\UserRole;

describe('PUT /api/v1/settings/branding', function () {
    it('updates the theme colors, and ignores the retired logo link', function () {
        $tenant = createTenant(['logo_path' => null]);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $response = $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/branding',
            [
                'logo_url' => 'https://cdn.example.et/acme-logo.png',
                'primary_color' => '#0F4C75',
                'accent_color' => '#E8A838',
            ]
        );

        // The link was retired on 2026-10-15: images are uploads only. A
        // client still sending it changes nothing and is told the logo in
        // effect, which is the uploaded one or none.
        $response->assertOk()
            ->assertJsonPath('logo_url', null)
            ->assertJsonPath('theme.primary_color', '#0F4C75')
            ->assertJsonPath('theme.accent_color', '#E8A838');

        expect($tenant->fresh()->logo_path)->toBeNull();

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

    // Until 2026-10-15 the logo could be a typed https link; it never reaches
    // the public page now, whatever it says, and the column is not read.
    it('never shows a logo link, whatever is sent or stored', function (string $url) {
        $tenant = createTenant(['logo_path' => null]);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/branding',
            ['logo_url' => $url]
        )->assertOk()->assertJsonPath('logo_url', null);
        expect($tenant->fresh()->logo_path)->toBeNull();

        $tenant->update(['logo_path' => $url]);
        $this->getJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/settings')
            ->assertJsonPath('branding.logo_url', null);
    })->with([
        'https' => 'https://cdn.example.et/logo.png',
        'plain http' => 'http://cdn.example.et/logo.png',
        'a script URL' => 'javascript:alert(1)',
    ]);

    it('denies branding changes to non-admin roles', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/branding',
            ['logo_url' => 'https://cdn.example.et/logo.png']
        )->assertForbidden();
    });
});
