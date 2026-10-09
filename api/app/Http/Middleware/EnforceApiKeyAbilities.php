<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Support\ApiKeyAbilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request made with an API key whose abilities do not cover it.
 *
 * Sits in the tenant route group after `auth:sanctum`. A request that did not
 * authenticate by key (a session, an impersonation token) carries no
 * `api_key` attribute and passes untouched: abilities narrow what a key may
 * do, they never widen it — the creator's role and the policies still apply.
 * The semantics of each ability are in ApiKeyAbilities.
 */
final class EnforceApiKeyAbilities
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->attributes->get(AuthenticateApiKey::ATTRIBUTE);
        if (! $apiKey instanceof ApiKey) {
            return $next($request);
        }

        $abilities = (array) $apiKey->abilities;
        if (ApiKeyAbilities::allows($abilities, $request)) {
            return $next($request);
        }

        $needed = ApiKeyAbilities::requiredFor($request);

        return response()->json([
            'type' => 'https://ethr.et/errors/api-key-ability',
            'title' => 'API Key Lacks Ability',
            'status' => 403,
            'detail' => 'This API key needs one of these abilities: '.implode(', ', $needed).'.',
        ], 403)->withHeaders(['Content-Type' => 'application/problem+json']);
    }
}
