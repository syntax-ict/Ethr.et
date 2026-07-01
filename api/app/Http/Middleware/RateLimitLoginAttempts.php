<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RateLimitLoginAttempts
{
    // Per-minute short-burst throttle (5 attempts / minute)
    private const BURST_MAX    = 5;
    private const BURST_DECAY  = 60;   // 1 minute

    // Cumulative lockout throttle (10 attempts → 15 min lockout)
    private const LOCKOUT_MAX   = 10;
    private const LOCKOUT_DECAY = 900; // 15 minutes

    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $request->is('api/v1/auth/login')) {
            return $next($request);
        }

        $id = $this->identifier($request);

        // 1. Lockout check (15-min window, 10 attempts)
        $lockoutKey = "login_lockout:{$id}";
        if ($this->limiter->attempts($lockoutKey) >= self::LOCKOUT_MAX) {
            return $this->tooManyResponse($this->limiter->availableIn($lockoutKey));
        }

        // 2. Burst check (1-min window, 5 attempts)
        $burstKey = "login_burst:{$id}";
        if ($this->limiter->attempts($burstKey) >= self::BURST_MAX) {
            return $this->tooManyResponse($this->limiter->availableIn($burstKey));
        }

        $response = $next($request);

        // 3. On failed authentication (401), increment both counters
        if ($response->getStatusCode() === 401) {
            $this->limiter->hit($burstKey,   self::BURST_DECAY);
            $this->limiter->hit($lockoutKey, self::LOCKOUT_DECAY);
        } else {
            // Successful login: clear counters
            $this->limiter->clear($burstKey);
            $this->limiter->clear($lockoutKey);
        }

        return $response;
    }

    private function tooManyResponse(int $retryAfter): Response
    {
        return response()->json([
            'type'   => 'https://ethr.et/errors/rate-limit',
            'title'  => 'Too Many Login Attempts',
            'status' => 429,
            'detail' => "Too many failed login attempts. Please try again in {$retryAfter} seconds.",
        ], 429)->header('Retry-After', $retryAfter);
    }

    private function identifier(Request $request): string
    {
        $email = mb_strtolower(trim($request->input('email') ?? 'unknown'));
        $ip    = $request->ip() ?? '0.0.0.0';
        return "{$email}:{$ip}";
    }
}
