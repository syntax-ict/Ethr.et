<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\RetirementDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideRetirementCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage', RetirementCase) runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(RetirementDecision::class)],
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
