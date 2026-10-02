<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Since Laravel 11 the api middleware group carries no throttle unless
 * bootstrap/app.php asks for one, and it did not. The named limiters applied
 * only where a route listed one, so everything else took unlimited requests:
 * measured 2026-10-01, 400 consecutive calls to /plans and to an authenticated
 * endpoint all returned 200.
 */
test('every api route passes through the global limiter', function () {
    $unthrottled = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
        ->reject(fn ($route) => collect(app('router')->gatherRouteMiddleware($route))
            ->contains(fn ($m) => is_string($m) && str_ends_with($m, ':api-global')))
        ->map(fn ($route) => $route->uri())
        ->values()
        ->all();

    expect($unthrottled)->toBe([]);
});

test('a signed-in user is limited to 300 requests a minute', function () {
    $tenant = createTenant();
    actingAsUser(['role' => UserRole::HR_ADMIN], $tenant);
    $url = "http://{$tenant->subdomain}.ethr.test/api/v1/announcements";

    for ($i = 0; $i < 300; $i++) {
        test()->getJson($url)->assertOk();
    }

    test()->getJson($url)->assertStatus(429);
});

test('the public catalogue is limited to 120 requests a minute per address', function (string $path) {
    for ($i = 0; $i < 120; $i++) {
        expect(test()->getJson("http://ethr.test/api/v1/{$path}")->status())->not->toBe(429);
    }

    test()->getJson("http://ethr.test/api/v1/{$path}")->assertStatus(429);
})->with(['plans', 'site-content', 'templates']);

test('an office behind one address can sign in more than ten people a minute', function () {
    // Every login counted against the address before, successful ones too, so
    // the 11th person at a shared NAT address got a 429.
    $tenant = createTenant();
    for ($i = 0; $i < 15; $i++) {
        createUser(['email' => "staff{$i}@office.test", 'password' => 'correct-horse-battery'], $tenant);
    }

    for ($i = 0; $i < 15; $i++) {
        test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/login", [
            'email' => "staff{$i}@office.test",
            'password' => 'correct-horse-battery',
        ])->assertOk();
        app('auth')->forgetGuards();
    }
});

test('the auth budget is per account and address, with a per-address ceiling', function () {
    $request = Request::create('/api/v1/auth/login', 'POST', ['email' => ' Staff@Office.TEST '], server: ['REMOTE_ADDR' => '196.188.1.10']);

    $limits = RateLimiter::limiter('auth')($request);

    expect($limits)->toHaveCount(2)
        ->and($limits[0]->maxAttempts)->toBe(10)
        ->and($limits[0]->key)->toBe('auth-account:staff@office.test|196.188.1.10')
        ->and($limits[1]->maxAttempts)->toBe(100)
        ->and($limits[1]->key)->toBe('auth-ip:196.188.1.10');
});

test('one address is still capped across many accounts', function () {
    $tenant = createTenant();

    for ($i = 0; $i < 100; $i++) {
        expect(test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/password/forgot", [
            'email' => "spray{$i}@office.test",
        ])->status())->not->toBe(429);
    }

    test()->postJson("http://{$tenant->subdomain}.ethr.test/api/v1/auth/password/forgot", [
        'email' => 'spray-final@office.test',
    ])->assertStatus(429);
});
