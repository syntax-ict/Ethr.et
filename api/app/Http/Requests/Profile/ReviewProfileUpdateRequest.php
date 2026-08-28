<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class ReviewProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is the policy's job (`ProfileUpdateRequestPolicy::review`),
        // applied in the controller so the failure is a 403 rather than a 422.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,reject'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
