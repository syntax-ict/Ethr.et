<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approving a run. When the run exempts anyone from pension, the approver
 * must say how many they saw (PayrollController::approve) — the count, not a
 * checkbox, so approving a run that has since changed does not slip through.
 */
class ApprovePayrollRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'acknowledged_pension_exemptions' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
