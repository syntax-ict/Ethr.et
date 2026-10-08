<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a console-minted API key (`Authorization: Bearer ethr_...`).
 *
 * Until 2026-10-09 nothing accepted one: the keys Settings → API keys issued
 * were stored, listed and sold behind the `api_access` plan feature, and
 * answered 401 on every tenant endpoint, because the only reader of ApiKey was
 * ScimAuth, which wants an ability the console cannot grant (QA sweep, finding
 * 3). This runs in the api group right after ResolveTenant and before any
 * route middleware, and makes three decisions:
 *
 *   - The key acts as the user who created it. That is what gives it a tenant,
 *     a role and permissions, and what every existing middleware and policy
 *     expects of `$request->user()`. Sanctum's guard reads the `web` guard
 *     first, so a user set there arrives through `auth:sanctum` unchanged,
 *     with a TransientToken — which EnforceSessionIdleTimeout and
 *     RejectUnverifiedMfaToken already treat as a browser session.
 *   - The key's tenant becomes the resolved tenant when the host named none
 *     (the apex, or a single-host install). When the host or X-Tenant named a
 *     different one, nothing is overridden: EnsureUserBelongsToTenant answers
 *     403 tenant-mismatch, as it does for any session.
 *   - What the key may call is decided later, per route, by
 *     EnforceApiKeyAbilities. This only says who is calling.
 *
 * The creator being inactive, the tenant being suspended, or the key being
 * revoked or expired all refuse. The message never says which: an attacker
 * holding a leaked key learns nothing about its state.
 */
final class AuthenticateApiKey
{
    public const PREFIX = 'ethr_';

    /** Request attribute carrying the authenticated ApiKey. */
    public const ATTRIBUTE = 'api_key';

    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null || ! str_starts_with($token, self::PREFIX)) {
            return $next($request);
        }

        // Pre-authentication, keyed on the presented secret: the hash both
        // selects and authenticates, exactly as ScimAuth does with the same
        // table. `key_hash` is unique.
        $apiKey = ApiKey::withoutGlobalScopes()
            ->where('key_hash', hash('sha256', $token))
            ->first();

        if ($apiKey === null || ! $apiKey->isActive()) {
            return $this->unauthenticated();
        }

        // Tenant carries no tenant scope (global model), so this is a plain find.
        $tenant = Tenant::query()->find($apiKey->tenant_id);
        if ($tenant === null || ! $tenant->isActive()) {
            return $this->unauthenticated();
        }

        // The creator, stated against the key's own tenant: a user row from any
        // other tenant can never be handed a key's identity.
        $user = User::withoutGlobalScopes()
            ->where('tenant_id', $apiKey->tenant_id)
            ->where('status', 'active')
            ->find($apiKey->created_by);

        if ($user === null) {
            return $this->unauthenticated();
        }

        if (! $this->currentTenant->resolved()) {
            $this->currentTenant->set($tenant);
        }

        Auth::guard('web')->setUser($user);
        $request->attributes->set(self::ATTRIBUTE, $apiKey);
        $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    private function unauthenticated(): Response
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/unauthenticated',
            'title' => 'Unauthenticated',
            'status' => 401,
            'detail' => 'Invalid, expired or revoked API key.',
        ], 401)->withHeaders(['Content-Type' => 'application/problem+json']);
    }
}
