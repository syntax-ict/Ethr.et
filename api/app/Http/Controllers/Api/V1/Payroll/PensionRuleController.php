<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\StorePensionRuleRequest;
use App\Http\Resources\PensionRuleResource;
use App\Models\PensionRule;
use App\Services\CurrentTenant;
use App\Services\Payroll\PensionCalculator;
use App\Services\Payroll\StatutoryRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Pension rules, beside Tax Brackets in Settings → Payroll.
 *
 * The list shows the platform's rules (the law, seeded from the research) and
 * any the organisation saved before rules were locked, with their dates, so
 * an administrator sees what is in force and what came before. A new rule is
 * refused: the rates are the law's. A run already approved keeps what it
 * applied — the rule is copied into each entry's calculation_log
 * (convention #11).
 */
class PensionRuleController extends Controller
{
    public function __construct(private readonly PensionCalculator $calculator) {}

    public function index(): JsonResponse
    {
        Gate::authorize('payroll.viewConfig');

        $tenant = app(CurrentTenant::class)->get();
        $scheme = app(StatutoryRules::class)->for($tenant, Carbon::today())->string('pension.default_scheme');

        // The platform's rules carry no tenant; read them alongside the
        // organisation's own, which the scope already limits to this tenant.
        $platform = PensionRule::query()
            ->withoutGlobalScope('tenant')
            ->whereNull('tenant_id')
            ->get();
        $own = PensionRule::query()->get();

        $rules = $own->concat($platform)
            ->sortBy([['scheme', 'asc'], ['effective_from', 'desc']])
            ->values();

        $inForce = $this->calculator->rule($tenant->id, $scheme, Carbon::today());

        return response()->json([
            'data' => PensionRuleResource::collection($rules),
            'scheme' => $inForce->scheme,
            'in_force' => $inForce->toLog(),
        ]);
    }

    public function store(StorePensionRuleRequest $request): JsonResponse
    {
        Gate::authorize('payroll.manageConfig');

        // The contribution rates are the law's exactly (Proclamations
        // 1268/2022 and 1267/2022). A rule an organisation saved before this
        // is still honoured for its dates and flagged on its readiness
        // checklist; no new one is accepted (decision D6).
        $scheme = (string) $request->validated('scheme');
        $law = $this->calculator->rule(null, $scheme, Carbon::today());

        abort(422, __('payroll.pension_rule_locked', [
            'reference' => $law->legalReference,
        ]));
    }
}
