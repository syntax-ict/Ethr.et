<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class StoreTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'department_public_id' => ['nullable', 'string', 'size:26'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
