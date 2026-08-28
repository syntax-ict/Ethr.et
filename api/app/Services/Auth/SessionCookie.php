<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * The single definition of the browser's session cookie.
 *
 * The SPA never sends an Authorization header — AuthenticateFromCookie promotes
 * this cookie to one on the way in. So anything that hands the browser a new
 * identity (login, refresh, impersonation) has to come through here: a caller
 * that mints a token but forgets the cookie leaves the browser authenticated as
 * whoever it was before, which is exactly how impersonation used to silently
 * keep the super admin's own session.
 */
class SessionCookie
{
    public const NAME = 'access_token';

    /**
     * Scoped to the API so the cookie is never attached to Next.js document or
     * asset requests.
     */
    private const PATH = '/api';

    public function issue(string $token, int $ttlSeconds): void
    {
        cookie()->queue(cookie(
            self::NAME,
            $token,
            (int) ceil($ttlSeconds / 60),
            self::PATH,
            null,
            app()->isProduction(),
            true,
            false,
            'Lax',
        ));
    }

    public function clear(): void
    {
        cookie()->queue(cookie()->forget(self::NAME, self::PATH));
    }
}
