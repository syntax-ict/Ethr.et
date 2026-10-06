<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterTenantRequest;
use App\Http\Resources\TenantResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(RegisterTenantRequest $request): JsonResponse
    {
        $result = $this->authService->registerTenant($request->validated());

        return response()->json([
            'user' => [
                'public_id' => $result['user']->public_id,
                'email' => $result['user']->email,
                'role' => $result['user']->role,
            ],
            'tenant' => new TenantResource($result['tenant']),
            'access_token' => $result['access_token'],
            'token_type' => $result['token_type'],
            'expires_in' => $result['expires_in'],
        ], 201);
    }
}
