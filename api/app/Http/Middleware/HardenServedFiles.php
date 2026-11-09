<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stored files served by the framework's signed route (`storage.local`, at
 * `/api/files/{path}`) leave as downloads, with their type not to be sniffed.
 *
 * That route sits outside the api group, so SecurityHeaders never sees it,
 * and the framework answers `inline`: a stored file opened from a link was
 * rendered by the browser on this origin. Its sandbox policy blocks script,
 * but whether a host keeps that header is not something this repository can
 * verify (review 2026-11-09). `attachment` does not stop an `<img>` from
 * showing a photo; it only stops the browser rendering the file as a page.
 */
final class HardenServedFiles
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! $request->routeIs('storage.*') || ! $response->isSuccessful()) {
            return $response;
        }

        $name = basename((string) $request->route('path'));
        $fallback = (string) preg_replace('/[^A-Za-z0-9._-]+/', '', $name);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $fallback === '' ? 'file' : $fallback,
        ));

        return $response;
    }
}
