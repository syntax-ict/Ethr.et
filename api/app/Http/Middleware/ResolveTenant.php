<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $subdomain = $this->extractSubdomain($host);

        if ($subdomain && $subdomain !== 'api' && $subdomain !== 'admin') {
            $tenant = Tenant::where('subdomain', $subdomain)->first();

            if (! $tenant) {
                return response()->json([
                    'type' => 'https://ethr.et/errors/tenant-not-found',
                    'title' => 'Tenant Not Found',
                    'status' => 404,
                    'detail' => 'No tenant found for this subdomain.',
                ], 404);
            }

            if (! $tenant->isActive()) {
                return response()->json([
                    'type' => 'https://ethr.et/errors/tenant-inactive',
                    'title' => 'Tenant Inactive',
                    'status' => 403,
                    'detail' => 'This tenant account is not active.',
                ], 403);
            }

            $this->currentTenant->set($tenant);
        }

        if (! $this->currentTenant->resolved()) {
            $this->resolveFromBearerToken($request);
        }

        return $next($request);
    }

    private function resolveFromBearerToken(Request $request): void
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return;
        }

        $tokenModel = Sanctum::$personalAccessTokenModel;
        $token = $tokenModel::findToken($bearer);
        if (! $token) {
            return;
        }

        $user = User::withoutGlobalScopes()->find($token->tokenable_id);
        if ($user?->tenant_id) {
            $tenant = Tenant::find($user->tenant_id);
            if ($tenant) {
                $this->currentTenant->set($tenant);
            }
        }
    }

    private function extractSubdomain(string $host): ?string
    {
        $parts = explode('.', $host);

        if (count($parts) >= 3) {
            return $parts[0];
        }

        if (count($parts) === 2 && ! in_array($parts[1], ['test', 'localhost'], true)) {
            return $parts[0];
        }

        return null;
    }
}
