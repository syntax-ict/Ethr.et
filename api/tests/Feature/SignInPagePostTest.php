<?php

declare(strict_types=1);

use App\Models\Tenant;

/*
 * A sign-in form posts natively when the button is pressed before the page has
 * hydrated. The two sign-in pages Laravel serves, `/login` on an organisation's
 * own host and `ethr.et/{slug}`, answered that POST with a 405. They now answer
 * 303 to the same address as a GET, read nothing from the body, and echo
 * nothing from the query string but `org` and `lang`.
 */
beforeEach(function () {
    config(['app.frontend_url' => 'https://www.ethr.et', 'app.domain' => 'ethr.et']);
    Tenant::factory()->create(['subdomain' => 'acme', 'name' => 'Acme Manufacturing']);
});

it('answers a POST to /login on an organisation host with 303 to the same page', function () {
    $response = test()->post('http://acme.ethr.test/login', [
        'identifier' => 'someone@example.com',
        'password' => 'Typed-Before-Hydration-9',
    ]);

    $response->assertStatus(303);
    expect($response->headers->get('Location'))->toBe('/login');
    expect((string) $response->getContent())->not->toContain('Typed-Before-Hydration-9');
});

it('answers a POST to ethr.et/{slug} with 303 to the same address', function () {
    $response = test()->post('http://ethr.et/acme', ['password' => 'Typed-Before-Hydration-9']);

    $response->assertStatus(303);
    expect($response->headers->get('Location'))->toBe('/acme');
    expect((string) $response->getContent())->not->toContain('Typed-Before-Hydration-9');
});

it('carries org and lang over and drops everything else', function () {
    $response = test()->post(
        'http://acme.ethr.test/login?org=acme&lang=am&password=leaked-secret&identifier=x%40example.com',
        ['password' => 'leaked-secret'],
    );

    $response->assertStatus(303);
    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('/login?');
    expect($query)->toBe(['org' => 'acme', 'lang' => 'am']);
    expect($location)->not->toContain('leaked-secret')->not->toContain('identifier');
    expect((string) $response->getContent())->not->toContain('leaked-secret');
});

it('drops an org or lang that is not well formed', function () {
    $response = test()->post('http://ethr.et/acme?org=%3Cscript%3E&lang=amharic-language-x');

    $response->assertStatus(303);
    expect($response->headers->get('Location'))->toBe('/acme');
});
