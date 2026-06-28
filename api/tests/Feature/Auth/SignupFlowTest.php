<?php

declare(strict_types=1);

use App\Models\Tenant;

describe('subdomain availability check', function () {
    it('reports available subdomain', function () {
        $response = $this->getJson('/api/v1/register/check-subdomain?subdomain=newcompany');

        $response->assertOk()
            ->assertJsonPath('subdomain', 'newcompany')
            ->assertJsonPath('available', true);
    });

    it('reports taken subdomain', function () {
        Tenant::factory()->create(['subdomain' => 'taken']);

        $response = $this->getJson('/api/v1/register/check-subdomain?subdomain=taken');

        $response->assertOk()
            ->assertJsonPath('available', false);
    });

    it('rejects reserved subdomains', function () {
        $response = $this->getJson('/api/v1/register/check-subdomain?subdomain=admin');

        $response->assertOk()
            ->assertJsonPath('available', false);
    });

    it('validates subdomain format', function () {
        $response = $this->getJson('/api/v1/register/check-subdomain?subdomain=AB');

        $response->assertUnprocessable();
    });
});

describe('registration provisions tenant', function () {
    it('sets trial to 6 months', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Trial Corp',
            'subdomain' => 'trialcorp',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@trial.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();

        $tenant = Tenant::withoutGlobalScopes()->where('subdomain', 'trialcorp')->first();
        expect($tenant)->not->toBeNull();
        expect((int) abs($tenant->trial_ends_at->diffInMonths(now())))->toBeGreaterThanOrEqual(5);
    });

    it('provisions default settings via event', function () {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Settings Corp',
            'subdomain' => 'settingscorp',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@settings.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();

        $tenant = Tenant::withoutGlobalScopes()->where('subdomain', 'settingscorp')->first();
        expect($tenant->settings)->toHaveKey('timezone', 'Africa/Addis_Ababa');
        expect($tenant->settings)->toHaveKey('locale', 'en');
    });

    it('rejects duplicate email in same tenant', function () {
        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'First Corp',
            'subdomain' => 'firstcorp',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@shared.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Second Corp',
            'subdomain' => 'firstcorp',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@shared.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertUnprocessable();
    });
});

describe('trial enforcement', function () {
    it('allows access during active trial', function () {
        $tenant = createTenant();
        $user = actingAsUser([], $tenant);

        $response = $this->getJson('http://' . $tenant->subdomain . '.ethr.test/api/v1/auth/me');

        $response->assertOk();
    });
});
