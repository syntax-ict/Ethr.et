<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\EnforceApiKeyAbilities;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * The API reference an organisation's own developers read: the operations an
 * API key can actually call, and the abilities each one needs.
 *
 * Built from the full OpenAPI document Scramble exports (`scramble:export`),
 * which stays super-admin-only at /api/docs because it maps every endpoint,
 * the platform console's included. This keeps an operation only when its
 * route sits behind EnforceApiKeyAbilities and ApiKeyAbilities does not
 * reserve it for a signed-in session, so the reference cannot list a call a
 * key would be refused — the same two checks the request itself meets.
 *
 * The result is committed (resources/api-reference.json) and served by
 * ApiReferenceController; the contract gate regenerates it and fails on drift,
 * exactly as it does for src/src/api/generated.ts.
 */
final class ApiReference
{
    public const PATH = 'resources/api-reference.json';

    private const PREFIX = 'api/v1';

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /**
     * @param  array<string, mixed>  $openApi  the full exported document
     * @return array<string, mixed>
     */
    public static function build(array $openApi, Router $router): array
    {
        $keyRoutes = self::keyRoutes($router);

        $paths = [];
        foreach ((array) ($openApi['paths'] ?? []) as $path => $operations) {
            foreach ((array) $operations as $method => $operation) {
                $abilities = $keyRoutes[strtoupper((string) $method).' '.self::shape((string) $path)] ?? null;
                if ($abilities === null || ! in_array($method, self::METHODS, true)) {
                    continue;
                }

                $paths[$path][$method] = ['x-abilities' => $abilities] + (array) $operation;
            }
        }
        ksort($paths);

        $document = [
            'openapi' => $openApi['openapi'] ?? '3.1.0',
            'info' => [
                'title' => 'ETHR API',
                'version' => 'v1',
                'description' => 'The operations an API key can call. Every request is made as the person who created the key, '
                    .'within the abilities the key was given, and only ever reaches that key\'s own organisation.',
            ],
            'paths' => $paths,
            'components' => self::usedComponents((array) ($openApi['components'] ?? []), $paths),
            'x-webhook-events' => WebhookEvents::ALL,
        ];

        return $document;
    }

    /**
     * "METHOD /path/{}" for every route an API key may call, with the
     * abilities any one of which allows it.
     *
     * @return array<string, list<string>>
     */
    private static function keyRoutes(Router $router): array
    {
        $routes = [];
        foreach ($router->getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            $uri = $route->uri();
            if (! str_starts_with($uri, self::PREFIX.'/') || ! in_array(EnforceApiKeyAbilities::class, $route->gatherMiddleware(), true)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $request = Request::create('/'.$uri, $method);
                if (ApiKeyAbilities::sessionOnly($request)) {
                    continue;
                }

                $routes[$method.' '.self::shape(substr($uri, strlen(self::PREFIX)))] = ApiKeyAbilities::requiredFor($request);
            }
        }

        return $routes;
    }

    /** "/employees/{employee}" and "employees/{id}" both become "/employees/{}". */
    private static function shape(string $path): string
    {
        return '/'.trim((string) preg_replace('/\{[^}]+\}/', '{}', $path), '/');
    }

    /**
     * Only the schemas and responses the kept operations refer to, followed
     * transitively: the full document's components describe the console too.
     *
     * @param  array<string, mixed>  $components
     * @param  array<string, mixed>  $paths
     * @return array<string, mixed>
     */
    private static function usedComponents(array $components, array $paths): array
    {
        $kept = [];
        $pending = self::refs($paths);

        while ($pending !== []) {
            $ref = array_pop($pending);
            if (isset($kept[$ref])) {
                continue;
            }

            [$section, $name] = explode('/', $ref, 2);
            if (! isset($components[$section][$name])) {
                continue;
            }

            $kept[$ref] = true;
            array_push($pending, ...self::refs($components[$section][$name]));
        }

        $used = [];
        foreach (array_keys($kept) as $ref) {
            [$section, $name] = explode('/', $ref, 2);
            $used[$section][$name] = $components[$section][$name];
        }
        foreach ($used as &$entries) {
            ksort($entries);
        }
        ksort($used);

        return $used;
    }

    /** @return list<string> "schemas/Name" for every $ref inside $node */
    private static function refs(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/components/')) {
                $found[] = substr($value, strlen('#/components/'));
            } elseif (is_array($value)) {
                array_push($found, ...self::refs($value));
            }
        }

        return $found;
    }
}
