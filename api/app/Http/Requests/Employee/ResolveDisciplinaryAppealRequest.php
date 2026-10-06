<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\DisciplinaryAppealStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveDisciplinaryAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage', DisciplinaryCase) runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Only the two resolved outcomes are valid input — PENDING is the
            // appeal's own starting state, never something this endpoint sets.
            'outcome' => [
                'required',
                Rule::in([
                    DisciplinaryAppealStatus::UPHELD->value,
                    DisciplinaryAppealStatus::DENIED->value,
                ]),
            ],
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
