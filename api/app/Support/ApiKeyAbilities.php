<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * What a console-minted API key may call, from the abilities it was given.
 *
 * The seven ability names come from StoreApiKeyRequest and were stored and
 * displayed long before anything read them (QA sweep, 2026-10-08, finding 3).
 * Their meaning, fixed here so the console and the API cannot disagree:
 *
 *   read    any GET/HEAD/OPTIONS on the tenant API
 *   write   any other method on the tenant API
 *   employees, attendance, leave, payroll, reports
 *           every method under that route prefix (/api/v1/<module>/...)
 *
 * A module ability is the narrow grant: an integration that syncs attendance
 * gets `attendance` and nothing else. `read` and `write` are the broad ones.
 * Anything else outside the five modules (organisation, holidays, shifts, ...)
 * needs `read` or `write` as the method requires.
 *
 * Some surfaces no key reaches, whatever its abilities: the ones that mint
 * credentials or change who may do what (SESSION_ONLY). A key with `write`
 * could otherwise create another key, a webhook that receives every event, or
 * an admin user — each of which outlives revoking the key that made it
 * (API/webhook end-to-end test, 2026-10-14, D1). Organisation settings are
 * readable but not writable by key: PUT /settings carries the MFA policy.
 */
final class ApiKeyAbilities
{
    public const READ = 'read';

    public const WRITE = 'write';

    /** @var list<string> */
    public const MODULES = ['employees', 'attendance', 'leave', 'payroll', 'reports'];

    /**
     * Route prefixes (/api/v1/<prefix>/...) refused to every API key.
     *
     * @var list<string>
     */
    public const SESSION_ONLY = [
        'admin', 'api-keys', 'auth', 'billing', 'broadcasting', 'maintenance',
        'permissions', 'profile', 'roles', 'scim', 'sso', 'users', 'webhooks',
    ];

    /** @var list<string> */
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * The abilities any one of which allows `$request`.
     *
     * @return list<string>
     */
    public static function requiredFor(Request $request): array
    {
        $allowed = [self::isRead($request) ? self::READ : self::WRITE];

        $module = self::moduleOf($request);
        if ($module !== null) {
            $allowed[] = $module;
        }

        return $allowed;
    }

    /** @param  list<string>  $abilities */
    public static function allows(array $abilities, Request $request): bool
    {
        return array_intersect($abilities, self::requiredFor($request)) !== [];
    }

    /** Whether no API key may make this request, whatever its abilities. */
    public static function sessionOnly(Request $request): bool
    {
        // "Who is this key?" stays answerable: GET /auth/me reveals nothing
        // the key does not already act as.
        if (self::isRead($request) && trim($request->path(), '/') === 'api/v1/auth/me') {
            return false;
        }

        $prefix = self::prefixOf($request);

        return in_array($prefix, self::SESSION_ONLY, true)
            || ($prefix === 'settings' && ! self::isRead($request));
    }

    /** The module prefix the request lives under, or null outside the five. */
    public static function moduleOf(Request $request): ?string
    {
        $module = self::prefixOf($request);

        return $module !== null && in_array($module, self::MODULES, true) ? $module : null;
    }

    private static function prefixOf(Request $request): ?string
    {
        // "api/v1/employees/01M.../documents" → "employees"
        $segments = explode('/', trim($request->path(), '/'));

        return $segments[2] ?? null;
    }

    public static function isRead(Request $request): bool
    {
        return in_array(strtoupper($request->getMethod()), self::READ_METHODS, true);
    }
}
