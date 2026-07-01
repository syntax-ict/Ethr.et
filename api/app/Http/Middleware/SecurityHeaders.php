<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Prevent clickjacking attacks
        $response->header('X-Frame-Options', 'DENY');

        // Prevent MIME type sniffing
        $response->header('X-Content-Type-Options', 'nosniff');

        // Enable XSS protection in older browsers
        $response->header('X-XSS-Protection', '1; mode=block');

        // Enforce HTTPS (1 year including subdomains)
        $response->header('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');

        // Content Security Policy (strict, no inline scripts)
        $csp = "default-src 'self'; "
            ."script-src 'self' 'nonce-".base64_encode(random_bytes(16))."'; "
            ."style-src 'self' 'nonce-".base64_encode(random_bytes(16))."'; "
            ."img-src 'self' data: https:; "
            ."font-src 'self'; "
            ."connect-src 'self' https:; "
            ."frame-ancestors 'none'; "
            ."base-uri 'self'; "
            ."form-action 'self'";

        $response->header('Content-Security-Policy', $csp);

        // Referrer policy
        $response->header('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions policy (disable unnecessary features)
        $response->header('Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()'
        );

        return $response;
    }
}
