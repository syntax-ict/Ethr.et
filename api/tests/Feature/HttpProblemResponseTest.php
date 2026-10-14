<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The renderer that turns every HttpException into RFC 7807 (bootstrap/app.php).
 *
 * It dropped the exception's own headers — so a 429 told an API client it was
 * limited but not when to come back — answered `application/json`, and passed
 * route-model binding's message through, naming our model classes to any
 * caller (API/webhook end-to-end test, 2026-10-14, D2 and D3).
 */
it('tells a rate-limited caller when to try again, as a problem document', function () {
    RateLimiter::for('api-global', fn () => Limit::perMinute(1)->by('problem-test'));

    test()->getJson('/api/v1/ping')->assertOk();

    test()->getJson('/api/v1/ping')
        ->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('X-RateLimit-Limit', '1')
        ->assertJsonPath('type', 'https://ethr.et/errors/http')
        ->assertJsonPath('status', 429)
        ->assertHeader('Retry-After');
});

it('says a missing record was not found without naming the model behind it', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    $response = test()->getJson('/api/v1/employees/01ZZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('detail', 'The requested resource was not found.');

    expect($response->getContent())->not->toContain('App\\\\Models')
        ->and($response->getContent())->not->toContain('No query results');
});

it('says when to try again on the limits the controllers count themselves', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    $forgot = fn () => test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/password/forgot", ['email' => 'nobody@acme.test']);

    foreach (range(1, 3) as $ignored) {
        $forgot()->assertOk();
    }

    $forgot()
        ->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('Retry-After');
});
