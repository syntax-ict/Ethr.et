<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Http\Requests\Employee\Concerns\ResolvesBulkEmployeeIds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class BulkArchiveRequest extends FormRequest
{
    use ResolvesBulkEmployeeIds;

    public function authorize(): bool
    {
        return Gate::allows('employee.delete');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_EMPLOYEES],
            'employee_ids.*' => ['required', 'string', 'distinct', 'exists:employees,public_id'],
            'confirm' => ['nullable', 'boolean'],
        ];
    }

    /**
     * A person already archived is not an unknown id: archiving the same
     * selection twice answers that they are archived already, not a 422.
     */
    public function withValidator(Validator $validator): void
    {
        $this->refuseUnknownEmployeeIds($validator, withArchived: true);
    }
}
