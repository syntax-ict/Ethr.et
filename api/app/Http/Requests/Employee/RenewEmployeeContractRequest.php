<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\ContractType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RenewEmployeeContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('employee.update') runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Deliberately re-askable rather than defaulted from the expiring
            // contract — a renewal is often also where probation converts to
            // permanent, or a fixed term changes length.
            'contract_type' => ['required', Rule::enum(ContractType::class)],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'salary_cents' => ['nullable', 'integer', 'min:0'],
            'terms' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->enum('contract_type', ContractType::class);

            if ($type?->requiresEndDate() && ! $this->filled('end_date')) {
                $validator->errors()->add('end_date', __('contract.end_date_required'));
            }
        });
    }
}
