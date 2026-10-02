<?php

declare(strict_types=1);

namespace App\Http\Requests\Accounting;

use App\Services\Accounting\AccountingExportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChartOfAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $keys = array_keys(AccountingExportService::DEFAULTS);

        return [
            'accounts' => ['required', 'array'],
            'accounts.*.key' => ['required', 'string', 'distinct', Rule::in($keys)],
            'accounts.*.account_code' => ['required', 'string', 'max:20'],
            'accounts.*.account_name' => ['required', 'string', 'max:100'],
        ];
    }
}
