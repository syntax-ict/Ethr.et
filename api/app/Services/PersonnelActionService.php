<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PersonnelActionType;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Grade;
use App\Models\GradeSalaryStep;
use App\Models\PersonnelAction;
use App\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a civil-service personnel action and applies its effect.
 *
 * A permanent action (promotion, transfer, step increment, …) updates the
 * employee's substantive record and captures a before/after snapshot in the
 * action. A temporary action (acting, delegation, secondment) is recorded with
 * the same snapshot but leaves the substantive posting untouched.
 */
class PersonnelActionService
{
    /**
     * @param  array<string, mixed>  $data  Validated request data.
     *
     * @throws ValidationException when the action would change nothing.
     */
    public function record(Employee $employee, array $data, ?int $recordedBy): PersonnelAction
    {
        $type = $data['type'] instanceof PersonnelActionType
            ? $data['type']
            : PersonnelActionType::from($data['type']);

        [$changes, $apply] = $this->resolveChanges($employee, $data);

        if ($changes === []) {
            throw ValidationException::withMessages([
                'changes' => [__('personnel.no_effective_change')],
            ]);
        }

        return DB::transaction(function () use ($employee, $type, $data, $changes, $apply, $recordedBy) {
            $action = PersonnelAction::create([
                'employee_id' => $employee->id,
                'type' => $type->value,
                'effective_date' => $data['effective_date'],
                'end_date' => $data['end_date'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'reason' => $data['reason'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'changes' => $changes,
                'is_temporary' => $type->isTemporary(),
                'recorded_by' => $recordedBy,
            ]);

            // A temporary posting must not overwrite the person's real job.
            if (! $type->isTemporary()) {
                $employee->forceFill($apply)->save();
            }

            AuditLog::record('employee.personnel_action', $employee, [
                'action_type' => $type->value,
                'temporary' => $type->isTemporary(),
                'changes' => $changes,
                'reference_number' => $action->reference_number,
            ]);

            return $action;
        });
    }

    /**
     * Diff the requested new values against the employee's current record.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, array{from: mixed, to: mixed}>, 1: array<string, mixed>}
     *                                                                                         [snapshot for the history, column updates to apply]
     */
    private function resolveChanges(Employee $employee, array $data): array
    {
        $changes = [];
        $apply = [];

        $relations = [
            'position' => [Position::class, 'position_id', 'position_public_id', 'title'],
            'grade' => [Grade::class, 'grade_id', 'grade_public_id', 'name'],
            'department' => [Department::class, 'department_id', 'department_public_id', 'name'],
            'branch' => [Branch::class, 'branch_id', 'branch_public_id', 'name'],
        ];

        foreach ($relations as $key => [$model, $column, $input, $labelField]) {
            if (! array_key_exists($input, $data) || $data[$input] === null) {
                continue;
            }

            /** @var Model|null $target */
            $target = $model::where('public_id', $data[$input])->first();

            if ($target === null) {
                // Tenant-scoped lookup returned nothing — reject rather than
                // silently reaching across tenants via a raw exists rule.
                throw ValidationException::withMessages([
                    $input => [__('personnel.unknown_reference', ['field' => $key])],
                ]);
            }

            if ((int) $employee->{$column} === (int) $target->getKey()) {
                continue; // no actual change on this dimension
            }

            $current = $employee->{$key};
            $changes[$key] = [
                'from' => $current?->{$labelField},
                'to' => $target->getAttribute($labelField),
            ];
            $apply[$column] = $target->getKey();
        }

        // If the target grade has a defined salary scale for the requested
        // step, that scale is authoritative: auto-fill new_salary_cents when
        // it was left blank, or reject a manually-typed amount that doesn't
        // match it. A grade with no scale defined yet falls through
        // unchanged — the free-typed amount below still applies, so tenants
        // that haven't populated a scale aren't blocked.
        if (array_key_exists('salary_step', $data) && $data['salary_step'] !== null) {
            $targetGradeId = $apply['grade_id'] ?? $employee->grade_id;

            if ($targetGradeId !== null) {
                $scaleEntry = GradeSalaryStep::where('grade_id', $targetGradeId)
                    ->where('step', (int) $data['salary_step'])
                    ->first();

                if ($scaleEntry !== null) {
                    if (array_key_exists('new_salary_cents', $data) && $data['new_salary_cents'] !== null) {
                        if ((int) $data['new_salary_cents'] !== $scaleEntry->salary_cents) {
                            throw ValidationException::withMessages([
                                'new_salary_cents' => [__('personnel.salary_step_mismatch', [
                                    'step' => $data['salary_step'],
                                    'expected' => number_format($scaleEntry->salary_cents / 100, 2).' ETB',
                                ])],
                            ]);
                        }
                    } else {
                        $data['new_salary_cents'] = $scaleEntry->salary_cents;
                    }
                }
            }
        }

        // Salary (integer minor units) and step are raw scalars, not relations.
        if (array_key_exists('new_salary_cents', $data) && $data['new_salary_cents'] !== null
            && (int) $data['new_salary_cents'] !== (int) $employee->salary_cents) {
            $changes['salary'] = ['from' => $employee->salary_cents, 'to' => (int) $data['new_salary_cents']];
            $apply['salary_cents'] = (int) $data['new_salary_cents'];
        }

        if (array_key_exists('salary_step', $data) && $data['salary_step'] !== null
            && (int) $data['salary_step'] !== (int) $employee->salary_step) {
            $changes['salary_step'] = ['from' => $employee->salary_step, 'to' => (int) $data['salary_step']];
            $apply['salary_step'] = (int) $data['salary_step'];
        }

        return [$changes, $apply];
    }
}
