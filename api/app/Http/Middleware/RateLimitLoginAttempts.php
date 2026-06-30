<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;

class RateLimitLoginAttempts
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        // Only apply to login endpoint
        if (! $request->is('api/v1/auth/login')) {
            return $next($request);
        }

        $key = 'login_attempts:' . $this->getClientIdentifier($request);
        $attempts = $this->limiter->attempts($key);
        $maxAttempts = 5; // 5 attempts
        $decayMinutes = 1; // per minute

        if ($attempts >= $maxAttempts) {
            return response()->json([
                'type' => 'https://ethr.et/errors/rate-limit',
                'title' => 'Too Many Login Attempts',
                'status' => 429,
                'detail' => 'Too many login attempts. Please try again in ' . $this->limiter->availableIn($key) . ' seconds.',
            ], 429);
        }

        $this->limiter->hit($key, $decayMinutes * 60);

        return $next($request);
    }

    private function getClientIdentifier(Request $request): string
    {
        $email = $request->input('email') ?: 'unknown';
        $ip = $request->ip() ?: 'unknown';

        return "{$email}:{$ip}";
    }
}
