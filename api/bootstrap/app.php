<?php

declare(strict_types=1);

use App\Exceptions\AttendanceRefused;
use App\Exceptions\DeviceRequestFailed;
use App\Exceptions\PayrollRunRefused;
use App\Http\Controllers\Api\V1\Device\AdmsController;
use App\Http\Controllers\Api\V1\Maintenance\MaintenanceController;
use App\Http\Middleware\AcceptIdempotencyKeyHeader;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\AuthenticateFromCookie;
use App\Http\Middleware\BlockImpersonatedActions;
use App\Http\Middleware\CapPagination;
use App\Http\Middleware\EnforceSessionIdleTimeout;
use App\Http\Middleware\RateLimitLoginAttempts;
use App\Http\Middleware\RejectUnverifiedMfaToken;
use App\Http\Middleware\RequireTenantMfaEnrolment;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\RunBackgroundWorkAfterResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\VerifyMaintenanceToken;
use App\Http\Middleware\VerifyUploadedFiles;
use App\Services\Auth\SessionCookie;
use App\Support\UploadLimit;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
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
        // The install and release steps, OUTSIDE the api group on purpose: that
        // group's `api-global` throttle keeps its counters in the database
        // cache, whose table does not exist until `migrate` — one of these
        // steps — has run. Only VerifyMaintenanceToken guards them. The path
        // stays under /api because that is all .htaccess hands to Laravel.
        then: function (): void {
            Route::prefix('api/v1/maintenance')
                ->middleware(VerifyMaintenanceToken::class)
                ->group(function (): void {
                    Route::post('/key', [MaintenanceController::class, 'key']);
                    Route::post('/create-admin', [MaintenanceController::class, 'createAdmin']);
                    Route::post('/backups', [MaintenanceController::class, 'backups']);
                    Route::post('/backup', [MaintenanceController::class, 'backup']);
                    Route::post('/restore', [MaintenanceController::class, 'restore']);
                    Route::post('/{task}', [MaintenanceController::class, 'run'])
                        ->where('task', 'migrate|seed|optimize|clear|mail-probe');
                });

            // ZKTeco ADMS. The path is fixed in the firmware, so it is not under
            // /api/v1; a device has no session, CSRF token or tenant host, so it
            // is outside the api group too. AdmsDeviceResolver authenticates
            // each request by serial and IP allowlist.
            Route::prefix('iclock')
                ->middleware('throttle:adms')
                ->group(function (): void {
                    foreach (['', '.aspx'] as $ext) {
                        Route::get('/cdata'.$ext, [AdmsController::class, 'handshake']);
                        Route::post('/cdata'.$ext, [AdmsController::class, 'upload']);
                        Route::get('/getrequest'.$ext, [AdmsController::class, 'getRequest']);
                        Route::post('/devicecmd'.$ext, [AdmsController::class, 'commandResult']);
                        Route::get('/ping'.$ext, [AdmsController::class, 'ping']);
                    }
                });
        },
    )
    // Listeners are registered once, explicitly, in AppServiceProvider::boot.
    // Laravel's automatic discovery registered every one of them a second time
    // — `event:list` showed each twice — so every payslip notice, device alert
    // and payroll alert was sent twice, and tenant provisioning ran twice.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SecurityHeaders::class,
            AuthenticateFromCookie::class,
            SetLocale::class,
            ResolveTenant::class,
            AuthenticateApiKey::class,
            CapPagination::class,
            RateLimitLoginAttempts::class,
            VerifyUploadedFiles::class,
            // Must run before validation so the header can satisfy the
            // `idempotency_key` rule the FormRequests enforce.
            AcceptIdempotencyKeyHeader::class,
        ]);

        // After the response is sent: drain the queue a few seconds, at most
        // once a minute, so background work does not wait for the external
        // timer (console QA F23). Appended, so it sees the final response.
        $middleware->api(append: [
            RunBackgroundWorkAfterResponse::class,
        ]);

        $middleware->statefulApi();

        // Since Laravel 11 the api group carries no throttle unless asked, and
        // nothing asked: the named limiters applied only where a route listed
        // one, so every other endpoint — the whole authenticated API, and the
        // public catalogue — took unlimited requests (measured 2026-10-01:
        // 400 of 400 returned 200). Shared hosting puts no per-IP brake in
        // front of PHP, so this is the only one. Route-level limiters still
        // apply on top.
        $middleware->throttleApi('api-global');

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

        // The tenant security policy (audit N6), for the same two reasons: both
        // read `$request->user()`, and both must answer before route-model
        // binding can turn a refused request into a 404. The idle timeout goes
        // ahead of BlockImpersonatedActions so an expired session is told so
        // (401) rather than refused for something else; MFA enrolment goes
        // after RejectUnverifiedMfaToken, which owns a sign-in still owing a code.
        $middleware->prependToPriorityList(
            before: BlockImpersonatedActions::class,
            prepend: EnforceSessionIdleTimeout::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: RequireTenantMfaEnrolment::class,
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

            // Route-model binding throws "No query results for model
            // [App\Models\Employee] 01M…", which named our classes to any
            // caller; the 404 says only that nothing was found. The exception's
            // own headers are kept: a 429 carries Retry-After and the
            // X-RateLimit-* pair, and dropping them left an API client to
            // guess when to try again (API/webhook end-to-end test, 2026-10-14,
            // D2 and D3).
            // Translated, because the app shows this sentence to the person:
            // since 2026-10-20 every failed save surfaces the server's reason,
            // and Laravel's generic 403 was English on an Amharic screen.
            // __() returns a sentence unchanged when it has no translation,
            // and domain messages arrive already translated.
            $detail = __($e->getPrevious() instanceof ModelNotFoundException
                ? 'The requested resource was not found.'
                : ($e->getMessage() ?: 'An error occurred.'));

            // An upload over PHP's post_max_size is refused by global
            // middleware, before SetLocale runs: name the real limit, in the
            // caller's language, instead of Laravel's untranslated sentence.
            if ($e instanceof PostTooLargeException) {
                app()->setLocale(SetLocale::fromRequest($request));
                $detail = __('validation.post_too_large', ['max' => UploadLimit::label(UploadLimit::bytes())]);
            }

            return response()->json([
                'type' => 'https://ethr.et/errors/http',
                'title' => match ($status) {
                    401 => 'Unauthorized',
                    403 => 'Forbidden',
                    404 => 'Not Found',
                    405 => 'Method Not Allowed',
                    409 => 'Conflict',
                    413 => 'Payload Too Large',
                    422 => 'Validation Error',
                    429 => 'Too Many Requests',
                    default => 'Error',
                },
                'status' => $status,
                'detail' => $detail,
            ], $status, [...$e->getHeaders(), 'Content-Type' => 'application/problem+json']);
        });

        // A punch the engine refuses (nothing to close, method off, outside
        // the geofence, employee has left) is the caller's mistake, not a
        // server fault: 422 from every endpoint, not 500 from the ones that
        // did not catch it.
        $exceptions->render(function (AttendanceRefused $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Validation Failed',
                'status' => 422,
                'detail' => $e->getMessage(),
            ], 422);
        });

        // A payroll approval or void the engine refused under its lock: the
        // run moved on, or the balances it was calculated from did. The
        // employees are named by public id so the screen can say whose loan
        // or cost-sharing balance to check before reprocessing.
        $exceptions->render(function (PayrollRunRefused $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $problem = [
                'type' => $e->type,
                'title' => $e->title,
                'status' => 422,
                'detail' => $e->getMessage(),
            ];
            if ($e->employees !== []) {
                $problem['employees'] = $e->employees;
            }

            return response()->json($problem, 422, ['Content-Type' => 'application/problem+json']);
        });

        // A device the request depends on could not be read: unreachable,
        // non-2xx, or not JSON. The fault is upstream of this server, so 502,
        // and the caller can retry once the device is back. The detail is a
        // translated sentence, not the exception's message, which names the
        // vendor and HTTP status for the sync log and the operator.
        $exceptions->render(function (DeviceRequestFailed $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'type' => 'https://ethr.et/errors/device-unreachable',
                'title' => 'Bad Gateway',
                'status' => 502,
                'detail' => __('device.read_failed'),
            ], 502);
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
                'detail' => __($e->getMessage() ?: 'You are not authorized to perform this action.'),
            ], 403);
        });
    })->create();
