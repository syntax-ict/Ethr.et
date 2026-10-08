<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'name_am' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
            'parent_public_id' => ['nullable', 'string', 'size:26'],
            'branch_public_id' => ['nullable', 'string', 'size:26'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /**
     * A department may not become its own ancestor.
     *
     * Nothing checked, so a department could be given one of its own
     * descendants as parent. In A -> B -> A neither is a root, and the
     * Structure chart, which starts from the roots, dropped both subtrees
     * while the Departments tab still listed them (audit N72). Walks up from
     * the proposed parent; reaching this department means a cycle. A hook,
     * not a rule, so rules() and the API contract stay as they are.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $department = $this->route('department');
            $parentPublicId = $this->input('parent_public_id');

            if (! $department instanceof Department || ! is_string($parentPublicId)) {
                return;
            }

            $node = Department::where('public_id', $parentPublicId)->first();
            $seen = [];
            while ($node !== null && ! isset($seen[$node->id])) {
                if ($node->id === $department->id) {
                    $validator->errors()->add('parent_public_id', __('organization.department_parent_cycle'));

                    return;
                }
                $seen[$node->id] = true;
                $node = $node->parent_id === null ? null : Department::find($node->parent_id);
            }
        });
    }
}
