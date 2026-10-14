<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\StorePensionRuleRequest;
use App\Http\Resources\PensionRuleResource;
use App\Models\AuditLog;
use App\Models\PensionRule;
use App\Services\CurrentTenant;
use App\Services\Payroll\PensionCalculator;
use App\Services\Payroll\StatutorySchedules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Pension rules, beside Tax Brackets in Settings → Payroll.
 *
 * The list shows the platform's rules (the law, seeded from the research) and
 * the organisation's own, with their dates, so an administrator sees what is
 * in force and what came before. Adding a rule never edits one: it closes the
 * organisation's previous rule for that scheme the day before the new one
 * starts. A run already approved keeps what it applied — the rule is copied
 * into each entry's calculation_log (convention #11).
 */
class PensionRuleController extends Controller
{
    public function __construct(private readonly PensionCalculator $calculator) {}

    public function index(): JsonResponse
    {
        Gate::authorize('payroll.viewConfig');

        $tenant = app(CurrentTenant::class)->get();
        $scheme = StatutorySchedules::setting($tenant, 'pension_scheme') ?? 'private';

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

        $data = $request->validated();
        $from = Carbon::parse($data['effective_from'])->startOfDay();

        $latest = PensionRule::query()
            ->where('scheme', $data['scheme'])
            ->orderByDesc('effective_from')
            ->first();

        // History stays a line: a rule cannot start on or before the start of
        // the organisation's latest one for the scheme.
        abort_if(
            $latest !== null && $latest->effective_from->greaterThanOrEqualTo($from),
            422,
            __('payroll.pension_rule_not_after_latest', ['date' => $latest?->effective_from->format('Y-m-d')]),
        );

        $rule = DB::transaction(function () use ($data, $from, $latest): PensionRule {
            if ($latest !== null && ($latest->effective_to === null || $latest->effective_to->greaterThanOrEqualTo($from))) {
                $latest->update(['effective_to' => $from->copy()->subDay()->toDateString()]);
            }

            return PensionRule::create([
                'scheme' => $data['scheme'],
                'employee_rate' => $data['employee_rate'],
                'employer_rate' => $data['employer_rate'],
                'ceiling_cents' => $data['ceiling_cents'] ?? null,
                'floor_cents' => $data['floor_cents'] ?? null,
                'base' => $data['base'] ?? 'basic',
                'exempt_non_nationals' => $data['exempt_non_nationals'] ?? true,
                'min_service_days' => $data['min_service_days'] ?? null,
                'legal_reference' => $data['legal_reference'],
                'effective_from' => $from->toDateString(),
            ]);
        });

        AuditLog::record('pension_rule.created', $rule, ['rule' => (new PensionRuleResource($rule))->resolve()]);

        return (new PensionRuleResource($rule))->response()->setStatusCode(201);
    }
}
