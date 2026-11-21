<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Rules\ReferenceCode;
use Illuminate\Foundation\Http\FormRequest;

class StoreBankDetailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:60', new ReferenceCode(['bank'])],
            'branch_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:50'],
            'is_primary' => ['nullable', 'boolean'],
        ];
    }
}
