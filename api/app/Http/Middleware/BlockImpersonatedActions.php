<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\ApiKey\ApiKeyController;
use App\Http\Controllers\Api\V1\Auth\MfaSetupController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Api\V1\Onboarding\ConfigurationController;
use App\Http\Controllers\Api\V1\Settings\SettingsController;
use App\Http\Controllers\Api\V1\User\StartingPasswordController;
use App\Services\Auth\ImpersonationToken;
use App\Services\Payroll\StatutorySettingsBridge;
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

    /**
     * Actions an operator may use, except for the fields named here.
     *
     * The organisation's kind in law decides which statutory rules apply and so
     * who its pension schedule is addressed to; the four statutory settings
     * decide how pay is worked out. An operator fixing a name, a timezone or a
     * language for a customer who asked must still be able to, so these are
     * refused by what the request carries, not by the action: a request that
     * names any of them is refused, whatever value it gives. (The settings
     * screens send these keys only when the person changed them.)
     *
     * Keys are dotted paths into the request, as Request::has() reads them.
     *
     * @return array<string, list<string>>
     */
    private static function blockedFields(): array
    {
        return [
            SettingsController::class.'@updateOrganization' => [
                'organization_type',
                'effective_from',
                'cancel_pending_organization_type',
            ],
            SettingsController::class.'@update' => array_map(
                static fn (string $key): string => 'settings.'.$key,
                array_values(StatutorySettingsBridge::SETTINGS),
            ),
            // The setup wizard's apply writes the same two things by another
            // route: the kind, and plan.settings, which is held to what
            // PUT /settings may write. Its screen always sends the kind, so an
            // operator cannot apply it; the organisation's own administrator
            // does. The preview writes nothing and stays open.
            ConfigurationController::class.'@apply' => [
                'organization_type',
                ...array_map(
                    static fn (string $key): string => 'plan.settings.'.$key,
                    array_values(StatutorySettingsBridge::SETTINGS),
                ),
            ],
        ];
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Sanctum's can() treats '*' as matching any ability, so a normal
        // token (abilities: ['*']) would also match 'impersonation'. Check
        // the abilities list directly instead of via can().
        if ($token && in_array(ImpersonationToken::ABILITY, $token->abilities ?? [], true)) {
            $action = $request->route()?->getActionName();

            if ($request->isMethod('delete') || in_array($action, self::BLOCKED_ACTIONS, true) || $this->carriesBlockedField($request, $action)) {
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

    private function carriesBlockedField(Request $request, ?string $action): bool
    {
        $fields = $action === null ? null : (self::blockedFields()[$action] ?? null);

        return $fields !== null && $request->hasAny($fields);
    }
}
