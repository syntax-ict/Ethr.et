<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Http\Requests\Organization\Concerns\ValidatesPositionAccess;
use App\Services\Access\PositionAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePositionRequest extends FormRequest
{
    use ValidatesPositionAccess;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'min:2', 'max:255'],
            'title_am' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'access_role' => ['sometimes', 'nullable', 'string', Rule::in(PositionAccess::MAPPABLE)],
        ];
    }
}
