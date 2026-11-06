<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\SessionCookie;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class AuthenticateFromCookie
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->bearerToken() && $request->cookie(SessionCookie::NAME) && $this->mayUseCookie($request)) {
            $request->headers->set('Authorization', 'Bearer '.$request->cookie(SessionCookie::NAME));
        }

        return $next($request);
    }

    /**
     * A write signs in with the cookie only when it comes from this host, from
     * an origin Sanctum trusts (and so checks the XSRF token for), or names no
     * origin at all, which a browser always does on a cross-origin write. A
     * write from any other origin is treated as signed out (A-Z review I-04).
     * Reads are unchanged.
     */
    private function mayUseCookie(Request $request): bool
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return true;
        }

        $origin = $request->headers->get('origin') ?: $request->headers->get('referer');
        if ($origin === null || $origin === '') {
            return true;
        }

        $host = parse_url($origin, PHP_URL_HOST);
        $port = parse_url($origin, PHP_URL_PORT);
        if (is_string($host) && strtolower($host.(is_int($port) ? ':'.$port : '')) === strtolower($request->getHttpHost())) {
            return true;
        }

        return EnsureFrontendRequestsAreStateful::fromFrontend($request);
    }
}
