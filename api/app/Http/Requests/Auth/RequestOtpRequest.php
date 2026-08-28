<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20'],
            // Read by the ResolveTenant middleware when the request is not on a
            // tenant subdomain and carries no X-Tenant header.
            'tenant' => ['nullable', 'string'],
        ];
    }
}
