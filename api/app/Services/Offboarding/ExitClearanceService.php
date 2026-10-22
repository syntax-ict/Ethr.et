<?php

declare(strict_types=1);

namespace App\Services\Offboarding;

use App\Enums\CostSharingStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeCostSharing;
use App\Models\EmployeeLoan;
use App\Models\EmployeeTransition;
use App\Models\ExitClearance;
use App\Models\ExitClearanceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ExitClearanceTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exit clearance (blueprint L10): the checklist that follows a resignation,
 * termination or retirement.
 *
 * It records; it never withholds. Nothing here blocks the exit, the final
 * pay or the login closing on the leaving date, and the obligations line can
 * be ticked with a balance still owed as long as someone says why.
 */
class ExitClearanceService
{
    /**
     * Start the leaver's checklist from the organisation's template. A second
     * call while one is open returns that one, so a repeated event or a retry
     * never makes two.
     */
    public function start(Employee $employee, ?EmployeeTransition $transition = null, ?int $startedBy = null): ExitClearance
    {
        $open = ExitClearance::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('status', ExitClearance::STATUS_OPEN)
            ->first();

        if ($open !== null) {
            return $open;
        }

        $tenant = Tenant::query()->findOrFail($employee->tenant_id);

        return DB::transaction(function () use ($employee, $transition, $startedBy, $tenant): ExitClearance {
            $clearance = ExitClearance::create([
                'tenant_id' => $employee->tenant_id,
                'employee_id' => $employee->id,
                'employee_transition_id' => $transition?->id,
                'exit_status' => ($transition->to_status ?? $employee->status)->value,
                'leaving_date' => $transition->effective_date ?? $employee->termination_date,
                'status' => ExitClearance::STATUS_OPEN,
                'started_by' => $startedBy,
            ]);

            foreach (ExitClearanceTemplate::for($tenant) as $position => $line) {
                ExitClearanceItem::create([
                    'tenant_id' => $employee->tenant_id,
                    'exit_clearance_id' => $clearance->id,
                    'position' => $position,
                    'key' => $line['key'],
                    'label' => $line['key'] !== null ? ExitClearanceTemplate::label($line['key'], 'en') : (string) $line['label'],
                    'owner' => $line['owner'],
                    'kind' => $line['kind'],
                ]);
            }

            AuditLog::record('exit_clearance.started', $employee, [
                'exit_clearance_id' => $clearance->public_id,
                'exit_status' => $clearance->exit_status,
            ]);

            return $clearance;
        });
    }

    /**
     * Mark a line done or not done. The obligations line needs a note when a
     * balance is still owed: the tick then says why, and the balance stays
     * where finance can see it.
     */
    public function tick(ExitClearanceItem $item, User $user, bool $done, ?string $note): ExitClearanceItem
    {
        $clearance = $item->clearance()->firstOrFail();
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        if ($done && $item->kind === ExitClearanceItem::KIND_OBLIGATIONS && $note === null) {
            $employee = Employee::query()->withTrashed()->findOrFail($clearance->employee_id);

            if ($this->obligations($employee)['total_cents'] > 0) {
                throw ValidationException::withMessages([
                    'note' => __('exit_clearance.note_required_with_balance'),
                ]);
            }
        }

        DB::transaction(function () use ($item, $clearance, $user, $done, $note): void {
            $item->update([
                'done_at' => $done ? Carbon::now() : null,
                'done_by' => $done ? $user->id : null,
                'note' => $note,
            ]);

            $remaining = $clearance->items()->whereNull('done_at')->count();
            $clearance->update([
                'status' => $remaining === 0 ? ExitClearance::STATUS_COMPLETE : ExitClearance::STATUS_OPEN,
                'completed_at' => $remaining === 0 ? ($clearance->completed_at ?? Carbon::now()) : null,
            ]);

            AuditLog::record($done ? 'exit_clearance.item_done' : 'exit_clearance.item_reopened', $clearance, [
                'item_id' => $item->public_id,
                'label' => $item->label,
                'note' => $note,
            ]);
        });

        return $item->refresh();
    }

    /**
     * What the leaver still owes: active loans and active or suspended
     * cost-sharing, in cents.
     *
     * @return array{loans_cents: int, cost_sharing_cents: int, total_cents: int}
     */
    public function obligations(Employee $employee): array
    {
        $loans = (int) EmployeeLoan::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->sum('remaining_cents');

        $costSharing = (int) EmployeeCostSharing::query()
            ->where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)
            ->whereIn('status', [CostSharingStatus::ACTIVE, CostSharingStatus::SUSPENDED])
            ->sum('outstanding_cents');

        return [
            'loans_cents' => $loans,
            'cost_sharing_cents' => $costSharing,
            'total_cents' => (int) ($loans + $costSharing),
        ];
    }

    /** HR ticks any line; finance its own; the leaver's supervisor theirs. */
    public function canTick(User $user, ExitClearanceItem $item, Employee $employee): bool
    {
        if ($user->hasPermission('employee.transition') && $user->canAccessEmployee($employee)) {
            return true;
        }

        return match ($item->owner) {
            ExitClearanceItem::OWNER_FINANCE => $user->hasPermission('payroll.manageLoan'),
            ExitClearanceItem::OWNER_SUPERVISOR => $this->isSupervisor($user, $employee),
            default => false,
        };
    }

    /** Anyone who may tick a line may see the checklist, and so may whoever can see the employee. */
    public function canView(User $user, Employee $employee): bool
    {
        return ($user->hasPermission('employee.view') && $user->canAccessEmployee($employee))
            || $user->hasPermission('payroll.manageLoan')
            || $this->isSupervisor($user, $employee);
    }

    public function isSupervisor(User $user, Employee $employee): bool
    {
        return $user->employee_id !== null && $employee->supervisor_id === $user->employee_id;
    }
}
