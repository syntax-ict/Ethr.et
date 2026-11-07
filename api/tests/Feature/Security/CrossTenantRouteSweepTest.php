<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Employee;
use Illuminate\Support\Facades\Route;
use Tests\Support\ParameterizedFixtures;

/**
 * Every `api/v1` route that names another organisation's record refuses an
 * admin of this one.
 *
 * `TenantIsolationTest` proves each model carries the scope. This proves the
 * outcome at the edge: with one record of every kind owned by organisation A,
 * organisation B's tenant admin, on B's own address, is refused on every route
 * that names one of them, for every method, rather than served it.
 *
 * A route passes when it answers 403 or 404: nothing was returned and
 * nothing was changed. A 422 passes only for the routes in
 * `crossTenantValidatesFirstRoutes()`. Anything else (a 2xx, a redirect, a 5xx) is listed with
 * its method and address. A 5xx counts because an unhandled exception on a
 * foreign id still means the lookup was not refused cleanly.
 *
 * It reuses `ParameterizedFixtures`, the record map behind
 * `ParameterizedEndpointSmokeTest`, so a route added with a parameter that is
 * in neither the map nor the exemptions below fails both tests.
 *
 * What it does not do: it sends no body, so a mutating route that validates
 * before it looks the record up answers 422 and that lookup is not exercised.
 * It also reads no response body for a refusal, so an error message that names
 * the foreign record is not caught.
 */

/**
 * Route parameters that do not name a record owned by one organisation: public
 * content, global reference data, or a value the caller supplies. A route whose
 * every parameter is one of these is not part of this sweep.
 *
 * @return array<int, string>
 */
function crossTenantExemptParams(): array
{
    return [
        // Public landing content and its images.
        'owner', 'variant', 'story', 'size', 'slot', 'kind', 'version',
        // The public lookup of an organisation by its own identifier.
        'publicId', 'subdomain',
        // Global reference data.
        'slug', 'schedule', 'which',
        // Caller-supplied keys and ids that name no fixture.
        'key', 'file', 'letter', 'export',
        // Keyed by the caller's own organisation and nothing else: a template
        // type, and a setup step. Neither can name another organisation's row.
        'type', 'step',
    ];
}

/**
 * Routes whose parameter has no fixture here, each with the reason. A new
 * entry needs a reason; a route that is not listed and not mapped fails.
 *
 * @return array<string, string>
 */
function crossTenantUnfixturedRoutes(): array
{
    return [];
}

/**
 * Routes that validate the request body before they look the record up, so with
 * no body they answer 422 whatever the id names. Each was read by hand on
 * 2026-11-07 and finds its record through a tenant-scoped query. A 422 from any
 * other route fails the sweep, because it would mean a lookup went unchecked.
 *
 * @return array<int, string>
 */
function crossTenantValidatesFirstRoutes(): array
{
    return [
        'PUT api/v1/attendance/integrity/{flag}/review',
        'PUT api/v1/profile/emergency-contacts/{contact}',
        'PUT api/v1/voice/concerns/{concern}',
        'POST api/v1/profile-update-requests/{profileUpdateRequest}/review',
        'POST api/v1/vacancies/{vacancy}/applications',
        'POST api/v1/job-applications/{application}/stage',
        'POST api/v1/job-applications/{application}/events',
        'POST api/v1/support-requests/{supportRequest}/messages',
        'POST api/v1/voice/concerns/{concern}/messages',
    ];
}

test('another organisation\'s admin is refused on every route that names a record', function () {
    $owner = createTenant();
    $fixtures = ParameterizedFixtures::build($owner);
    $params = $fixtures['params'];

    $other = createTenant();
    $otherEmployee = Employee::factory()->create(['tenant_id' => $other->id]);
    $otherAdmin = createUser(['role' => UserRole::TENANT_ADMIN, 'employee_id' => $otherEmployee->id], $other);
    $ownerAdmin = createUser(['role' => UserRole::TENANT_ADMIN, 'employee_id' => $fixtures['employee']->id], $owner);

    $exempt = crossTenantExemptParams();
    $unfixtured = crossTenantUnfixturedRoutes();

    // The control: the sweep means nothing if the owner is refused too.
    test()->actingAs($ownerAdmin);
    $control = test()->getJson("http://{$owner->subdomain}.ethr.test/api/v1/employees/{$params['employee']}");
    expect($control->getStatusCode())->toBe(200, 'the owner could not read its own employee, so the sweep proves nothing');

    test()->actingAs($otherAdmin);
    $foreign = test()->getJson("http://{$other->subdomain}.ethr.test/api/v1/employees/{$params['employee']}");
    expect($foreign->getStatusCode())->toBeIn([403, 404]);

    $methods = ['GET', 'PUT', 'PATCH', 'POST', 'DELETE'];
    $validatesFirst = crossTenantValidatesFirstRoutes();
    $failures = [];
    $unresolved = [];
    $swept = 0;

    foreach ($methods as $method) {
        foreach (Route::getRoutes() as $route) {
            if (! in_array($method, $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/') || ! str_contains($uri, '{')) {
                continue;
            }

            preg_match_all('/\{(\w+)\??\}/', $uri, $m);
            $names = $m[1];
            $owned = array_values(array_diff($names, $exempt));

            if ($owned === []) {
                continue;
            }

            if (array_key_exists($uri, $unfixtured)) {
                continue;
            }

            $resolved = $uri;
            $mapped = true;

            $routeParams = [...$params, ...($fixtures['overrides'][$uri] ?? [])];

            foreach ($names as $name) {
                if (! array_key_exists($name, $routeParams)) {
                    $mapped = false;
                    break;
                }

                $resolved = preg_replace('/\{'.$name.'\??\}/', (string) $routeParams[$name], $resolved);
            }

            if (! $mapped) {
                $unresolved[] = $uri;

                continue;
            }

            $status = test()
                ->json($method, "http://{$other->subdomain}.ethr.test/{$resolved}", [])
                ->getStatusCode();
            $swept++;

            $refusals = in_array("{$method} {$uri}", $validatesFirst, true) ? [403, 404, 422] : [403, 404];

            if (! in_array($status, $refusals, true)) {
                $failures["{$method} {$uri}"] = $status;
            }
        }
    }

    expect($swept)->toBeGreaterThan(150, 'the sweep reached too few routes to mean anything');

    if ($failures !== []) {
        $lines = collect($failures)->map(fn (int $s, string $u): string => "  {$u} => {$s}")->implode("\n");
        test()->fail("Routes that answered another organisation's admin with something other than a refusal:\n".$lines);
    }

    expect($unresolved)->toBe([]);
});
