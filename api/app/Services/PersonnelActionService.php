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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a civil-service personnel action and applies its effect.
 *
 * A permanent action (promotion, transfer, step increment, …) updates the
 * employee's substantive record and captures a before/after snapshot in the
 * action. A temporary action (acting, delegation, secondment) is recorded with
 * the same snapshot but leaves the substantive posting untouched.
 *
 * A permanent action dated after today, in the organisation's own zone, is
 * held until that date and applied by ApplyDuePersonnelActionsJob. It used to
 * apply on the spot, so a promotion effective next month raised this month's
 * pay. An employee has at most one such scheduled change at a time, because
 * the change is worked out against the record as it stands now.
 */
class PersonnelActionService
{
    /**
     * @param  array<string, mixed>  $data  Validated request data.
     *
     * @throws ValidationException when the action would change nothing.
     */
    /**
     * Whether recording this action would change the employee's pay: a new
     * salary, a step, or a grade whose scale sets one. The controller asks it
     * so such an action needs employee.updateFinancial, as a direct salary
     * edit does.
     *
     * @param  array<string, mixed>  $data  Validated request data.
     */
    public function wouldChangePay(Employee $employee, array $data): bool
    {
        [, $apply] = $this->resolveChanges($employee, $data);

        return array_key_exists('salary_cents', $apply) || array_key_exists('salary_step', $apply);
    }

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

        $effective = Carbon::parse($data['effective_date'])->toDateString();
        $scheduled = ! $type->isTemporary() && $effective > EmployeeTransitionService::today();

        return DB::transaction(function () use ($employee, $type, $data, $changes, $apply, $recordedBy, $scheduled) {
            // Locked so two requests cannot both find no scheduled change.
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first();

            $pending = $type->isTemporary() ? null : $this->scheduledFor($employee);

            if ($pending !== null) {
                throw ValidationException::withMessages([
                    'effective_date' => [__('personnel.already_scheduled', [
                        'date' => $pending->effective_date->toDateString(),
                    ])],
                ]);
            }

            $action = PersonnelAction::create([
                'employee_id' => $employee->id,
                'type' => $type->value,
                'effective_date' => $data['effective_date'],
                'end_date' => $data['end_date'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'reason' => $data['reason'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'changes' => $changes,
                'pending_values' => $scheduled ? $apply : null,
                'is_temporary' => $type->isTemporary(),
                'applied_at' => $scheduled ? null : now(),
                'recorded_by' => $recordedBy,
            ]);

            // A temporary posting must not overwrite the person's real job.
            if (! $type->isTemporary() && ! $scheduled) {
                $employee->forceFill($apply)->save();
            }

            AuditLog::record('employee.personnel_action', $employee, [
                'action_type' => $type->value,
                'temporary' => $type->isTemporary(),
                'scheduled' => $scheduled,
                'changes' => $changes,
                'reference_number' => $action->reference_number,
            ]);

            return $action;
        });
    }

    /**
     * Apply every scheduled action of the current organisation whose
     * effective date has come, oldest first. Safe to run as often as wanted:
     * each action is locked and re-checked before it is written.
     *
     * @return int how many were applied
     */
    public function applyDue(): int
    {
        $due = PersonnelAction::query()
            ->whereNull('applied_at')
            ->whereDate('effective_date', '<=', EmployeeTransitionService::today())
            ->orderBy('effective_date')
            ->orderBy('id')
            ->pluck('id');

        $applied = 0;

        foreach ($due as $id) {
            $applied += DB::transaction(function () use ($id): int {
                $action = PersonnelAction::query()->whereKey($id)->lockForUpdate()->first();

                if ($action === null || ! $action->isScheduled()) {
                    return 0;
                }

                // A person removed since keeps their record as it was left.
                $employee = Employee::withTrashed()->find($action->employee_id);

                if ($employee !== null && ! $employee->trashed()) {
                    $employee->forceFill($action->pending_values ?? [])->save();
                }

                $action->forceFill(['applied_at' => now(), 'pending_values' => null])->save();

                if ($employee !== null) {
                    AuditLog::record('employee.personnel_action_applied', $employee, [
                        'action' => $action->public_id,
                        'action_type' => $action->type->value,
                        'effective_date' => $action->effective_date->toDateString(),
                        'changes' => $action->changes,
                        'employee_removed' => $employee->trashed(),
                    ]);
                }

                return 1;
            });
        }

        return $applied;
    }

    /**
     * Withdraw a scheduled action before it applies. An applied action is
     * history and stays; the way to undo it is another action.
     *
     * @throws ValidationException when the action has already applied
     */
    public function cancel(PersonnelAction $action, Employee $employee): void
    {
        DB::transaction(function () use ($action, $employee): void {
            $locked = PersonnelAction::query()->whereKey($action->getKey())->lockForUpdate()->first();

            if ($locked === null || ! $locked->isScheduled()) {
                throw ValidationException::withMessages([
                    'action' => [__('personnel.not_scheduled')],
                ]);
            }

            AuditLog::record('employee.personnel_action_cancelled', $employee, [
                'action' => $locked->public_id,
                'action_type' => $locked->type->value,
                'effective_date' => $locked->effective_date->toDateString(),
                'changes' => $locked->changes,
            ]);

            $locked->delete();
        });
    }

    private function scheduledFor(Employee $employee): ?PersonnelAction
    {
        return PersonnelAction::query()
            ->where('employee_id', $employee->id)
            ->whereNull('applied_at')
            ->first();
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
