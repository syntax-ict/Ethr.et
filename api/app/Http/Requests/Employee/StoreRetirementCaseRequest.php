<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\RetirementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRetirementCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage', RetirementCase) runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'retirement_type' => ['required', Rule::enum(RetirementType::class)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
