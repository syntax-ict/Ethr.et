<?php

declare(strict_types=1);

namespace App\Http\Requests\Recruitment;

use App\Enums\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Hiring from an approved offer. The start date defaults to the offer's.
 * Probation is chosen by HR, never assumed: whether and how long it applies
 * depends on the organisation's employment framework.
 */
class HireApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'hire_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.now()->addYear()->toDateString()],
            'status' => ['nullable', 'string', Rule::in([EmployeeStatus::HIRED->value, EmployeeStatus::PROBATION->value])],
            'probation_end_date' => ['nullable', 'required_if:status,probation', 'date_format:Y-m-d'],
        ];
    }
}
