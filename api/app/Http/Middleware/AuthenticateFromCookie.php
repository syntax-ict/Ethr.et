<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\SessionCookie;
use Closure;
use Illuminate\Http\Request;

class AuthenticateFromCookie
{
    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->bearerToken() && $request->cookie(SessionCookie::NAME)) {
            $request->headers->set('Authorization', 'Bearer '.$request->cookie(SessionCookie::NAME));
        }

        return $next($request);
    }
}
