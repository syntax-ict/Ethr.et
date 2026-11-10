<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\ImpersonationToken;
use App\Services\Auth\StartingPasswords;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone who signed in with the starting password HR gave their batch may do
 * one thing: choose their own.
 *
 * A starting password is known to HR and to everyone it was handed to
 * (StartingPasswords), so nothing of the person's is shown or changed with
 * it. Every authenticated request is refused with a 403 whose `type` the SPA
 * recognises and answers by opening the change-password screen. The session is
 * issued rather than the sign-in refused for the reason
 * RequireTenantMfaEnrolment gives: `POST /auth/password/change` is
 * authenticated.
 *
 * Reachable while held:
 *
 *   POST /auth/password/change    choose a password, which lifts the hold
 *   POST /auth/logout             leave
 *   POST /auth/refresh            keep the session alive on that screen
 *
 * `GET /auth/me` is not on the list. The change-password screen stands outside
 * the app shell and needs nothing from it, and the profile it returns is the
 * person's own.
 *
 * Fail-closed: the list is explicit, so an endpoint added later is refused.
 *
 * A starting password that has run out is not good for the change either: the
 * account goes back to `invited` here, on the first request after it.
 *
 * Not affected: an impersonation session, for the reason
 * RequireTenantMfaEnrolment gives. The operator passed platform MFA to start
 * it and cannot change the impersonated account's password
 * (BlockImpersonatedActions), so holding them would leave nothing they could do.
 */
final class RequirePasswordChange
{
    public const PROBLEM_TYPE = 'https://ethr.et/errors/password-change-required';

    public const EXPIRED_TYPE = 'https://ethr.et/errors/starting-password-expired';

    /** @var list<string> */
    private const ALLOWED_PATHS = [
        'api/v1/auth/password/change',
        'api/v1/auth/logout',
        'api/v1/auth/refresh',
    ];

    public function __construct(private readonly StartingPasswords $startingPasswords) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->mustChangePassword()) {
            return $next($request);
        }

        // Checked by name, as BlockImpersonatedActions does: the token also
        // holds '*', which a `can()` check would match for anything.
        $token = $user->currentAccessToken();
        if ($token && in_array(ImpersonationToken::ABILITY, $token->abilities ?? [], true)) {
            return $next($request);
        }

        if ($user->startingPasswordExpired()) {
            $this->startingPasswords->expire($user);

            return self::expired();
        }

        if ($request->is(...self::ALLOWED_PATHS)) {
            return $next($request);
        }

        return response()->json([
            'type' => self::PROBLEM_TYPE,
            'title' => 'Password Change Required',
            'status' => 403,
            'detail' => __('auth.password_change_required'),
        ], 403)->withHeaders(['Content-Type' => 'application/problem+json']);
    }

    /** The answer to a starting password used after it ran out, here and at sign-in. */
    public static function expired(): JsonResponse
    {
        return response()->json([
            'type' => self::EXPIRED_TYPE,
            'title' => 'Starting Password Expired',
            'status' => 403,
            'detail' => __('auth.starting_password_expired'),
        ], 403)->withHeaders(['Content-Type' => 'application/problem+json']);
    }
}
