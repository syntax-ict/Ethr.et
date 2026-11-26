<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ImpersonationRestricted;
use App\Http\Controllers\Api\V1\ApiKey\ApiKeyController;
use App\Http\Controllers\Api\V1\Auth\MfaSetupController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Api\V1\Employee\BankDetailController;
use App\Http\Controllers\Api\V1\Employee\EmployeeController;
use App\Http\Controllers\Api\V1\Onboarding\ConfigurationController;
use App\Http\Controllers\Api\V1\Onboarding\MigrationController;
use App\Http\Controllers\Api\V1\Payroll\PayrollController;
use App\Http\Controllers\Api\V1\Profile\ProfileController;
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
        // Pay is released and paid by the organisation, never by the platform.
        // The domain code refuses these too (PayrollEngine, BankExportService,
        // EmployeeBankDetail), so a route added later is covered; naming them
        // here refuses before any work and says which action it was.
        PayrollController::class.'@approve',
        PayrollController::class.'@markPaid',
        PayrollController::class.'@bankExport',
        PayrollController::class.'@downloadBankExport',
        BankDetailController::class.'@store',
        BankDetailController::class.'@update',
    ];

    /** The columns of a staged person that name a bank account. */
    private const BANK_COLUMNS = ['bank_name', 'bank_branch', 'bank_account'];

    /**
     * Actions an operator may use, except for the fields named here.
     *
     * The organisation's kind in law decides which statutory rules apply and so
     * who its pension schedule is addressed to, and the employment framework is
     * the law the organisation says governs its staff; the four statutory settings
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
                'employment_framework',
            ],
            SettingsController::class.'@update' => array_map(
                static fn (string $key): string => 'settings.'.$key,
                array_values(StatutorySettingsBridge::SETTINGS),
            ),
            EmployeeController::class.'@store' => ['outside_employment_framework', 'employment_framework_note'],
            EmployeeController::class.'@update' => ['outside_employment_framework', 'employment_framework_note'],
            ProfileController::class.'@update' => ['bank_name', 'bank_account_number'],
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
        // Sanctum's can() treats '*' as matching any ability, so a normal
        // token (abilities: ['*']) would also match 'impersonation'.
        // sessionActive() checks the abilities list directly instead.
        if (ImpersonationToken::sessionActive($request->user())) {
            $action = $request->route()?->getActionName();

            if ($request->isMethod('delete') || in_array($action, self::BLOCKED_ACTIONS, true)) {
                throw new ImpersonationRestricted($action ?? $request->method());
            }

            $fields = $this->blockedFieldsCarried($request, $action);
            if ($fields !== []) {
                throw new ImpersonationRestricted((string) $action, $fields);
            }
        }

        return $next($request);
    }

    /**
     * The refused fields this request carries, by name.
     *
     * @return list<string>
     */
    private function blockedFieldsCarried(Request $request, ?string $action): array
    {
        if ($action === null) {
            return [];
        }

        $named = array_values(array_filter(
            self::blockedFields()[$action] ?? [],
            static fn (string $field): bool => $request->has($field),
        ));

        return [...$named, ...$this->bankColumnsCarried($request, $action)];
    }

    /**
     * Bank columns inside the rows of a staged list, or the accepted
     * differences of a reviewed one. Request::has() does not expand
     * `rows.*.bank_name`, so the rows are read. A list read from a grid is
     * not visible here, by column name; its commit is refused instead, where
     * the staged rows are (WorkforceMigrationService::commitSlice).
     *
     * @return list<string>
     */
    private function bankColumnsCarried(Request $request, string $action): array
    {
        if ($action === MigrationController::class.'@stageFromRows') {
            $found = [];
            foreach ((array) $request->input('rows', []) as $row) {
                foreach (self::BANK_COLUMNS as $column) {
                    if (is_array($row) && isset($row[$column]) && trim((string) (is_scalar($row[$column]) ? $row[$column] : 'x')) !== '') {
                        $found[$column] = true;
                    }
                }
            }

            return array_map(static fn (string $column): string => 'rows.*.'.$column, array_keys($found));
        }

        if ($action === MigrationController::class.'@updateRow') {
            $accepted = array_map('strval', array_filter((array) $request->input('accept', []), 'is_scalar'));

            return array_map(
                static fn (string $field): string => 'accept.'.$field,
                array_values(array_intersect($accepted, ['bank', ...self::BANK_COLUMNS])),
            );
        }

        return [];
    }
}
