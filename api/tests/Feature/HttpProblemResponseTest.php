<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
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

/*
 * The renderers beside it (validation, a refused punch, a device that cannot
 * be read) built the same document but left Laravel's default
 * `application/json`, against convention #8. A client that branches on the
 * media type saw some errors as problems and others as plain JSON. The 502 is
 * pinned in DeviceEnrollmentTest, beside the device that cannot be read.
 */
it('answers a validation failure as a problem document', function () {
    $tenant = createTenant(['subdomain' => 'acme']);

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/password/forgot", [])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/validation')
        ->assertJsonPath('status', 422)
        ->assertJsonValidationErrors('email');
});

it('answers a punch the engine refuses as a problem document', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    $employee = Employee::factory()->create(['tenant_id' => $tenant->id]);
    test()->actingAs(createUser(['role' => UserRole::EMPLOYEE, 'employee_id' => $employee->id], $tenant));

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/attendance/check-out", ['idempotency_key' => 'nothing-open'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/validation')
        ->assertJsonPath('status', 422);
});

// Laravel turns the AuthorizationException into an HttpException before any
// renderer sees it, so this 403 comes from the renderer above. Pinned so a
// change to that mapping cannot quietly bring back plain JSON.
it('answers a denied ability as a problem document', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/api-keys/reference")
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 403);
});
