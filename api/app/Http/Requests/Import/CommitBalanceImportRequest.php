<?php

declare(strict_types=1);

namespace App\Http\Requests\Import;

use Illuminate\Foundation\Http\FormRequest;

class CommitBalanceImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1', 'max:5000'],
            'rows.*' => ['array'],
            'rows.*.employee_code' => ['nullable', 'string', 'max:50'],
            'rows.*.line' => ['nullable', 'integer'],
        ];
    }
}
