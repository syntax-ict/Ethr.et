<?php

declare(strict_types=1);

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class GenerateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source' => ['required', 'string', 'in:employees,attendance,leave,payroll'],
            'columns' => ['sometimes', 'array'],
            'columns.*' => ['string'],
            'filters' => ['sometimes', 'array'],
            'group_by' => ['nullable', 'string'],
            'sort_by' => ['nullable', 'string'],
            'sort_dir' => ['sometimes', 'string', 'in:asc,desc'],
            'format' => ['sometimes', 'string', 'in:csv,pdf'],
        ];
    }
}
