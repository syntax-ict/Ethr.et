<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use App\Enums\EmployeeStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * What the monthly timesheet grid is asked for: the days (`from` and `to`, or a
 * Gregorian `month`), who (unit, shift and status filters, always within the
 * viewer's reach) and which page of people.
 */
class TimesheetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'month' => ['nullable', 'date_format:Y-m'],
            'department' => ['nullable', 'string', 'max:64'],
            'branch' => ['nullable', 'string', 'max:64'],
            'shift' => ['nullable', 'string', 'max:64'],
            'employment_status' => ['nullable', Rule::enum(EmployeeStatus::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
