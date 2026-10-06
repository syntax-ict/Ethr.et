<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * The naming convention that carries a super admin's identity through an
 * impersonation session.
 *
 * The impersonating admin is not recoverable from the token itself — it belongs
 * to the tenant admin being impersonated — so their id rides along in the token
 * name. Three unrelated places read it back: exiting impersonation (to restore
 * the admin's own session), audit records (to tag every action with
 * `impersonated_by`), and the middleware that blocks destructive actions. They
 * used to share nothing but a repeated string literal, where a change in one
 * place would have quietly stopped the audit tagging in another.
 */
final class ImpersonationToken
{
    /**
     * Distinguishes an impersonation session from an ordinary one.
     *
     * Granted alongside '*', so it must always be checked by name — a wildcard
     * `tokenCan()` matches everything, including this.
     */
    public const ABILITY = 'impersonation';

    private const PREFIX = 'impersonation:';

    public static function nameFor(int $impersonatorId): string
    {
        return self::PREFIX.$impersonatorId;
    }

    /**
     * The super admin behind an impersonation token name, or null if the name
     * is not one of ours.
     */
    public static function impersonatorId(?string $tokenName): ?int
    {
        if ($tokenName === null || ! str_starts_with($tokenName, self::PREFIX)) {
            return null;
        }

        $id = substr($tokenName, strlen(self::PREFIX));

        return ctype_digit($id) ? (int) $id : null;
    }
}
