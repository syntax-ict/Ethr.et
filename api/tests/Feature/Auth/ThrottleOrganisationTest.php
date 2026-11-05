<?php

declare(strict_types=1);

use App\Services\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

// A-Z review I-02 and I-12 (2026-11-05). An email or employee code is unique
// within one organisation only, and an office reaches ETHR from one address,
// so the sign-in counters have to say which organisation they count for.

test('failed sign-ins at one organisation do not lock the same email out at another', function () {
    $first = createTenant();
    createUser(['email' => 'shared@office.test', 'password' => 'correct-horse-battery'], $first);
    $second = createTenant();
    createUser(['email' => 'shared@office.test', 'password' => 'correct-horse-battery'], $second);

    for ($i = 0; $i < 6; $i++) {
        test()->postJson("http://{$first->subdomain}.ethr.test/api/v1/auth/login", [
            'email' => 'shared@office.test',
            'password' => 'wrong-password',
        ]);
    }

    test()->postJson("http://{$first->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => 'shared@office.test',
        'password' => 'wrong-password',
    ])->assertStatus(429);

    test()->postJson("http://{$second->subdomain}.ethr.test/api/v1/auth/login", [
        'email' => 'shared@office.test',
        'password' => 'correct-horse-battery',
    ])->assertOk();
});

test('reset requests at one organisation do not use up the same email at another', function () {
    $first = createTenant();
    $second = createTenant();

    for ($i = 0; $i < 3; $i++) {
        test()->postJson("http://{$first->subdomain}.ethr.test/api/v1/auth/password/forgot", [
            'email' => 'shared@office.test',
        ])->assertOk();
    }

    test()->postJson("http://{$first->subdomain}.ethr.test/api/v1/auth/password/forgot", [
        'email' => 'shared@office.test',
    ])->assertStatus(429);

    test()->postJson("http://{$second->subdomain}.ethr.test/api/v1/auth/password/forgot", [
        'email' => 'shared@office.test',
    ])->assertOk();
});

test('the auth budget names the organisation the request resolved to', function () {
    $tenant = createTenant();
    $request = Request::create('/api/v1/auth/login', 'POST', ['email' => 'staff@office.test'], server: ['REMOTE_ADDR' => '196.188.1.10']);

    $limits = RateLimiter::limiter('auth')($request);

    expect($limits[0]->key)->toBe("auth-account:staff@office.test|{$tenant->id}|196.188.1.10");
});

test('the activation budget follows the resolved organisation, not the body', function () {
    $first = createTenant();
    $second = createTenant();
    $key = function (string $typed) {
        $request = Request::create('/api/v1/auth/activation/redeem', 'POST', ['tenant' => $typed, 'employee_code' => 'E-001'], server: ['REMOTE_ADDR' => '196.188.1.10']);

        return RateLimiter::limiter('activation')($request)[1]->key;
    };

    app(CurrentTenant::class)->set($first);
    $asTyped = $key($first->subdomain);
    $otherSpelling = $key(strtoupper($first->subdomain).' ');

    app(CurrentTenant::class)->set($second);
    $elsewhere = $key($first->subdomain);

    expect($otherSpelling)->toBe($asTyped)
        ->and($elsewhere)->not->toBe($asTyped);
});
