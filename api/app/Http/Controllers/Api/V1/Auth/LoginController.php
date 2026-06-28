<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $request->authenticate();

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->status !== 'active') {
            Auth::guard('web')->logout();

            AuditLog::record('user.login_blocked', $user, [
                'reason' => 'account_inactive',
            ]);

            return response()->json([
                'type' => 'https://ethr.et/errors/account-inactive',
                'title' => 'Account Inactive',
                'status' => 403,
                'detail' => __('auth.account_suspended'),
            ], 403);
        }

        $result = $this->authService->login($user);

        return response()->json($result);
    }
}
