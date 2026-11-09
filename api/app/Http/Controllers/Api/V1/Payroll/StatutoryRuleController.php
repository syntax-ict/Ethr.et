<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\StatutoryParameter;
use App\Services\CurrentTenant;
use App\Services\Payroll\StatutoryRules;
use App\Services\Payroll\StatutorySettingsBridge;
use App\Services\Tenancy\ProfileContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The statutory rules an organisation's payroll applies today, each with
 * where it came from (docs/payroll/STATUTORY-PARAMETERS.md), for Settings →
 * Payroll → Statutory rules.
 *
 * A rule the organisation may change is changed through PUT /settings, which
 * writes its own row (StatutorySettingsBridge); this lists them and resets
 * one to the value its profile gives, which deletes that row. A locked rule
 * is the law's and only listed.
 */
class StatutoryRuleController extends Controller
{
    public function __construct(
        private readonly StatutoryRules $rules,
        private readonly ProfileContext $profiles,
    ) {}

    public function index(): JsonResponse
    {
        Gate::authorize('payroll.viewConfig');

        $tenant = app(CurrentTenant::class)->get();
        $today = Carbon::today();
        $profile = $this->profiles->for($tenant->id, $today);
        $resolved = $this->rules->for($tenant, $today);
        $platform = $this->rules->platform($today, $profile['organization_type'], $profile['sector']);

        $data = [];
        foreach ($resolved->all() as $code => $rule) {
            $data[] = $this->present($code, $rule, $platform->describe($code)['value'] ?? null);
        }

        return response()->json([
            'data' => $data,
            'organization_type' => $profile['organization_type'],
            'sector' => $profile['sector'],
            'as_of' => $today->toDateString(),
        ]);
    }

    /**
     * One rule as the settings page shows it.
     *
     * @param  array{value: mixed, source: string, layer: string, enforcement: string, floor_direction: string|null, legal_reference: string|null, confirmed: bool, effective_from: string|null, version: string|null, parameter: string|null}  $rule
     * @return array{code: string, value: mixed, default_value: mixed, layer: string, enforcement: string, floor_direction: string|null, legal_reference: string|null, confirmed: bool, effective_from: string|null, version: string|null, setting_key: string|null, editable: bool, overridden: bool}
     */
    private function present(string $code, array $rule, mixed $default): array
    {
        $settingKey = StatutorySettingsBridge::SETTINGS[$code] ?? null;
        $editable = $rule['enforcement'] !== StatutoryParameter::LOCKED && $settingKey !== null;

        return [
            'code' => $code,
            'value' => $rule['value'],
            'default_value' => $default,
            'layer' => $rule['layer'],
            'enforcement' => $rule['enforcement'],
            'floor_direction' => is_string($rule['floor_direction']) ? $rule['floor_direction'] : null,
            'legal_reference' => is_string($rule['legal_reference']) ? $rule['legal_reference'] : null,
            'confirmed' => (bool) $rule['confirmed'],
            'effective_from' => is_string($rule['effective_from']) ? $rule['effective_from'] : null,
            'version' => is_string($rule['version']) ? $rule['version'] : null,
            'setting_key' => $editable ? $settingKey : null,
            'editable' => (bool) $editable,
            'overridden' => $rule['layer'] === 'tenant',
        ];
    }

    public function destroy(string $code): JsonResponse
    {
        Gate::authorize('payroll.manageConfig');

        $tenant = app(CurrentTenant::class)->get();
        $settingKey = StatutorySettingsBridge::SETTINGS[$code] ?? null;
        $current = $this->rules->for($tenant, Carbon::today())->describe($code);

        abort_if($current === null, 404);
        abort_if($settingKey === null || $current['enforcement'] === StatutoryParameter::LOCKED, 422, __('payroll.statutory_rule_not_resettable'));

        // Read as the cast gives it: the model's schema-derived PHPDoc does
        // not know `settings` is an array.
        $settings = $tenant->getAttribute('settings');
        $settings = is_array($settings) ? $settings : [];
        if (array_key_exists($settingKey, $settings)) {
            unset($settings[$settingKey]);
            $tenant->update(['settings' => $settings]);

            AuditLog::record('statutory_parameter.reset', $tenant, ['code' => $code, 'from' => $current['value']]);
        }

        $this->rules->forget();
        $after = $this->rules->for($tenant->fresh() ?? $tenant, Carbon::today())->describe($code);

        return response()->json([
            'code' => $code,
            'value' => $after['value'] ?? null,
            'layer' => $after['layer'] ?? null,
        ]);
    }
}
