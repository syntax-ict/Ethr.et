<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use App\Enums\CostSharingStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCostSharingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `total_obligation_cents` and `outstanding_cents` are deliberately absent.
     * The balance is derived by payroll, and letting it be set by hand would
     * silently contradict the `calculation_log` of every run that already
     * deducted against it. Correcting a wrong balance is a cancel-and-recreate,
     * which leaves both rows in the audit trail.
     */
    public function rules(): array
    {
        return [
            'deduction_rate_percent' => ['sometimes', 'numeric', 'gt:0', 'max:100'],
            'status' => ['sometimes', Rule::enum(CostSharingStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
