<?php

declare(strict_types=1);

use App\Models\Tenant;

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

    it('hides numeric ids in response', function () {
        $payload = [
            'organization_name' => 'ID Test Corp',
            'subdomain' => 'id-test',
            'admin_name' => 'Admin',
            'admin_email' => 'admin@idtest.com',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated();
        expect($response->json('user'))->not->toHaveKey('id');
        expect($response->json('tenant'))->not->toHaveKey('id');
    });
});
