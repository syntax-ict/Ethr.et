<?php

declare(strict_types=1);

namespace App\Http\Requests\Payroll;

use Illuminate\Foundation\Http\FormRequest;

class ListPayDifferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'in:pending,open,failed,settled,dismissed,superseded'],
            'employee' => ['nullable', 'string', 'max:26'],
            'for_run' => ['nullable', 'string', 'max:26'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
