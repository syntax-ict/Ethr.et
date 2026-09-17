<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers for the public HTML surface.
 *
 * A separate middleware rather than a relaxation of SecurityHeaders, and the
 * reason is the whole point of it. SecurityHeaders sends `default-src 'none'`,
 * which is exactly right for a JSON API — the API serves no documents, so a
 * policy that forbids loading anything forbids nothing it needs. Applied to an
 * HTML page that same policy blocks the stylesheet, the fonts and the images,
 * and the page renders as unstyled text.
 *
 * The obvious fix — widening SecurityHeaders until the page renders — would
 * relax the policy on every `/api/v1/*` response in the application to serve
 * one page. So the API's policy stays untouched and the public group gets its
 * own, which is narrow in a different place: `'self'` for the three resource
 * types this page actually loads, and nothing else at all.
 *
 * There is deliberately no `script-src`: `default-src 'none'` already forbids
 * script, and the landing page ships no JavaScript. If that ever changes, the
 * change belongs here and should be conspicuous rather than inherited.
 *
 * `'unsafe-inline'` is absent from `style-src` by design, which is why the
 * page's CSS is a static file rather than an inline `<style>` block. One
 * exception is unavoidable: tenant brand colours arrive as a `style` attribute
 * on a single element, and attribute styles are governed by `style-src-attr`,
 * declared explicitly below so the allowance is visible instead of implied.
 */
class PublicSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set(
            'Strict-Transport-Security',
            'max-age=31536000; includeSubDomains; preload'
        );

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'none'",
            "img-src 'self'",
            "style-src 'self'",
            "style-src-attr 'unsafe-inline'",
            "font-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
        ]));

        $response->headers->set(
            'Permissions-Policy',
            'accelerometer=(), camera=(), geolocation=(), gyroscope=(), '
            .'magnetometer=(), microphone=(), payment=(), usb=()'
        );

        return $response;
    }
}
