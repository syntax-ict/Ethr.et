<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\UpdateOvertimeRatesRequest;
use App\Models\AuditLog;
use App\Models\PayrollRule;
use App\Services\CurrentTenant;
use App\Services\Payroll\OvertimeCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Tenant overtime multipliers, stored as a single `category = overtime`
 * payroll rule and read by {@see OvertimeCalculator}. A tenant that has never
 * saved rates inherits the proclamation defaults.
 */
class OvertimeRateController extends Controller
{
    private const RULE_NAME = 'Overtime Rates';

    public function __construct(
        private readonly OvertimeCalculator $overtimeCalculator,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function show(): JsonResponse
    {
        Gate::authorize('payroll.viewConfig');

        $tenantId = $this->currentTenant->id();

        return response()->json([
            'rates' => $this->overtimeCalculator->ratesFor($tenantId),
            'defaults' => OvertimeCalculator::DEFAULT_RATES,
            'is_customized' => $this->rule() !== null,
        ]);
    }

    public function update(UpdateOvertimeRatesRequest $request): JsonResponse
    {
        Gate::authorize('payroll.manageConfig');

        $tenantId = $this->currentTenant->id();
        $rates = array_map(
            static fn (mixed $value): float => (float) $value,
            $request->validated(),
        );

        $rule = $this->rule();
        $before = $rule ? $this->overtimeCalculator->ratesFor($tenantId) : OvertimeCalculator::DEFAULT_RATES;

        if ($rule) {
            $rule->update(['formula' => $rates, 'is_active' => true]);
        } else {
            $rule = PayrollRule::create([
                'name' => self::RULE_NAME,
                'type' => 'rate',
                'category' => 'overtime',
                'formula' => $rates,
                'is_taxable' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]);
        }

        // Reading `$before` memoized the pre-update rates on this instance.
        $this->overtimeCalculator->forgetCachedRates($tenantId);

        AuditLog::record('overtime_rates.updated', $rule, [
            'before' => $before,
            'after' => $rates,
        ]);

        return response()->json([
            'rates' => $this->overtimeCalculator->ratesFor($tenantId),
            'defaults' => OvertimeCalculator::DEFAULT_RATES,
            'is_customized' => true,
        ]);
    }

    private function rule(): ?PayrollRule
    {
        return PayrollRule::query()
            ->where('category', 'overtime')
            ->orderByDesc('id')
            ->first();
    }
}
