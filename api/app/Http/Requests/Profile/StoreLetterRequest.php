<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class StoreLetterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', 'min:3', 'max:255'],
            'addressed_to' => ['nullable', 'string', 'max:255'],
            'include_salary' => ['sometimes', 'boolean'],
            'language' => ['sometimes', 'string', 'in:en,am'],
        ];
    }
}
