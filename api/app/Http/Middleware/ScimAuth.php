<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScimAuth
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            return $this->unauthorized('Bearer token required');
        }

        $apiKey = ApiKey::withoutGlobalScopes()
            ->where('key_hash', hash('sha256', $token))
            ->whereJsonContains('abilities', 'scim')
            ->first();

        if (! $apiKey || ! $apiKey->isActive()) {
            return $this->unauthorized('Invalid or expired SCIM token');
        }

        $apiKey->update(['last_used_at' => now()]);

        $this->currentTenant->set($apiKey->tenant);

        return $next($request);
    }

    private function unauthorized(string $detail): Response
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => $detail,
            'status' => 401,
        ], 401);
    }
}
