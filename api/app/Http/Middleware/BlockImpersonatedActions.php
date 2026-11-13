<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\ApiKey\ApiKeyController;
use App\Http\Controllers\Api\V1\Auth\MfaSetupController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Api\V1\Settings\SettingsController;
use App\Http\Controllers\Api\V1\User\StartingPasswordController;
use App\Services\Auth\ImpersonationToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockImpersonatedActions
{
    /** @var list<string> */
    private const BLOCKED_ACTIONS = [
        PasswordResetController::class.'@change',
        MfaSetupController::class.'@setup',
        MfaSetupController::class.'@enable',
        MfaSetupController::class.'@disable',
        ApiKeyController::class.'@store',
        BillingController::class.'@changePlan',
        // The organisation's address is where its people sign in. An operator
        // looking at an organisation as it sees itself must not move it.
        SettingsController::class.'@updateSubdomain',
        SettingsController::class.'@updateAddress',
        // A sign-in credential for the organisation's own staff. The step-up
        // already asks for the impersonated account's own password, which an
        // operator does not have, but that is the only thing in the way:
        // this refuses by name, as it does for the address above.
        StartingPasswordController::class.'@store',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Sanctum's can() treats '*' as matching any ability, so a normal
        // token (abilities: ['*']) would also match 'impersonation'. Check
        // the abilities list directly instead of via can().
        if ($token && in_array(ImpersonationToken::ABILITY, $token->abilities ?? [], true)) {
            $action = $request->route()?->getActionName();

            if ($request->isMethod('delete') || in_array($action, self::BLOCKED_ACTIONS, true)) {
                return response()->json([
                    'type' => 'https://ethr.et/errors/impersonation-restricted',
                    'title' => 'Action Restricted',
                    'status' => 403,
                    'detail' => __('auth.impersonation_restricted'),
                ], 403)->header('Content-Type', 'application/problem+json');
            }
        }

        return $next($request);
    }
}
