<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, CurrentTenant $currentTenant): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'public_id' => $user->public_id,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'status' => $user->status,
                'mfa_enabled' => $user->mfa_enabled,
                'locale' => $user->locale,
                'last_login_at' => $user->last_login_at,
            ],
            'tenant' => $currentTenant->resolved()
                ? new TenantResource($currentTenant->get())
                : null,
        ]);
    }
}
