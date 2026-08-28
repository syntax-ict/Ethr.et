<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\PersonnelActionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StorePersonnelActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('create', PersonnelAction) runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PersonnelActionType::class)],
            'effective_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:effective_date'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:2000'],

            // At least one of these must produce an actual change — enforced in
            // the service, which alone knows the employee's current values.
            'position_public_id' => ['nullable', 'string', 'exists:positions,public_id'],
            'grade_public_id' => ['nullable', 'string', 'exists:grades,public_id'],
            'department_public_id' => ['nullable', 'string', 'exists:departments,public_id'],
            'branch_public_id' => ['nullable', 'string', 'exists:branches,public_id'],
            'new_salary_cents' => ['nullable', 'integer', 'min:0'],
            'salary_step' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->enum('type', PersonnelActionType::class);

            // Acting/delegation/secondment are time-boxed by definition; an
            // open-ended one is almost always a data-entry mistake.
            if ($type?->isTemporary() && ! $this->filled('end_date')) {
                $validator->errors()->add('end_date', __('personnel.end_date_required_for_temporary'));
            }
        });
    }
}
