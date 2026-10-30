<?php

declare(strict_types=1);

namespace App\Http\Requests\Migration;

use App\Services\CurrentTenant;
use App\Support\DepartmentMatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StageRowsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_type' => ['sometimes', 'string', 'in:csv,manual'],
            'source_ref' => ['nullable', 'string', 'max:100'],
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*' => ['array'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.email' => ['nullable', 'email', 'max:255'],
            'rows.*.phone' => ['nullable', 'string', 'max:50'],
            'rows.*.employee_code' => ['nullable', 'string', 'max:50'],
            'rows.*.hire_date' => ['nullable', 'date_format:Y-m-d'],
            'rows.*.department' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A department the organisation does not have is refused here, row by
     * row, rather than becoming a silent null at commit: the person landed
     * unassigned and nobody knew. The message is the employee import's, which
     * matches departments the same way (DepartmentMatch).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $tenantId = app(CurrentTenant::class)->id();
            $rows = $this->input('rows');

            if ($tenantId === null || ! is_array($rows) || $validator->errors()->isNotEmpty()) {
                return;
            }

            $departments = DepartmentMatch::forTenant($tenantId);

            foreach ($rows as $index => $row) {
                $value = is_array($row) ? ($row['department'] ?? null) : null;
                if (! is_string($value) || trim($value) === '' || $departments->id($value) !== null) {
                    continue;
                }

                $validator->errors()->add("rows.{$index}.department", __(
                    $departments->ambiguous($value) ? 'employee.import_relation_ambiguous' : 'employee.import_relation_not_found',
                    ['row' => (int) $index + 1, 'field' => __('employee.import_field_department_code'), 'value' => $value],
                ));
            }
        });
    }
}
