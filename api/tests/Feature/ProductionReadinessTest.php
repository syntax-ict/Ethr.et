<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use App\Rules\ExternalUrl;

// ── Webhook URL Validation (SSRF Prevention) ──

test('webhook rejects localhost url', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks", [
        'url' => 'http://localhost/callback',
        'events' => ['employee.created'],
    ]);

    $response->assertUnprocessable();
});

test('webhook rejects 127.0.0.1 url', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks", [
        'url' => 'http://127.0.0.1:8080/hook',
        'events' => ['employee.created'],
    ]);

    $response->assertUnprocessable();
});

test('webhook rejects internal domain', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks", [
        'url' => 'http://service.internal/hook',
        'events' => ['employee.created'],
    ]);

    $response->assertUnprocessable();
});

test('webhook accepts valid external url', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/webhooks", [
        'url' => 'https://hooks.example.com/ethr',
        'events' => ['employee.created'],
    ]);

    $response->assertStatus(201);
});

// ── ExternalUrl Rule Unit Tests ──

test('external url rule rejects localhost', function () {
    $rule = new ExternalUrl();
    $failed = false;
    $rule->validate('url', 'http://localhost/test', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeTrue();
});

test('external url rule rejects 0.0.0.0', function () {
    $rule = new ExternalUrl();
    $failed = false;
    $rule->validate('url', 'http://0.0.0.0/test', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeTrue();
});

test('external url rule allows valid https', function () {
    $rule = new ExternalUrl();
    $failed = false;
    $rule->validate('url', 'https://api.example.com/webhook', function () use (&$failed) {
        $failed = true;
    });
    expect($failed)->toBeFalse();
});

// ── Pagination Cap ──

test('pagination is capped at 100', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);

    Employee::factory()->count(3)->create(['tenant_id' => $tenant->id]);

    $response = test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees?per_page=500");

    $response->assertOk();
    expect($response->json('meta.per_page'))->toBeLessThanOrEqual(100);
});

// ── Login Rate Limiting ──

test('login is rate limited after 5 attempts', function () {
    $tenant = createTenant();

    for ($i = 0; $i < 5; $i++) {
        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
            'email' => 'wrong@example.com',
            'password' => 'wrong',
        ]);
    }

    $response = test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => 'wrong@example.com',
        'password' => 'wrong',
    ]);

    $response->assertStatus(429);
});

// ── Health Endpoint ──

test('health endpoint returns status', function () {
    $response = test()->getJson('/api/v1/health');

    $response->assertOk()
        ->assertJsonStructure([
            'status',
            'services',
            'timestamp',
            'version',
        ]);
    expect($response->json('status'))->toBe('healthy');
    expect($response->json('services.api'))->toBe('healthy');
});

// ── Fresh Migration ──

test('all migrations run without error', function () {
    $this->artisan('migrate:status')
        ->assertSuccessful();
});
