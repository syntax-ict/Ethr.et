<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\DisciplinaryDecision;
use App\Enums\DisciplinarySanctionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecordDisciplinaryDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('manage', DisciplinaryCase) runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::enum(DisciplinaryDecision::class)],
            'decision_notes' => ['nullable', 'string', 'max:5000'],
            'sanction_type' => ['nullable', Rule::enum(DisciplinarySanctionType::class)],
            'sanction_details' => ['nullable', 'string', 'max:2000'],
            'sanction_effective_date' => ['nullable', 'date', 'required_with:sanction_type'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $decision = $this->enum('decision', DisciplinaryDecision::class);

            // A sanction only makes sense once guilt is established — a
            // not-guilty or inconclusive decision that also carries a sanction
            // is a data-entry mistake, not a policy the API should apply.
            if ($decision !== DisciplinaryDecision::GUILTY && $this->filled('sanction_type')) {
                $validator->errors()->add(
                    'sanction_type',
                    __('validation.prohibited', ['attribute' => 'sanction type']),
                );
            }
        });
    }
}
