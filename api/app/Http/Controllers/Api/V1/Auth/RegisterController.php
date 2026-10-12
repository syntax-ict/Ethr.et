<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterTenantRequest;
use App\Http\Resources\TenantResource;
use App\Notifications\OrganisationRegisteredNotification;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Throwable;

class RegisterController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(RegisterTenantRequest $request): JsonResponse
    {
        $result = $this->authService->registerTenant($request->validated());

        // Sign the browser in the way LoginRequest does. Without a session the
        // 15-minute token was all it had, and /auth/refresh needs a live one,
        // so a new admin was signed out mid-setup (production QA 2026-10-11, P2).
        if ($request->hasSession()) {
            Auth::guard('web')->login($result['user']);
            $request->session()->regenerate();
        }

        try {
            $result['user']->notify(new OrganisationRegisteredNotification($result['tenant']));
        } catch (Throwable $e) {
            // The organisation exists either way; a mail outage must not turn a
            // completed registration into an error.
            report($e);
        }

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
