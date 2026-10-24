<?php

declare(strict_types=1);

/**
 * A signed-out API request that does not ask for JSON.
 *
 * The web app always sends `Accept: application/json`; a script or an
 * integration calling with plain curl does not. Without it the auth
 * middleware asked for a redirect to route('login'), which this app never
 * names, and every protected route answered 500 "Server Error" instead of
 * 401 (production, 2026-10-24).
 */
it('answers 401 problem+json to a signed-out request with no Accept header', function (string $path) {
    $tenant = createTenant(['subdomain' => 'acme']);

    test()->get("http://{$tenant->subdomain}.ethr.test{$path}")
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/unauthenticated')
        ->assertJsonPath('status', 401);
})->with([
    'employees' => '/api/v1/employees',
    'payroll runs' => '/api/v1/payroll/runs',
    'leave requests' => '/api/v1/leave/requests',
]);

it('answers the same 401 when the caller does ask for JSON', function () {
    $tenant = createTenant(['subdomain' => 'acme']);

    test()->getJson("http://{$tenant->subdomain}.ethr.test/api/v1/employees")
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://ethr.et/errors/unauthenticated');
});
