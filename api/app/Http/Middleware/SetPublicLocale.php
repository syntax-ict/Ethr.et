<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale for the public landing page.
 *
 * Distinct from SetLocale because the inputs are different, not because the
 * output is. SetLocale serves an API client that has already chosen a language
 * and says so in `Accept-Language`; a landing page is reached by a stranger
 * following a link, and the best available signal is usually the organisation's
 * own default rather than the browser's.
 *
 * Order matters: a visitor's explicit `?lang=` beats everything, then the
 * tenant's `default_locale`, then the browser, then English. Putting the tenant
 * ahead of `Accept-Language` is the deliberate part — an Ethiopian school's
 * page should open in Amharic for a visitor whose browser happens to be
 * configured in English, while still honouring anyone who asks otherwise.
 *
 * Must run after ResolveTenant, which is what puts a tenant in CurrentTenant
 * for the second step to read.
 */
class SetPublicLocale
{
    /** Locales with complete backend translations — mirrors SetLocale::SUPPORTED. */
    private const SUPPORTED = ['en', 'am'];

    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->fromQuery($request)
            ?? $this->fromTenant()
            ?? $this->fromHeader($request)
            ?? 'en';

        app()->setLocale($locale);

        return $next($request);
    }

    private function fromQuery(Request $request): ?string
    {
        $lang = $request->query('lang');

        return is_string($lang) ? $this->normalize($lang) : null;
    }

    private function fromTenant(): ?string
    {
        return $this->normalize($this->currentTenant->get()?->default_locale);
    }

    private function fromHeader(Request $request): ?string
    {
        return $this->normalize($request->header('Accept-Language'));
    }

    /**
     * A supported locale code, or null.
     *
     * Takes the first two characters so `am-ET`, `en-GB` and a full
     * `Accept-Language` list all reduce to a language this application has
     * translations for. Anything else returns null so the caller falls through
     * to the next signal rather than being pinned to English by a header it
     * could not read.
     */
    private function normalize(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $code = strtolower(substr(trim($value), 0, 2));

        return in_array($code, self::SUPPORTED, true) ? $code : null;
    }
}
