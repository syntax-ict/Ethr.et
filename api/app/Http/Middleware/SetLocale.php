<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED = ['en', 'am'];

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(self::fromRequest($request));

        return $next($request);
    }

    /**
     * The supported locale the request asks for, `en` otherwise. Also read by
     * the exception renderer for errors thrown before this middleware runs
     * (an upload over post_max_size is refused by global middleware).
     */
    public static function fromRequest(Request $request): string
    {
        $locale = substr((string) $request->header('Accept-Language', 'en'), 0, 2);

        return in_array($locale, self::SUPPORTED, true) ? $locale : 'en';
    }
}
