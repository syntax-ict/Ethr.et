<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Support\ApiKeyAbilities;
use App\Support\ApiReference;
use App\Support\WebhookEvents;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

/**
 * The API reference an organisation's developers read (Settings → API Keys →
 * API reference). It lists what an API key can call — and only that: the
 * full Swagger document at /api/docs maps the platform console too and stays
 * with super admins.
 */
function referenceSource(): array
{
    $schema = fn (string $name) => ['$ref' => "#/components/schemas/{$name}"];
    $ok = fn (array $schema) => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => $schema]]]];

    return [
        'openapi' => '3.1.0',
        'paths' => [
            '/employees' => ['get' => ['summary' => 'List employees', 'responses' => $ok($schema('EmployeeResource'))]],
            '/employees/{employee}' => ['put' => ['summary' => 'Update employee', 'responses' => $ok($schema('EmployeeResource'))]],
            '/organization/departments' => ['get' => ['summary' => 'List departments', 'responses' => $ok($schema('DepartmentResource'))]],
            '/api-keys' => ['post' => ['summary' => 'Create key', 'responses' => $ok($schema('Secret'))]],
            '/users' => ['get' => ['summary' => 'List users', 'responses' => $ok($schema('Secret'))]],
            '/admin/plans' => ['get' => ['summary' => 'Plans', 'responses' => $ok($schema('Secret'))]],
            '/settings' => [
                'get' => ['summary' => 'Read settings', 'responses' => $ok(['type' => 'object'])],
                'put' => ['summary' => 'Change settings', 'responses' => $ok($schema('Secret'))],
            ],
            '/not-a-route' => ['get' => ['summary' => 'Stale', 'responses' => $ok(['type' => 'object'])]],
        ],
        'components' => [
            'schemas' => [
                'EmployeeResource' => ['type' => 'object', 'properties' => ['department' => $schema('DepartmentResource')]],
                'DepartmentResource' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                'Secret' => ['type' => 'object'],
                'Unused' => ['type' => 'object'],
            ],
        ],
    ];
}

it('lists what a key can call, with the abilities each needs', function () {
    $reference = ApiReference::build(referenceSource(), app(Router::class));

    expect($reference['paths'])->toHaveKeys(['/employees', '/employees/{employee}', '/organization/departments', '/settings'])
        ->and($reference['paths']['/employees']['get']['x-abilities'])->toBe(['read', 'employees'])
        ->and($reference['paths']['/employees/{employee}']['put']['x-abilities'])->toBe(['write', 'employees'])
        ->and($reference['paths']['/organization/departments']['get']['x-abilities'])->toBe(['read'])
        ->and($reference['x-webhook-events'])->toBe(WebhookEvents::ALL);
});

it('leaves out what no key may call, and what is not a route at all', function () {
    $reference = ApiReference::build(referenceSource(), app(Router::class));

    expect($reference['paths'])->not->toHaveKeys(['/api-keys', '/users', '/admin/plans', '/not-a-route'])
        ->and($reference['paths']['/settings'])->toHaveKey('get')
        ->and($reference['paths']['/settings'])->not->toHaveKey('put');
});

it('keeps only the schemas the listed operations use, followed through their references', function () {
    $schemas = ApiReference::build(referenceSource(), app(Router::class))['components']['schemas'];

    expect(array_keys($schemas))->toBe(['DepartmentResource', 'EmployeeResource']);
});

it('ships a reference that matches the routes: every operation is one a key can make', function () {
    $reference = json_decode((string) file_get_contents(base_path(ApiReference::PATH)), true);
    $routes = app(Router::class)->getRoutes();

    expect(count($reference['paths']))->toBeGreaterThan(50);

    foreach ($reference['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            $uri = '/api/v1'.preg_replace('/\{[^}]+\}/', 'x', $path);
            $request = Request::create($uri, strtoupper($method));

            expect(fn () => $routes->match($request))->not->toThrow(Exception::class, "{$method} {$path}")
                ->and(ApiKeyAbilities::sessionOnly($request))->toBeFalse("{$method} {$path} is session-only")
                ->and($operation['x-abilities'])->toBe(ApiKeyAbilities::requiredFor($request));
        }
    }
});

it('is served to whoever manages API keys, with this host as the server', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    actingAsUser(['role' => UserRole::TENANT_ADMIN], $tenant);

    test()->getJson('http://acme.ethr.test/api/v1/api-keys/reference')
        ->assertOk()
        ->assertJsonPath('servers.0.url', 'http://acme.ethr.test/api/v1')
        ->assertJsonPath('x-webhook-events', WebhookEvents::ALL)
        ->assertJsonStructure(['paths' => ['/employees' => ['get' => ['x-abilities']]]]);
});

it('is refused to someone who does not manage API keys, and to an API key', function () {
    $tenant = createTenant(['subdomain' => 'acme']);
    $admin = createUser(['role' => UserRole::TENANT_ADMIN], $tenant);
    actingAsUser(['role' => UserRole::EMPLOYEE], $tenant);

    test()->getJson('http://acme.ethr.test/api/v1/api-keys/reference')->assertForbidden();

    $plain = 'ethr_'.bin2hex(random_bytes(20));
    ApiKey::factory()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $admin->id,
        'key_hash' => hash('sha256', $plain),
        'key_prefix' => substr($plain, 0, 12),
        'abilities' => ['read'],
    ]);
    app('auth')->forgetGuards();

    test()->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('http://acme.ethr.test/api/v1/api-keys/reference')
        ->assertForbidden()
        ->assertJsonPath('type', 'https://ethr.et/errors/api-key-session-only');
});
