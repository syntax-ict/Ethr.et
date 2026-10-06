<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one place that decides whether hostname-based tenancy is switched on.
 *
 * `env('APP_DOMAIN')` returns an empty **string** for `APP_DOMAIN=` in a .env
 * file, not null — and "present but blank" is exactly how the variable ships in
 * `.env.example`, because single-host development wants it unset. Guards written
 * as `config('app.domain') === null` therefore read a blank setting as
 * *configured* and switch the whole hostname model on.
 *
 * That is not hypothetical: it turned every admin API call on a tenant host into
 * a 404, narrowed the super-admin exemption, and made impersonation demand a
 * cross-host handoff on localhost — all from an empty string. Normalising in one
 * place is the fix; four call sites each remembering to trim is not.
 */
final class TenancyDomain
{
    /**
     * The bare root domain tenants live under, or null when tenancy is not
     * hostname-driven (single-host development, tests).
     *
     * Trailing and leading dots are tolerated so `.ethr.et` and `ethr.et.`
     * behave identically to `ethr.et`.
     */
    public static function root(): ?string
    {
        $domain = trim((string) config('app.domain'), " \t\n\r\0\x0B.");

        return $domain === '' ? null : strtolower($domain);
    }

    /** Whether the hostname is the authoritative tenant selector. */
    public static function isHostnameAuthoritative(): bool
    {
        return self::root() !== null;
    }
}
