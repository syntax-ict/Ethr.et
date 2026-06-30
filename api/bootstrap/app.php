<?php

declare(strict_types=1);

use App\Http\Middleware\CapPagination;
use App\Http\Middleware\RateLimitLoginAttempts;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SecurityHeaders::class,
            SetLocale::class,
            ResolveTenant::class,
            CapPagination::class,
            RateLimitLoginAttempts::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
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
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
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
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
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
        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, Request $request) {
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
