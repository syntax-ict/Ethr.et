<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;

describe('POST /api/v1/auth/login', function () {
    it('authenticates with valid credentials', function () {
        $tenant = createTenant();
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'user@test.com',
            'password' => bcrypt('password'),
            'role' => UserRole::EMPLOYEE,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'user@test.com',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
                'mfa_required',
            ]);

        expect($response->json('token_type'))->toBe('Bearer');
        expect($response->json('mfa_required'))->toBeFalse();
    });

    it('rejects invalid credentials', function () {
        $tenant = createTenant();
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'user@test.com',
            'password' => bcrypt('password'),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'user@test.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    it('blocks inactive accounts', function () {
        $tenant = createTenant();
        User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'suspended@test.com',
            'password' => bcrypt('password'),
            'status' => 'suspended',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'suspended@test.com',
            'password' => 'password',
        ])->assertForbidden();
    });

    it('validates required fields', function () {
        $this->postJson('/api/v1/auth/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    });
});

describe('POST /api/v1/auth/logout', function () {
    it('logs out authenticated user', function () {
        $user = actingAsUser();

        $this->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out successfully.']);
    });

    it('rejects unauthenticated logout', function () {
        $this->postJson('/api/v1/auth/logout')
            ->assertUnauthorized();
    });
});

describe('GET /api/v1/auth/me', function () {
    it('returns the current user profile', function () {
        $user = actingAsUser(['role' => UserRole::HR_ADMIN]);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonStructure([
                'user' => ['public_id', 'email', 'role', 'mfa_enabled', 'locale'],
            ]);

        expect($response->json('user.role'))->toBe('hr_admin');
        expect($response->json('user'))->not->toHaveKey('id');
    });

    it('rejects unauthenticated requests', function () {
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    });
});
