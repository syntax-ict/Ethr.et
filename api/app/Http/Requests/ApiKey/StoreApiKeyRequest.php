<?php

declare(strict_types=1);

namespace App\Http\Requests\ApiKey;

use Illuminate\Foundation\Http\FormRequest;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:read,write,employees,attendance,leave,payroll,reports'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
