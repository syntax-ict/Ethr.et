<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Support\TenancyDomain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Moves an already-minted session between hostnames, once.
 *
 * The session cookie is host-only by design — no `Domain` attribute — and that
 * is precisely what stops habru.ethr.et's cookie from ever being sent to
 * woldia.ethr.et. Impersonation is the one flow that legitimately has to cross
 * that line: it starts on admin.ethr.et and must end with the browser holding a
 * session on {tenant}.ethr.et.
 *
 * The obvious shortcut — SESSION_DOMAIN=.ethr.et — was rejected. It is not
 * scoped to impersonation: it would widen *every* user's session cookie to the
 * whole domain, so an ordinary employee's cookie would be transmitted to every
 * other tenant's host, leaving EnsureUserBelongsToTenant as the only thing
 * between it and their data. That trades a structural guarantee for a
 * single-middleware one to save a redirect.
 *
 * So instead: the platform host mints the token and hands back a nonce; the
 * browser presents that nonce once, on the tenant host, and gets a host-only
 * cookie there.
 *
 * Properties that matter:
 *  - single use — consumed atomically, so a replayed nonce buys nothing;
 *  - short lived — seconds, not minutes; it exists only to survive a redirect;
 *  - bound to its tenant — a nonce issued for habru cannot be claimed on woldia;
 *  - never in a URL query — the caller passes it in the fragment, which browsers
 *    do not send to servers and do not write to access logs or Referer headers.
 */
class SessionHandoff
{
    /** Long enough to survive one redirect, short enough to be useless if leaked. */
    private const TTL_SECONDS = 30;

    private const PREFIX = 'session-handoff:';

    /**
     * Store a token for one cross-host claim and return the nonce naming it.
     */
    public function issue(string $plainTextToken, string $tenantSubdomain, int $sessionSeconds): string
    {
        // 256 bits from a CSPRNG. This is a bearer credential for its lifetime,
        // so it is sized like one rather than like an identifier.
        $nonce = bin2hex(random_bytes(32));

        Cache::put(self::PREFIX.$nonce, [
            'token' => $plainTextToken,
            'tenant' => $tenantSubdomain,
            'session_seconds' => $sessionSeconds,
        ], self::TTL_SECONDS);

        return $nonce;
    }

    /**
     * Redeem a nonce for the tenant it was issued to.
     *
     * Returns null when the nonce is unknown, already used, expired, or was
     * issued for a different tenant than the host doing the claiming — the
     * caller cannot distinguish these, and should not: telling an attacker
     * *why* a nonce failed is free information.
     *
     * @return array{token: string, session_seconds: int}|null
     */
    public function claim(string $nonce, string $tenantSubdomain): ?array
    {
        // Cache::pull is the single-use guarantee: it reads and deletes, so two
        // concurrent claims cannot both succeed.
        $payload = Cache::pull(self::PREFIX.$nonce);

        if (! is_array($payload) || ! isset($payload['token'], $payload['tenant'])) {
            return null;
        }

        if (! hash_equals((string) $payload['tenant'], $tenantSubdomain)) {
            return null;
        }

        return [
            'token' => (string) $payload['token'],
            'session_seconds' => (int) ($payload['session_seconds'] ?? 0),
        ];
    }

    /** Whether a cross-host handoff is needed at all. */
    public static function required(): bool
    {
        // Single-host development serves the console and the tenant app from the
        // same origin, so the cookie already reaches where it needs to and a
        // handoff would be ceremony with no purpose. Via TenancyDomain because
        // `APP_DOMAIN=` is an empty string, and reading that as "configured"
        // makes impersonation demand a cross-host handoff on localhost.
        return TenancyDomain::isHostnameAuthoritative();
    }

    public static function urlFor(string $tenantSubdomain, string $nonce): string
    {
        $domain = TenancyDomain::root();
        $scheme = Str::startsWith((string) config('app.url'), 'http://') ? 'http' : 'https';

        // The nonce rides in the fragment. A query parameter would put a bearer
        // credential into nginx access logs and any Referer sent onward — the
        // same reason credentials never appear in this application's URLs.
        return "{$scheme}://{$tenantSubdomain}.{$domain}/impersonate/claim#nonce={$nonce}";
    }
}
