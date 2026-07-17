<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChartOfAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'accounts' => ['required', 'array'],
            'accounts.*.key' => ['required', 'string'],
            'accounts.*.account_code' => ['required', 'string', 'max:20'],
            'accounts.*.account_name' => ['required', 'string', 'max:100'],
        ];
    }
}
