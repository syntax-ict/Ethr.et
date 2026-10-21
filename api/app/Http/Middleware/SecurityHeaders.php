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

        // `headers->set()`, not `header()`: only Laravel's own Response has
        // `header()`, so a streamed or file download (Symfony's
        // StreamedResponse, BinaryFileResponse) returned 500 here. Found by
        // the data export download, the first endpoint to stream a file.

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');

        $csp = "default-src 'none'; "
            ."frame-ancestors 'none'; "
            ."base-uri 'self'; "
            ."form-action 'self'";

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy',
            'accelerometer=(), camera=(self), geolocation=(self), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()'
        );

        return $response;
    }
}
