<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Tenant;
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

        // The key authenticates, but the tenant must also be allowed to act.
        // ResolveTenant refuses a suspended, cancelled or trial-expired tenant on
        // every host-resolved route; a SCIM request resolves its tenant from the
        // key instead, so without this a suspended tenant's token kept
        // provisioning and deprovisioning users.
        $tenant = Tenant::find($apiKey->tenant_id);
        if ($tenant === null || ! $tenant->isActive()) {
            return $this->error('This tenant account is not active', 403);
        }

        $apiKey->update(['last_used_at' => now()]);

        $this->currentTenant->set($tenant);

        return $next($request);
    }

    private function unauthorized(string $detail): Response
    {
        return $this->error($detail, 401);
    }

    private function error(string $detail, int $status): Response
    {
        return response()->json([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'],
            'detail' => $detail,
            'status' => $status,
        ], $status);
    }
}
