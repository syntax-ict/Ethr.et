<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeRetirementCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage', RetirementCase) runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'effective_date' => ['required', 'date', 'after_or_equal:today'],
        ];
    }
}
