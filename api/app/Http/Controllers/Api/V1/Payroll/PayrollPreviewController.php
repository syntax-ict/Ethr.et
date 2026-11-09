<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PreviewPayrollRequest;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Services\CurrentTenant;
use App\Services\Payroll\PayrollEngine;
use App\Support\RequestBudget;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * POST /payroll/preview: a dry run of the period. The same engine a run uses
 * works the payslips out and writes none of them; no run is reserved and no
 * balance moves. Each answer is one page of people, with the statutory rules
 * and the published batch of the law every figure was worked out under.
 * Committing is the existing POST /payroll/process, and approving the run
 * that makes is still the final step.
 */
class PayrollPreviewController extends Controller
{
    public function __invoke(PreviewPayrollRequest $request, PayrollEngine $engine): JsonResponse
    {
        $this->authorize('process', PayrollRun::class);

        $tenant = app(CurrentTenant::class)->get();
        $periodStart = Carbon::parse($request->validated('period_start'));
        $periodEnd = Carbon::parse($request->validated('period_end'));

        $after = $request->validated('after');
        $afterId = is_string($after) ? Employee::query()->where('public_id', $after)->value('id') : null;
        abort_if(is_string($after) && $afterId === null, 404);

        $preview = $engine->preview($tenant->id, $periodStart, $periodEnd, $afterId === null ? null : (int) $afterId, RequestBudget::deadline());

        $payslips = [];
        foreach ($preview['entries'] as $entry) {
            $payslips[] = $this->payslip($entry['employee'], $entry['attributes']);
        }
        $nextAfter = $this->publicIdOf($preview['next_after']);

        AuditLog::record('payroll.previewed', null, [
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'payslips' => count($payslips),
        ]);

        $rules = [];
        foreach ($preview['rules']->all() as $code => $rule) {
            $rules[] = $this->rule($code, $rule);
        }

        return response()->json([
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'organization_type' => $preview['rules']->organizationType,
            'sector' => $preview['rules']->sector,
            'rules' => $rules,
            'income_tax' => [
                'legal_reference' => is_string($preview['tax_basis']['legal_reference'] ?? null) ? $preview['tax_basis']['legal_reference'] : null,
                'effective_from' => is_string($preview['tax_basis']['effective_from'] ?? null) ? $preview['tax_basis']['effective_from'] : null,
                'source' => (string) ($preview['tax_basis']['source'] ?? ''),
            ],
            'pension' => [
                'scheme' => $preview['pension_rule']->scheme,
                'employee_rate' => $preview['pension_rule']->employeeRate,
                'employer_rate' => $preview['pension_rule']->employerRate,
                'legal_reference' => $preview['pension_rule']->legalReference,
                'source' => $preview['pension_rule']->source,
            ],
            'payslips' => $payslips,
            'next_after' => $nextAfter,
        ]);
    }

    /**
     * One payslip as the preview shows it: the amounts and why pension was
     * not taken, if it was not.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{employee: array{public_id: string, name: string, employee_code: string|null}, basic_salary_cents: int, gross_cents: int, income_tax_cents: int, employee_pension_cents: int, employer_pension_cents: int, other_deductions_cents: int, net_cents: int, pension_exempt_reason: string|null}
     */
    private function payslip(Employee $employee, array $attributes): array
    {
        $steps = is_array($attributes['calculation_log']['steps'] ?? null) ? $attributes['calculation_log']['steps'] : [];
        $pension = collect($steps)->firstWhere('step', 'pension');
        $exempt = is_array($pension) && is_string($pension['exempt_reason'] ?? null) ? $pension['exempt_reason'] : null;

        return [
            'employee' => [
                'public_id' => (string) $employee->public_id,
                'name' => (string) $employee->name,
                'employee_code' => is_string($employee->employee_code) ? $employee->employee_code : null,
            ],
            'basic_salary_cents' => (int) $attributes['basic_salary_cents'],
            'gross_cents' => (int) $attributes['gross_cents'],
            'income_tax_cents' => (int) $attributes['income_tax_cents'],
            'employee_pension_cents' => (int) $attributes['employee_pension_cents'],
            'employer_pension_cents' => (int) $attributes['employer_pension_cents'],
            'other_deductions_cents' => (int) $attributes['other_deductions_cents'],
            'net_cents' => (int) $attributes['net_cents'],
            'pension_exempt_reason' => $exempt,
        ];
    }

    /** The employee the next page starts after, by the id the API uses. */
    private function publicIdOf(?int $employeeId): ?string
    {
        if ($employeeId === null) {
            return null;
        }

        $publicId = Employee::query()->whereKey($employeeId)->value('public_id');

        return is_string($publicId) ? $publicId : null;
    }

    /**
     * A statutory rule the preview was worked out under, and where it came from.
     *
     * @param  array{value: mixed, source: string, layer: string, enforcement: string, floor_direction: string|null, legal_reference: string|null, confirmed: bool, effective_from: string|null, version: string|null, parameter: string|null}  $rule
     * @return array{code: string, value: mixed, layer: string, version: string|null, legal_reference: string|null}
     */
    private function rule(string $code, array $rule): array
    {
        return [
            'code' => $code,
            'value' => $rule['value'],
            'layer' => (string) $rule['layer'],
            'version' => is_string($rule['version']) ? $rule['version'] : null,
            'legal_reference' => is_string($rule['legal_reference']) ? $rule['legal_reference'] : null,
        ];
    }
}
