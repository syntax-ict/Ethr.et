<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;

describe('POST /api/v1/auth/register', function () {
    it('registers a new tenant and admin user', function () {
        $payload = [
            'organization_name' => 'Acme Corp',
            'subdomain' => 'acme-corp',
            'admin_name' => 'Admin User',
            'admin_email' => 'admin@acme.com',
            'admin_phone' => '+251911234567',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'organization_type' => 'general',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated()
            ->assertJsonStructure([
                'user' => ['public_id', 'email', 'role'],
                'tenant' => ['public_id', 'name', 'subdomain'],
                'access_token',
                'token_type',
                'expires_in',
            ]);

        expect($response->json('tenant.name'))->toBe('Acme Corp');
        expect($response->json('tenant.subdomain'))->toBe('acme-corp');
        expect($response->json('user.role'))->toBe('tenant_admin');
        expect($response->json('token_type'))->toBe('Bearer');

        $this->assertDatabaseHas('tenants', ['subdomain' => 'acme-corp']);
        $this->assertDatabaseHas('users', ['email' => 'admin@acme.com']);
    });

    it('rejects duplicate subdomains', function () {
        Tenant::factory()->create(['subdomain' => 'taken-domain']);

        $payload = [
            'organization_name' => 'Another Corp',
            'subdomain' => 'taken-domain',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@another.com',
            'admin_phone' => '0912345678',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ];

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subdomain']);
    });

    it('validates required fields', function () {
        $this->postJson('/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'organization_name',
                'subdomain',
                'admin_name',
                'admin_email',
                'password',
            ]);
    });

    it('validates password confirmation', function () {
        $payload = [
            'organization_name' => 'Test Corp',
            'subdomain' => 'test-corp',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@test.com',
            'admin_phone' => '0912345678',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'DifferentPass!',
        ];

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    });

    it('validates subdomain format', function () {
        $payload = [
            'organization_name' => 'Test',
            'subdomain' => 'INVALID SUB',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@test.com',
            'admin_phone' => '0912345678',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ];

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['subdomain']);
    });

    it('validates ethiopian phone format', function () {
        $payload = [
            'organization_name' => 'Test',
            'subdomain' => 'test-phone',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@test.com',
            'admin_phone' => '09112345',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ];

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_phone']);
    });

    it('accepts a local-format phone and stores it canonically', function () {
        $payload = [
            'organization_name' => 'Local Phone Corp',
            'subdomain' => 'local-phone',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@localphone.com',
            'admin_phone' => '0912345678',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ];

        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

        // Canonicalized to E.164 for consistent storage regardless of input form.
        $this->assertDatabaseHas('users', [
            'email' => 'admin@localphone.com',
            'phone' => '+251912345678',
        ]);
    });

    it('accepts a spaced local-format phone', function () {
        $payload = [
            'organization_name' => 'Spaced Phone Corp',
            'subdomain' => 'spaced-phone',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@spacedphone.com',
            'admin_phone' => '0912 345 678',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ];

        $this->postJson('/api/v1/auth/register', $payload)->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@spacedphone.com',
            'phone' => '+251912345678',
        ]);
    });

    it('applies the platform password policy to the admin password', function () {
        // The registration form used to run `min:8` while every other password
        // path went through PasswordPolicy. Both enforced the same rule today,
        // but a tightened floor in DEFAULTS would leave registration behind —
        // pinning it here so future changes fail loudly, not silently.
        $payload = [
            'organization_name' => 'Policy Corp',
            'subdomain' => 'policy-corp',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@policy.com',
            'admin_phone' => '0912345678',
            'password' => 'short',
            'password_confirmation' => 'short',
        ];

        $this->postJson('/api/v1/auth/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    });

    it('hides numeric ids in response', function () {
        $payload = [
            'organization_name' => 'ID Test Corp',
            'subdomain' => 'id-test',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@idtest.com',
            'admin_phone' => '0912345678',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated();
        expect($response->json('user'))->not->toHaveKey('id');
        expect($response->json('tenant'))->not->toHaveKey('id');
    });

    it('takes a broad kind or an industry as the organisation type, and nothing else', function (string $type, bool $accepted) {
        $response = $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Type Test',
            'subdomain' => 'type-'.str_replace('_', '-', $type),
            'admin_name' => 'Admin',
            'admin_email' => 'admin@type.example',
            'admin_phone' => '0912345678',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
            'organization_type' => $type,
        ]);

        if ($accepted) {
            $response->assertCreated();
            expect(Tenant::query()->where('type', $type)->exists())->toBeTrue();

            return;
        }

        $response->assertUnprocessable()->assertJsonValidationErrors(['organization_type']);
        expect(Tenant::query()->where('type', $type)->exists())->toBeFalse();
    })->with([
        'a broad kind the form offers' => ['government', true],
        'a broad kind that is also an industry' => ['hospital', true],
        'an industry the form does not offer yet' => ['health_center', true],
        'a word no catalogue knows' => ['cartel', false],
        'a kind in law, which is a different answer' => ['share_company', false],
    ]);

    it('gives the admin and the organisation the language the form was read in', function (?string $sent, string $stored) {
        $headers = $sent === null ? [] : ['Accept-Language' => $sent];

        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'Language Test',
            'subdomain' => 'language-test',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@language.example',
            'admin_phone' => '0912345678',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ], $headers)->assertCreated();

        $tenant = Tenant::query()->where('subdomain', 'language-test')->sole();
        $admin = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();

        expect($admin->locale)->toBe($stored)
            ->and($tenant->default_locale)->toBe($stored)
            ->and($tenant->settings['locale'])->toBe($stored)
            ->and($admin->preferredLocale())->toBe($stored);
    })->with([
        'Amharic' => ['am', 'am'],
        'Afaan Oromoo' => ['om', 'om'],
        'English' => ['en', 'en'],
        'a client that sends none' => [null, 'en'],
        'a language not shipped yet' => ['ti', 'en'],
    ]);

    it('still registers a client that sends no organisation type', function () {
        $this->postJson('/api/v1/auth/register', [
            'organization_name' => 'No Type',
            'subdomain' => 'no-type',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@notype.example',
            'admin_phone' => '0912345678',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ])->assertCreated();
    });
});
