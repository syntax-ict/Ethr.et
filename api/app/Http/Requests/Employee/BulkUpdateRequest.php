<?php

declare(strict_types=1);

namespace App\Http\Requests\Employee;

use App\Enums\EmployeeStatus;
use App\Http\Requests\Employee\Concerns\ResolvesBulkEmployeeIds;
use App\Http\Requests\Employee\Concerns\ValidatesRelationTenancy;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Team;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BulkUpdateRequest extends FormRequest
{
    /*
     * MAX_EMPLOYEES (200): each employee is its own transaction with a transition,
     * audit rows and an event, about six queries where it used to be one UPDATE.
     * Capped so a selection stays well inside production's 30-second limit.
     */
    use ResolvesBulkEmployeeIds;
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
            'employee_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_EMPLOYEES],
            'employee_ids.*' => ['required', 'string', 'exists:employees,public_id'],
            'department_id' => ['nullable', 'exists:departments,public_id'],
            'branch_id' => ['nullable', 'exists:branches,public_id'],
            'position_id' => ['nullable', 'exists:positions,public_id'],
            'team_id' => ['nullable', 'exists:teams,public_id'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,public_id'],
            'supervisor_id' => ['nullable', 'exists:employees,public_id'],
            // Must be constrained to the enum, as StoreEmployeeRequest already
            // does. A bare `string` here let any value reach the column, and
            // `status` is cast to EmployeeStatus on read — so writing e.g.
            // "banana" made the row throw a ValueError on every subsequent read,
            // 500ing the employee endpoint and any list containing them. The API
            // is also the only way to correct it, so the record was bricked.
            'status' => ['nullable', 'string', Rule::enum(EmployeeStatus::class)],
            'effective_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
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
            'position_id' => Position::class,
            'team_id' => Team::class,
            'cost_center_id' => CostCenter::class,
            'supervisor_id' => Employee::class,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateRelationTenancy($validator);
        $this->refuseUnknownEmployeeIds($validator);
    }
}
