<?php

declare(strict_types=1);

use App\Http\Middleware\AcceptIdempotencyKeyHeader;
use App\Http\Middleware\AuthenticateFromCookie;
use App\Http\Middleware\BlockImpersonatedActions;
use App\Http\Middleware\CapPagination;
use App\Http\Middleware\PublicSecurityHeaders;
use App\Http\Middleware\RateLimitLoginAttempts;
use App\Http\Middleware\RejectUnverifiedMfaToken;
use App\Http\Middleware\RenderPublicErrorPage;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetPublicLocale;
use App\Http\Middleware\VerifyUploadedFiles;
use App\Services\Auth\SessionCookie;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api/v1',
        then: function (): void {
            // The public tenant surface, registered here rather than folded
            // into the `web` or `api` group so its middleware stack is written
            // out in full at one visible place.
            //
            // What it does NOT include is the point: no `auth:sanctum`, no
            // `AuthenticateFromCookie`, no `statefulApi()`, no
            // `EnsureUserBelongsToTenant`. An anonymous request to a tenant's
            // landing page must not be able to become an authenticated one, and
            // the cheapest way to guarantee that is for the authentication
            // middleware to be absent rather than merely unsatisfied.
            //
            // Order: headers first so they are set even on a thrown 404;
            // RenderPublicErrorPage next so it wraps ResolveTenant, whose
            // tenant-not-found response is JSON and must not reach a browser;
            // ResolveTenant before SetPublicLocale, because the tenant's
            // `default_locale` is the second locale signal and there is no
            // tenant to read it from until ResolveTenant has run.
            Route::middleware([
                PublicSecurityHeaders::class,
                RenderPublicErrorPage::class,
                ResolveTenant::class,
                SetPublicLocale::class,
                'throttle:public-page',
            ])->group(base_path('routes/public.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SecurityHeaders::class,
            AuthenticateFromCookie::class,
            SetLocale::class,
            ResolveTenant::class,
            CapPagination::class,
            RateLimitLoginAttempts::class,
            VerifyUploadedFiles::class,
            // Must run before validation so the header can satisfy the
            // `idempotency_key` rule the FormRequests enforce.
            AcceptIdempotencyKeyHeader::class,
        ]);

        $middleware->statefulApi();

        // Trusted proxies are deliberately NOT configured. This looks like an
        // omission and is not — it was added during the 2026-08-15 domain audit
        // and reverted the same day, because measuring it showed it made things
        // strictly worse.
        //
        // The API is served over FastCGI, not an HTTP proxy_pass. nginx's
        // fastcgi_params already sets REMOTE_ADDR to $remote_addr and HTTPS to
        // $https, so PHP receives nginx's view of the client — in production the
        // real client address — and $request->isSecure() is already correct.
        // nginx sends no X-Forwarded-For on this path at all.
        //
        // Trusting proxies therefore adds no signal and one hazard: any client
        // whose address falls inside a trusted range could set X-Forwarded-For
        // and choose its own identity for rate limiting. Verified during the
        // audit — a request carrying "X-Forwarded-For: 203.0.113.77" was
        // recorded verbatim in login_histories once proxies were trusted, and
        // ignored once they were not.
        //
        // If a future deployment puts an HTTP load balancer in front of nginx,
        // revisit this — and trust X_FORWARDED_FOR/PORT/PROTO only. Never
        // X_FORWARDED_HOST: the Host header is the tenant selector, and trusting
        // a client-supplied override would reintroduce tenant spoofing.

        // The session cookie must NOT be encrypted, because AuthenticateFromCookie
        // reads it long before anything could decrypt it.
        //
        // The resolved api group order is: AuthenticateFromCookie at position 1,
        // EnsureFrontendRequestsAreStateful — which is what pipes the request
        // through EncryptCookies — at position 8. So $request->cookie() handed
        // AuthenticateFromCookie 342 bytes of ciphertext, it set
        // "Authorization: Bearer <ciphertext>", and Sanctum rejected it. Login
        // returned 200 and set the cookie correctly, then every subsequent
        // request 401'd: the SPA (withCredentials, no Authorization header —
        // see src/api/client.ts) could never stay signed in.
        //
        // Excluding it is safe rather than a weakening: the value is an opaque
        // Sanctum token that is verified by hashed lookup server-side, so a
        // forged or tampered value simply fails to authenticate. The cookie
        // keeps httpOnly, SameSite=Lax and (in production) Secure.
        $middleware->encryptCookies(except: [SessionCookie::NAME]);

        // Must run before route-model binding, otherwise a blocked delete on a
        // missing resource leaks a 404 (existence oracle) instead of a 403.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: BlockImpersonatedActions::class,
        );

        // Same reason, and the same trap: without an explicit priority, group
        // middleware order is not guaranteed relative to the authentication
        // middleware, and this one reads `$request->user()`. Run too early it
        // sees no user, finds no token, and waves every request through — which
        // is exactly how it behaved in the test suite while appearing to work
        // when driven by hand.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: RejectUnverifiedMfaToken::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Report to Sentry before the renderers below turn exceptions into RFC-7807
        // responses. No-ops when SENTRY_LARAVEL_DSN is unset, so local and test runs
        // are unaffected. Payload scrubbing lives in config/sentry.php `before_send`.
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Handle HTTP exceptions (4xx, 5xx)
        $exceptions->render(function (HttpException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $status = $e->getStatusCode();

            return response()->json([
                'type' => 'https://ethr.et/errors/http',
                'title' => match ($status) {
                    401 => 'Unauthorized',
                    403 => 'Forbidden',
                    404 => 'Not Found',
                    405 => 'Method Not Allowed',
                    409 => 'Conflict',
                    422 => 'Validation Error',
                    429 => 'Too Many Requests',
                    default => 'Error',
                },
                'status' => $status,
                'detail' => $e->getMessage() ?: 'An error occurred.',
            ], $status);
        });

        // Handle validation exceptions (422)
        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Validation Failed',
                'status' => 422,
                'detail' => 'The given data was invalid.',
                'errors' => $e->errors(),
            ], 422);
        });

        // Handle authentication exceptions (401)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'type' => 'https://ethr.et/errors/unauthenticated',
                'title' => 'Unauthenticated',
                'status' => 401,
                'detail' => 'You are not authenticated.',
            ], 401);
        });

        // Handle authorization exceptions (403)
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'type' => 'https://ethr.et/errors/unauthorized',
                'title' => 'Unauthorized',
                'status' => 403,
                'detail' => $e->getMessage() ?: 'You are not authorized to perform this action.',
            ], 403);
        });
    })->create();
