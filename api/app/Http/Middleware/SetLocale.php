<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
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

        return Locales::orDefault($locale);
    }
}
