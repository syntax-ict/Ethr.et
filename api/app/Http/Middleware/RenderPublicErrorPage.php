<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Turns every failure on the public surface into the same branded HTML page.
 *
 * Two different things produce a failure here and neither produces HTML on its
 * own. ResolveTenant *returns* an RFC-7807 `application/problem+json` response
 * for an unknown or inactive tenant — correct for the API it was written for,
 * and a wall of JSON in a visitor's browser. The landing controller *throws*
 * NotFoundHttpException, which Laravel renders as its own generic error page.
 *
 * This sits at the head of the public group and normalises both, without
 * touching ResolveTenant. That restraint is deliberate: ResolveTenant is the
 * most security-sensitive middleware in the application, its behaviour is
 * pinned by twenty tests, and "make it content-negotiate" would be a change to
 * shared code in service of one route group.
 *
 * Every failure renders the same page with the same wording, whatever the
 * underlying status. A visitor cannot tell an unknown subdomain from a
 * suspended tenant from one that simply has not published, which is the same
 * reason the controller answers 404 rather than 403. The real status is still
 * sent in the HTTP response, where crawlers and monitoring can read it and
 * curious humans mostly cannot.
 */
class RenderPublicErrorPage
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // Anything that is not an HTTP exception is a genuine fault. Let it
            // through to the real handler so it reaches Sentry with its stack
            // trace, rather than being flattened into a friendly 404.
            if (! $e instanceof HttpExceptionInterface) {
                throw $e;
            }

            return $this->page($e->getStatusCode(), $e->getHeaders());
        }

        if ($response->getStatusCode() >= 400) {
            return $this->page($response->getStatusCode(), $this->carriedHeaders($response));
        }

        return $response;
    }

    /**
     * Headers worth carrying from the original failure onto the rendered page.
     *
     * Only `Retry-After`, and only because the rate limiter sets it and
     * docs/CLAUDE.md requires every throttled response to carry it. Copying the
     * rest would defeat the point of this middleware — the original response is
     * `application/problem+json`, and its Content-Type must not follow the HTML
     * page out.
     *
     * @return array<string, string>
     */
    private function carriedHeaders(Response $response): array
    {
        $retryAfter = $response->headers->get('Retry-After');

        return $retryAfter === null ? [] : ['Retry-After' => $retryAfter];
    }

    /** @param  array<string, string>  $headers */
    private function page(int $status, array $headers = []): Response
    {
        // 404 for anything that is not a rate limit or a server fault. A 403
        // from ResolveTenant (inactive tenant) is deliberately flattened: it
        // would otherwise confirm that the organisation exists.
        $status = match (true) {
            $status === 429 => 429,
            $status >= 500 => $status,
            default => 404,
        };

        return response()->view(
            'public.tenant.unavailable',
            ['status' => $status],
            $status,
            array_intersect_key($headers, ['Retry-After' => true]),
        );
    }
}
