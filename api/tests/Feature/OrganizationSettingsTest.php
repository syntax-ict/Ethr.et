<?php

declare(strict_types=1);

use App\Enums\UserRole;

describe('PUT /api/v1/settings/organization', function () {
    it('writes the real tenant columns, not just the settings JSON', function () {
        $tenant = createTenant(['timezone' => 'Africa/Addis_Ababa', 'default_locale' => 'en']);
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $response = $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/organization',
            [
                'name' => 'Acme Ethiopia PLC',
                'type' => 'government',
                'timezone' => 'Africa/Nairobi',
                'locale' => 'am',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('organization.name', 'Acme Ethiopia PLC')
            ->assertJsonPath('organization.locale', 'am');

        $fresh = $tenant->fresh();

        // The columns are what the rest of the app reads: TenantResource
        // exposes them, and UserProvisioningService gives every newly
        // provisioned user `default_locale`. Before this fix the endpoint wrote
        // only the settings JSON, so changing the language here changed nothing
        // anyone consumed.
        expect($fresh->name)->toBe('Acme Ethiopia PLC')
            ->and($fresh->type)->toBe('government')
            ->and($fresh->timezone)->toBe('Africa/Nairobi')
            ->and($fresh->default_locale)->toBe('am');

        $this->assertDatabaseHas('audit_log', ['action' => 'settings.organization_updated']);
    });

    it('reflects the saved values back through GET /settings', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/organization',
            ['timezone' => 'Africa/Nairobi', 'locale' => 'am']
        )->assertOk();

        $this->getJson('http://'.$tenant->subdomain.'.ethr.test/api/v1/settings')
            ->assertOk()
            ->assertJsonPath('organization.timezone', 'Africa/Nairobi')
            ->assertJsonPath('organization.locale', 'am');
    });

    it('keeps the settings JSON in step with the columns', function () {
        // The JSON keys were the only store before this endpoint wrote the real
        // columns. Mirroring both ways means a tenant is never left with two
        // values that disagree about the same setting.
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/organization',
            ['timezone' => 'Africa/Djibouti', 'locale' => 'am']
        )->assertOk();

        $settings = $tenant->fresh()->settings;

        expect($settings['timezone'])->toBe('Africa/Djibouti')
            ->and($settings['locale'])->toBe('am');
    });

    it('rejects a locale the product does not ship', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

        $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/organization',
            ['locale' => 'fr']
        )->assertUnprocessable();
    });

    it('denies organization changes to non-admin roles', function () {
        $tenant = createTenant();
        actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

        $this->putJson(
            'http://'.$tenant->subdomain.'.ethr.test/api/v1/settings/organization',
            ['name' => 'Hijacked Ltd']
        )->assertForbidden();

        expect($tenant->fresh()->name)->not->toBe('Hijacked Ltd');
    });
});
