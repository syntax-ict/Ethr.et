<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Auth\SessionHandoff;
use App\Services\CurrentTenant;

/**
 * Impersonation across the host boundary.
 *
 * The session cookie is host-only, which is what keeps one tenant's cookie away
 * from another tenant's host. Impersonation is the single flow that must cross
 * that boundary — admin.ethr.et mints the token, {tenant}.ethr.et must end up
 * holding the session — so it does so through an explicit, single-use handoff
 * rather than by widening the cookie to the whole domain.
 *
 * The rejected alternative (SESSION_DOMAIN=.ethr.et) is why these tests exist:
 * it would have widened *every* user's cookie to every tenant host, and nothing
 * here would have failed to warn about it.
 *
 * @see docs/phases/DOMAIN_MULTITENANT_UPGRADE_PLAN.md — decision D8
 */
beforeEach(function () {
    config([
        'app.domain' => 'ethr.et',
        // Cookies queued by the app are only attached to the response for
        // requests Sanctum considers stateful, and statefulness is decided by
        // Origin. The real claim is a same-origin fetch from the tenant host, so
        // production must list the tenant hosts here — SANCTUM_STATEFUL_DOMAINS
        // omitting them would leave the handoff silently cookie-less, the same
        // failure mode as the apex being missing from that list.
        'sanctum.stateful' => ['ethr.et', '*.ethr.et'],
    ]);
});

/** Headers a same-origin fetch from the tenant host would carry. */
function fromTenantOrigin(string $subdomain): array
{
    return ['Origin' => "http://{$subdomain}.ethr.et"];
}

function handoffFor(string $subdomain = 'habru'): array
{
    $tenant = Tenant::factory()->create(['subdomain' => $subdomain]);
    $nonce = app(SessionHandoff::class)->issue('42|plain-text-token', $subdomain, 1800);

    return [$tenant, $nonce];
}

it('exchanges a nonce for a host-only session cookie on the tenant host', function () {
    [$tenant, $nonce] = handoffFor();
    app(CurrentTenant::class)->forget();

    $response = test()
        ->withHeaders(fromTenantOrigin($tenant->subdomain))
        ->postJson(
            "http://{$tenant->subdomain}.ethr.et/api/v1/auth/session/claim",
            ['nonce' => $nonce],
        )->assertOk();

    // No Domain attribute — that is the property the whole handoff exists to
    // preserve. A cookie scoped to .ethr.et would reach every other tenant.
    $cookie = collect($response->headers->getCookies())
        ->firstWhere(fn ($c) => $c->getName() === 'access_token');

    // Symfony reports "no Domain attribute" as an empty string, not null.
    expect($cookie)->not->toBeNull()
        ->and($cookie->getDomain())->toBeEmpty()
        ->and($cookie->getPath())->toBe('/api')
        ->and($cookie->isHttpOnly())->toBeTrue();
});

it('refuses a nonce presented a second time', function () {
    [$tenant, $nonce] = handoffFor();
    app(CurrentTenant::class)->forget();

    $url = "http://{$tenant->subdomain}.ethr.et/api/v1/auth/session/claim";

    test()->postJson($url, ['nonce' => $nonce])->assertOk();

    // Cache::pull reads and deletes, so a replay finds nothing — a handoff URL
    // left in history or a shared screenshot is spent, not reusable.
    test()->postJson($url, ['nonce' => $nonce])->assertStatus(422);
});

it('refuses a nonce claimed on a different tenant host', function () {
    [, $nonce] = handoffFor('habru');
    Tenant::factory()->create(['subdomain' => 'woldia']);
    app(CurrentTenant::class)->forget();

    // The tenant is read from the hostname, never from the request body, so a
    // nonce cannot be walked over to another tenant's host.
    test()->postJson(
        'http://woldia.ethr.et/api/v1/auth/session/claim',
        ['nonce' => $nonce],
    )->assertStatus(422);
});

it('refuses an unknown nonce', function () {
    $tenant = Tenant::factory()->create(['subdomain' => 'habru']);
    app(CurrentTenant::class)->forget();

    test()->postJson(
        "http://{$tenant->subdomain}.ethr.et/api/v1/auth/session/claim",
        ['nonce' => bin2hex(random_bytes(32))],
    )->assertStatus(422);
});

it('gives the same answer for every failure, so nonces cannot be probed', function () {
    [$tenant, $nonce] = handoffFor();
    app(CurrentTenant::class)->forget();

    $url = "http://{$tenant->subdomain}.ethr.et/api/v1/auth/session/claim";

    $spent = test()->postJson($url, ['nonce' => $nonce]);
    $spent->assertOk();

    $replayed = test()->postJson($url, ['nonce' => $nonce]);
    $unknown = test()->postJson($url, ['nonce' => bin2hex(random_bytes(32))]);

    // Distinguishing "used" from "never existed" would let an attacker confirm
    // which nonces were real.
    expect($replayed->json())->toEqual($unknown->json());
});

it('puts the nonce in the URL fragment, never the query string', function () {
    $url = SessionHandoff::urlFor('habru', 'abc123');

    // A query parameter would land in nginx access logs and any Referer sent
    // onward — the same reason no credential appears in this app's URLs.
    expect($url)->toContain('#nonce=abc123')
        ->and($url)->not->toContain('?nonce=')
        ->and($url)->toStartWith('http://habru.ethr.et/impersonate/claim');
});

it('needs no handoff at all in single-host development', function () {
    config(['app.domain' => null]);

    // Console and tenant app share an origin there, so the cookie already
    // reaches where it needs to and the ceremony would buy nothing.
    expect(SessionHandoff::required())->toBeFalse();
});
