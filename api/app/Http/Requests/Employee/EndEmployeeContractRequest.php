<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\ContractStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EndEmployeeContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate::authorize('employee.update') runs in the controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // ACTIVE/RENEWED are not valid outcomes of "end" — RENEWED only
            // ever happens via the renew endpoint, and ACTIVE is the state
            // being ended, not a result of it.
            'status' => [
                'required',
                Rule::in([
                    ContractStatus::EXPIRED->value,
                    ContractStatus::TERMINATED_EARLY->value,
                ]),
            ],
            'ended_at' => ['nullable', 'date'],
            'end_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
