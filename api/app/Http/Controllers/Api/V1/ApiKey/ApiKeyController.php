<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\ApiKey;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiKey\StoreApiKeyRequest;
use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('apikey.manage');

        $keys = ApiKey::query()
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiKey $k) => [
                'public_id' => $k->public_id,
                'name' => $k->name,
                'key_prefix' => $k->key_prefix,
                'abilities' => $k->abilities,
                'last_used_at' => $k->last_used_at,
                'expires_at' => $k->expires_at,
                'is_active' => $k->isActive(),
                'created_at' => $k->created_at,
            ]);

        return response()->json(['keys' => $keys]);
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        Gate::authorize('apikey.manage');

        $tenant = app(CurrentTenant::class)->get();
        $user = $request->user();
        $plainKey = 'ethr_'.Str::random(40);

        $apiKey = ApiKey::create([
            'tenant_id' => $tenant->id,
            'name' => $request->validated('name'),
            'key_hash' => hash('sha256', $plainKey),
            'key_prefix' => substr($plainKey, 0, 12),
            'abilities' => $request->validated('abilities'),
            'created_by' => $user->id,
            'expires_at' => $request->validated('expires_at'),
        ]);

        AuditLog::record('apikey.created', $apiKey);

        return response()->json([
            'public_id' => $apiKey->public_id,
            'name' => $apiKey->name,
            'key' => $plainKey,
            'abilities' => $apiKey->abilities,
            'expires_at' => $apiKey->expires_at,
            'created_at' => $apiKey->created_at,
        ], 201);
    }

    public function destroy(ApiKey $apiKey): JsonResponse
    {
        Gate::authorize('apikey.manage');

        $apiKey->update(['revoked_at' => now()]);

        AuditLog::record('apikey.revoked', $apiKey);

        return response()->json(null, 204);
    }
}
