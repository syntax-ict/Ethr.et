<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\StorePayrollRuleRequest;
use App\Http\Requests\Payroll\UpdatePayrollRuleRequest;
use App\Http\Resources\PayrollRuleResource;
use App\Models\AuditLog;
use App\Models\PayrollRule;
use App\Services\Payroll\AllowanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Tenant-managed allowance rules consumed by
 * {@see AllowanceService} during payroll processing.
 */
class PayrollRuleController extends Controller
{
    private const CATEGORY = 'allowance';

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('payroll.viewConfig');

        $query = PayrollRule::query()->where('category', self::CATEGORY);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->string('search')->toString().'%');
        }

        if ($request->has('filter.is_active')) {
            $query->where('is_active', $request->boolean('filter.is_active'));
        }

        $query->orderBy('sort_order')->orderBy('id');

        return PayrollRuleResource::collection(
            $query->paginate($request->integer('per_page', 25))
        );
    }

    public function store(StorePayrollRuleRequest $request): JsonResponse
    {
        Gate::authorize('payroll.manageConfig');

        $rule = PayrollRule::create([
            ...$request->validated(),
            'category' => self::CATEGORY,
        ]);

        AuditLog::record('payroll_rule.created', $rule, [
            'name' => $rule->name,
            'type' => $rule->type,
            'formula' => $rule->formula,
        ]);

        return (new PayrollRuleResource($rule))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PayrollRule $payrollRule): PayrollRuleResource
    {
        Gate::authorize('payroll.viewConfig');

        return new PayrollRuleResource($payrollRule);
    }

    public function update(UpdatePayrollRuleRequest $request, PayrollRule $payrollRule): PayrollRuleResource
    {
        Gate::authorize('payroll.manageConfig');

        $before = [
            'name' => $payrollRule->name,
            'type' => $payrollRule->type,
            'formula' => $payrollRule->formula,
            'is_taxable' => $payrollRule->is_taxable,
            'is_active' => $payrollRule->is_active,
        ];

        $payrollRule->update($request->validated());

        AuditLog::record('payroll_rule.updated', $payrollRule, [
            'before' => $before,
            'after' => [
                'name' => $payrollRule->name,
                'type' => $payrollRule->type,
                'formula' => $payrollRule->formula,
                'is_taxable' => $payrollRule->is_taxable,
                'is_active' => $payrollRule->is_active,
            ],
        ]);

        return new PayrollRuleResource($payrollRule);
    }

    public function destroy(PayrollRule $payrollRule): JsonResponse
    {
        Gate::authorize('payroll.manageConfig');

        AuditLog::record('payroll_rule.deleted', $payrollRule, [
            'name' => $payrollRule->name,
            'formula' => $payrollRule->formula,
        ]);

        $payrollRule->delete();

        return response()->json(null, 204);
    }
}
