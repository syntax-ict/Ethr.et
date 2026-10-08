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
 * Anything outside the five modules (settings, users, organisation, webhooks,
 * the API keys themselves, ...) needs `read` or `write` as the method requires.
 */
final class ApiKeyAbilities
{
    public const READ = 'read';

    public const WRITE = 'write';

    /** @var list<string> */
    public const MODULES = ['employees', 'attendance', 'leave', 'payroll', 'reports'];

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

    /** The module prefix the request lives under, or null outside the five. */
    public static function moduleOf(Request $request): ?string
    {
        // "api/v1/employees/01M.../documents" → "employees"
        $segments = explode('/', trim($request->path(), '/'));
        $module = $segments[2] ?? null;

        return $module !== null && in_array($module, self::MODULES, true) ? $module : null;
    }

    public static function isRead(Request $request): bool
    {
        return in_array(strtoupper($request->getMethod()), self::READ_METHODS, true);
    }
}
