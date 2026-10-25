<?php

declare(strict_types=1);

namespace App\Http\Requests\Attendance;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A work site, created or edited. The branch, employees and departments are
 * named by public id and looked up in the current tenant's scope (in the
 * after-hook, so the contract Scramble reads from rules() stays plain): an id
 * from another organisation fails exactly like one that does not exist.
 */
class SaveAttendanceSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'max:120'],
            'name_am' => ['nullable', 'string', 'max:120'],
            'branch_public_id' => ['nullable', 'string', 'size:26'],
            'latitude' => [$presence, 'numeric', 'between:-90,90'],
            'longitude' => [$presence, 'numeric', 'between:-180,180'],
            'radius_meters' => ['sometimes', 'integer', 'min:10', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
            'employee_public_ids' => ['sometimes', 'array', 'max:2000'],
            'employee_public_ids.*' => ['string', 'size:26'],
            'department_public_ids' => ['sometimes', 'array', 'max:500'],
            'department_public_ids.*' => ['string', 'size:26'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $branch = $this->input('branch_public_id');
            if (is_string($branch) && $branch !== '' && ! Branch::query()->where('public_id', $branch)->exists()) {
                $validator->errors()->add('branch_public_id', __('validation.exists', ['attribute' => 'branch']));
            }

            foreach (['employee_public_ids' => Employee::class, 'department_public_ids' => Department::class] as $field => $model) {
                $ids = array_values(array_unique(array_filter((array) $this->input($field, []), is_string(...))));
                if ($ids !== [] && $model::query()->whereIn('public_id', $ids)->count() !== count($ids)) {
                    $validator->errors()->add($field, __('validation.exists', ['attribute' => str_replace('_', ' ', $field)]));
                }
            }
        });
    }
}
