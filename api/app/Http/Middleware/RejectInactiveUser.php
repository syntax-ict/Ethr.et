<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only an active user may use the API.
 *
 * The login, OTP and SSO endpoints refused anyone whose status was not
 * `active`, and nothing after that did: a token issued before a user was
 * deactivated — by an admin, or by the identity provider over SCIM — kept
 * working, and POST /auth/refresh would mint a new one from it. A user
 * deprovisioned by their employer kept API access indefinitely.
 */
final class RejectInactiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status !== 'active') {
            // A bearer token is revoked; under session-cookie auth there is
            // none (the current token is a TransientToken), so nothing to do.
            $tokenId = PersonalAccessToken::currentIdFor($user);
            if ($tokenId !== null) {
                $user->tokens()->whereKey($tokenId)->delete();
            }

            return response()->json([
                'type' => 'https://ethr.et/errors/account-inactive',
                'title' => 'Unauthenticated',
                'status' => 401,
                'detail' => __('auth.account_inactive'),
            ], 401)->withHeaders(['Content-Type' => 'application/problem+json']);
        }

        return $next($request);
    }
}
