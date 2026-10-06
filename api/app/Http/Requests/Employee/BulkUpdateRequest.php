<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeStatus;
use App\Http\Requests\Employee\Concerns\ValidatesRelationTenancy;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkUpdateRequest extends FormRequest
{
    use ValidatesRelationTenancy {
        withValidator as validateRelationTenancy;
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['required', 'string', 'exists:employees,public_id'],
            'department_id' => ['nullable', 'exists:departments,public_id'],
            'branch_id' => ['nullable', 'exists:branches,public_id'],
            // Must be constrained to the enum, as StoreEmployeeRequest already
            // does. A bare `string` here let any value reach the column, and
            // `status` is cast to EmployeeStatus on read — so writing e.g.
            // "banana" made the row throw a ValueError on every subsequent read,
            // 500ing the employee endpoint and any list containing them. The API
            // is also the only way to correct it, so the record was bricked.
            'status' => ['nullable', 'string', Rule::enum(EmployeeStatus::class)],
        ];
    }

    /**
     * The `exists` rules above go through Laravel's presence verifier, which
     * applies no tenant scope, so another tenant's `public_id` passed them —
     * and `bulkUpdate()` then resolved it through the scoped model, found
     * nothing, and dropped the field (or the employee) without a word: 200,
     * fewer rows changed than were asked for. Audit N11.
     *
     * @return array<string, class-string>
     */
    protected function tenancyCheckedRelations(): array
    {
        return [
            'department_id' => Department::class,
            'branch_id' => Branch::class,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateRelationTenancy($validator);

        $validator->after(function (Validator $validator): void {
            $ids = $this->input('employee_ids');
            if (! is_array($ids)) {
                return;
            }

            $strings = array_filter($ids, 'is_string');

            // Scoped: the tenant global scope applies, as it does to the update.
            $found = Employee::whereIn('public_id', array_values($strings))->pluck('public_id')->all();

            foreach ($strings as $index => $publicId) {
                $key = "employee_ids.{$index}";

                if ($validator->errors()->has($key) || in_array($publicId, $found, true)) {
                    continue;
                }

                // The message the unscoped `exists` rule gives a public_id that
                // exists nowhere, so the response cannot confirm that it exists
                // in another tenant.
                $validator->errors()->add($key, __('validation.exists', [
                    'attribute' => $validator->getDisplayableAttribute($key),
                ]));
            }
        });
    }
}
