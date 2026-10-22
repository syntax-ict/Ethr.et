<?php

declare(strict_types=1);

namespace App\Services\Approval;

use App\Enums\ApprovalRequestType;
use App\Enums\ApproverKind;
use App\Enums\OrgScope;
use App\Enums\UserRole;
use App\Models\ApprovalAction;
use App\Models\ApprovalInstance;
use App\Models\ApprovalWorkflow;
use App\Models\AttendanceCorrection;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ProfileUpdateRequest;
use App\Models\User;
use App\Notifications\ApprovalEscalatedNotification;
use App\Notifications\AttendanceCorrectionRequestedNotification;
use App\Notifications\LeaveRequestedNotification;
use App\Notifications\ProfileUpdateRequestedNotification;
use App\Traits\SendsNotifications;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides *when* a request is decided; the module's decision service still
 * decides *what that means* (balances, payroll locks, webhooks, notices).
 *
 * A request runs the workflow that applied when it was submitted, snapshotted
 * onto its instance. A request with no instance — everything submitted before
 * the engine shipped — is on the built-in default: one step, anyone holding
 * the request's permission who reaches the employee, which is the rule the
 * policies apply. So an organisation that configures nothing decides exactly
 * as before (docs/APPROVAL-ENGINE-DESIGN.md).
 *
 * The policies are unchanged and still run first, so every step's approver
 * must hold the permission and reach the employee. The engine narrows that to
 * the step's approver, and never lets one person approve two steps of the
 * same request.
 */
final class ApprovalEngine
{
    use SendsNotifications;

    private const DEFAULT_SLA_HOURS = 48;

    /** Start a pending request on the workflow that applies to it now. */
    public function start(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable, ?User $submittedBy): ?ApprovalInstance
    {
        if (! $this->isPending($approvable)) {
            return null;
        }

        $type = ApprovalRequestType::fromModel($approvable);
        $workflow = $this->workflowFor($approvable, $type);

        $instance = $this->createInstance(
            $approvable,
            $workflow,
            $workflow === null ? $this->defaultSnapshot() : $this->snapshotOf($workflow),
            $submittedBy?->id,
        );

        $this->enter($instance, 1, $approvable, $type);

        return $instance;
    }

    /**
     * Whether $user may act on the request's current step. The policy has
     * already checked the permission and reach; this adds the step.
     */
    public function isCurrentApprover(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable, User $user, bool $approving): bool
    {
        $instance = $this->instanceOf($approvable);

        if ($instance === null || $instance->status !== ApprovalInstance::STATUS_PENDING) {
            return true;
        }

        if ($approving && $this->hasApproved($instance, $user->id)) {
            return false;
        }

        if ($instance->current_approver_user_id !== null) {
            return $instance->current_approver_user_id === $user->id;
        }

        if ($instance->current_approver_role !== null) {
            return $user->role->value === $instance->current_approver_role;
        }

        return true;
    }

    /**
     * Narrow a query of pending requests to those whose current step $user
     * may approve: the SQL counterpart of isCurrentApprover().
     *
     * @template TModel of LeaveRequest|AttendanceCorrection|ProfileUpdateRequest
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function onlyCurrentApprover(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('approvalInstance')
            ->orWhereHas('approvalInstance', fn (Builder $instance) => $instance
                ->where(fn (Builder $step) => $step
                    ->where(fn (Builder $anyone) => $anyone
                        ->whereNull('current_approver_user_id')
                        ->whereNull('current_approver_role'))
                    ->orWhere('current_approver_user_id', $user->id)
                    ->orWhere('current_approver_role', $user->role->value))
                ->whereDoesntHave('actions', fn (Builder $a) => $a
                    ->where('actor_user_id', $user->id)
                    ->where('action', ApprovalAction::APPROVE))));
    }

    /**
     * Record an approval of the current step. True when it was the last step
     * and the caller should now apply the decision; false when the request
     * moved on to the next step and stays pending.
     */
    public function approve(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable, User $by): bool
    {
        $instance = $this->instanceOf($approvable) ?? $this->startDefault($approvable);
        $type = ApprovalRequestType::fromModel($approvable);

        $this->record($instance, $by, ApprovalAction::APPROVE);

        if ($instance->current_position < $instance->stepCount()) {
            $next = $instance->current_position + 1;
            $nextUser = $this->enter($instance, $next, $approvable, $type);

            AuditLog::record('approval.step_approved', $approvable, [
                'step' => $next - 1,
                'steps' => $instance->stepCount(),
                'approved_by' => $by->id,
            ]);

            if ($nextUser !== null) {
                $this->notify($nextUser, $this->requestedNotification($approvable));
            }

            return false;
        }

        $instance->update([
            'status' => ApprovalInstance::STATUS_APPROVED,
            'current_approver_user_id' => null,
            'current_approver_role' => null,
        ]);

        return true;
    }

    /** The notice a step's approver gets, the same one the first step's gets. */
    private function requestedNotification(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): Notification
    {
        if ($approvable instanceof LeaveRequest) {
            return new LeaveRequestedNotification($approvable);
        }

        if ($approvable instanceof AttendanceCorrection) {
            return new AttendanceCorrectionRequestedNotification($approvable);
        }

        $approvable->loadMissing('employee');
        $employee = $approvable->getRelation('employee');

        return new ProfileUpdateRequestedNotification($employee, [$approvable->field_name]);
    }

    public function reject(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable, User $by, string $reason): void
    {
        $instance = $this->instanceOf($approvable) ?? $this->startDefault($approvable);

        $this->record($instance, $by, ApprovalAction::REJECT, $reason);

        $instance->update([
            'status' => ApprovalInstance::STATUS_REJECTED,
            'current_approver_user_id' => null,
            'current_approver_role' => null,
        ]);
    }

    /**
     * Close an instance whose request left pending some other way: the
     * employee cancelled it, or a path outside the engine decided it.
     */
    public function close(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): void
    {
        $instance = $this->instanceOf($approvable);

        if ($instance === null || $instance->status !== ApprovalInstance::STATUS_PENDING || $this->isPending($approvable)) {
            return;
        }

        $status = $this->statusValue($approvable);

        $instance->update([
            'status' => in_array($status, [ApprovalInstance::STATUS_APPROVED, ApprovalInstance::STATUS_REJECTED], true)
                ? $status
                : ApprovalInstance::STATUS_CANCELLED,
            'current_approver_user_id' => null,
            'current_approver_role' => null,
        ]);
    }

    /**
     * Who may decide the request's current step, for reminders. Null when the
     * request is on the built-in single step, so the caller keeps reminding
     * whoever it always has; otherwise exactly the people the inbox lists it
     * for, which on a chain is rarely the supervisor.
     *
     * @return Collection<int, User>|null
     */
    public function currentApprovers(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): ?Collection
    {
        $instance = $this->instanceOf($approvable);

        if ($instance === null
            || $instance->status !== ApprovalInstance::STATUS_PENDING
            || $instance->workflow_snapshot['workflow_public_id'] === null) {
            return null;
        }

        $type = ApprovalRequestType::fromModel($approvable);

        $candidates = User::query()
            ->where('tenant_id', $approvable->tenant_id)
            ->where('status', 'active')
            ->when($instance->current_approver_user_id !== null, fn (Builder $q) => $q->whereKey($instance->current_approver_user_id))
            ->when($instance->current_approver_role !== null, fn (Builder $q) => $q->where('role', $instance->current_approver_role))
            ->get();

        return $candidates
            ->filter(fn (User $user): bool => $this->eligible($user, $instance, $approvable, $type))
            ->values();
    }

    /** When the request reached its current step: submission, or the last approval. */
    public function stepEnteredAt(ApprovalInstance $instance): ?CarbonInterface
    {
        $lastApproval = $instance->actions()
            ->where('action', ApprovalAction::APPROVE)
            ->latest('id')
            ->value('created_at');

        return $lastApproval !== null ? Carbon::parse($lastApproval) : $instance->submitted_at;
    }

    /**
     * Tell someone when a step that names one person has waited past its
     * time limit: that person's supervisor, else the organisation's HR
     * admins. Once per step, recorded in the history. Nothing is approved or
     * moved on, but the step opens to everyone who may approve the request,
     * so an absent approver cannot hold it for ever
     * (docs/APPROVAL-ENGINE-DESIGN.md §6).
     *
     * @return list<User> who was told
     */
    public function escalateIfOverdue(ApprovalInstance $instance, ?CarbonInterface $now = null): array
    {
        $approvable = $instance->approvable;

        if (! ($approvable instanceof LeaveRequest || $approvable instanceof AttendanceCorrection || $approvable instanceof ProfileUpdateRequest)
            || $instance->status !== ApprovalInstance::STATUS_PENDING
            || $instance->current_approver_user_id === null) {
            return [];
        }

        $slaHours = (int) ($instance->workflow_snapshot['steps'][$instance->current_position - 1]['sla_hours'] ?? self::DEFAULT_SLA_HOURS);
        $entered = $this->stepEnteredAt($instance);
        $now ??= now();

        if ($entered === null || $entered->greaterThan($now->copy()->subHours($slaHours))) {
            return [];
        }

        $already = $instance->actions()
            ->where('action', 'escalate')
            ->where('position', $instance->current_position)
            ->exists();
        if ($already) {
            return [];
        }

        $approver = User::query()->find($instance->current_approver_user_id);
        $targets = $this->escalationTargets($approver, $approvable, $instance);

        $this->record($instance, null, 'escalate', null, [
            'approver' => $approver?->public_id,
            'notified' => array_map(static fn (User $u): string => $u->public_id, $targets),
            'opened_to' => ApproverKind::PERMISSION->value,
        ]);

        // A step that waits on one absent person would otherwise wait for
        // ever: from here anyone who may approve the request may decide it,
        // under every other rule. The named approver still can.
        $instance->update([
            'current_approver_user_id' => null,
            'current_approver_role' => null,
        ]);
        AuditLog::record('approval.escalated', $approvable, [
            'step' => $instance->current_position,
            'notified' => count($targets),
        ]);

        $employee = $this->employeeOf($approvable);
        foreach ($targets as $target) {
            $this->notify($target, new ApprovalEscalatedNotification(
                ApprovalRequestType::fromModel($approvable)->value,
                $employee->name ?? '',
                $approver->name ?? '',
                (int) $entered->diffInHours($now),
            ));
        }

        return $targets;
    }

    /** @return list<User> */
    private function escalationTargets(?User $approver, LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable, ApprovalInstance $instance): array
    {
        $excluded = array_filter([$approver?->id, $instance->submitted_by]);
        $requesterEmployee = $approvable->employee_id;

        if ($approver !== null) {
            $approver->loadMissing('employee.supervisor.user');
            $boss = $approver->employee?->supervisor?->user;

            if ($boss instanceof User && $boss->status === 'active'
                && ! in_array($boss->id, $excluded, true)
                && $boss->employee_id !== $requesterEmployee) {
                return [$boss];
            }
        }

        return User::query()
            ->where('tenant_id', $approvable->tenant_id)
            ->where('role', UserRole::HR_ADMIN->value)
            ->where('status', 'active')
            ->whereNotIn('id', $excluded)
            ->get()
            ->filter(fn (User $u): bool => $u->employee_id === null || $u->employee_id !== $requesterEmployee)
            ->values()
            ->all();
    }

    /**
     * The one person the request's current step names, or null when the
     * step is open to a role or to everyone holding the permission. The
     * submission notice goes to them rather than to the supervisor.
     */
    public function namedApprover(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): ?User
    {
        $id = $this->instanceOf($approvable)?->current_approver_user_id;

        return $id === null ? null : User::query()->find($id);
    }

    public function instanceOf(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): ?ApprovalInstance
    {
        $approvable->loadMissing('approvalInstance');
        $instance = $approvable->getRelation('approvalInstance');

        return $instance instanceof ApprovalInstance ? $instance : null;
    }

    /**
     * Enter a step: resolve who decides it, falling back to anyone holding
     * the permission when the step names no one who may (rule 2), and record
     * that the fallback was used. Returns the user to notify, if one person.
     */
    private function enter(ApprovalInstance $instance, int $position, LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable, ApprovalRequestType $type): ?User
    {
        $step = $instance->workflow_snapshot['steps'][$position - 1];
        $kind = ApproverKind::tryFrom($step['approver_kind']) ?? ApproverKind::PERMISSION;

        $user = null;
        $role = null;

        if (in_array($kind, [ApproverKind::USER, ApproverKind::SUPERVISOR, ApproverKind::DEPARTMENT_HEAD], true)) {
            $candidate = match ($kind) {
                ApproverKind::USER => $this->userByPublicId($approvable->tenant_id, $step['approver_ref']),
                ApproverKind::SUPERVISOR => $this->supervisorOf($approvable),
                default => $this->departmentHeadOf($approvable),
            };
            $user = $candidate !== null && $this->eligible($candidate, $instance, $approvable, $type) ? $candidate : null;
        } elseif ($kind === ApproverKind::ROLE) {
            $role = $this->roleWithEligibleHolder($step['approver_ref'], $instance, $approvable, $type);
        }

        $instance->update([
            'current_position' => $position,
            'current_approver_user_id' => $user?->id,
            'current_approver_role' => $role,
        ]);

        if ($kind !== ApproverKind::PERMISSION && $user === null && $role === null) {
            $this->record($instance, null, 'fallback', null, ['approver_kind' => $kind->value]);
        }

        return $user;
    }

    /**
     * Never the requester, never whoever filed the request, never someone who
     * already approved a step of it; and they must be able to decide it at all.
     */
    private function eligible(User $user, ApprovalInstance $instance, LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable, ApprovalRequestType $type): bool
    {
        if ($user->status !== 'active' || (int) $user->tenant_id !== (int) $approvable->tenant_id) {
            return false;
        }

        if ($user->employee_id !== null && $user->employee_id === $approvable->employee_id) {
            return false;
        }

        if ($instance->submitted_by !== null && $instance->submitted_by === $user->id) {
            return false;
        }

        if ($this->hasApproved($instance, $user->id) || ! $user->hasPermission($type->permission())) {
            return false;
        }

        if ($user->orgScope() === OrgScope::ALL) {
            return true;
        }

        $employee = $this->employeeOf($approvable);

        return $employee !== null && $user->canAccessEmployee($employee);
    }

    private function roleWithEligibleHolder(?string $ref, ApprovalInstance $instance, LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable, ApprovalRequestType $type): ?string
    {
        $role = UserRole::tryFrom((string) $ref);

        if ($role === null) {
            return null;
        }

        $holders = User::query()
            ->where('tenant_id', $approvable->tenant_id)
            ->where('role', $role->value)
            ->where('status', 'active')
            ->get();

        foreach ($holders as $holder) {
            if ($this->eligible($holder, $instance, $approvable, $type)) {
                return $role->value;
            }
        }

        return null;
    }

    private function workflowFor(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable, ApprovalRequestType $type): ?ApprovalWorkflow
    {
        $workflows = ApprovalWorkflow::query()
            ->where('tenant_id', $approvable->tenant_id)
            ->where('request_type', $type->value)
            ->where('is_active', true)
            ->whereHas('steps')
            ->with('steps')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        if ($workflows->isEmpty()) {
            return null;
        }

        $approvable->loadMissing(['employee.department', 'employee.branch']);
        if ($approvable instanceof LeaveRequest) {
            $approvable->loadMissing('leaveType');
        }

        return $workflows->first(fn (ApprovalWorkflow $workflow) => $this->matches($workflow, $approvable));
    }

    /** Every condition the workflow states must hold; an absent or empty one holds. */
    private function matches(ApprovalWorkflow $workflow, LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): bool
    {
        $conditions = $workflow->conditions ?? [];
        $employee = $this->employeeOf($approvable);

        $values = [
            'department_ids' => $employee?->department?->public_id,
            'branch_ids' => $employee?->branch?->public_id,
            'leave_type_ids' => $approvable instanceof LeaveRequest ? $approvable->leaveType?->public_id : null,
        ];

        foreach ($values as $key => $value) {
            $allowed = $conditions[$key] ?? [];

            if ($allowed !== [] && ! in_array($value, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{workflow_public_id: string|null, name: string|null, steps: list<array{position: int, approver_kind: string, approver_ref: string|null, sla_hours: int, can_return: bool}>} */
    private function snapshotOf(ApprovalWorkflow $workflow): array
    {
        $steps = [];
        $position = 1;

        foreach ($workflow->steps as $step) {
            $steps[] = [
                'position' => $position++,
                'approver_kind' => $step->approver_kind->value,
                'approver_ref' => $step->approver_ref,
                'sla_hours' => $step->sla_hours,
                'can_return' => $step->can_return,
            ];
        }

        return ['workflow_public_id' => $workflow->public_id, 'name' => $workflow->name, 'steps' => $steps];
    }

    /** @return array{workflow_public_id: null, name: null, steps: list<array{position: int, approver_kind: string, approver_ref: null, sla_hours: int, can_return: bool}>} */
    private function defaultSnapshot(): array
    {
        return [
            'workflow_public_id' => null,
            'name' => null,
            'steps' => [[
                'position' => 1,
                'approver_kind' => ApproverKind::PERMISSION->value,
                'approver_ref' => null,
                'sla_hours' => self::DEFAULT_SLA_HOURS,
                'can_return' => false,
            ]],
        ];
    }

    /**
     * A request submitted before the engine shipped is decided on the default
     * workflow; its instance is created when it is decided, so its history
     * is recorded like any other.
     */
    private function startDefault(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): ApprovalInstance
    {
        return $this->createInstance($approvable, null, $this->defaultSnapshot(), null, $approvable->created_at);
    }

    /**
     * @param  array{workflow_public_id: string|null, name: string|null, steps: list<array{position: int, approver_kind: string, approver_ref: string|null, sla_hours: int, can_return: bool}>}  $snapshot
     */
    private function createInstance(
        LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable,
        ?ApprovalWorkflow $workflow,
        array $snapshot,
        ?int $submittedBy,
        mixed $submittedAt = null,
    ): ApprovalInstance {
        $instance = ApprovalInstance::create([
            'tenant_id' => $approvable->tenant_id,
            'approvable_type' => $approvable->getMorphClass(),
            'approvable_id' => $approvable->getKey(),
            'approval_workflow_id' => $workflow?->id,
            'workflow_snapshot' => $snapshot,
            'current_position' => 1,
            'status' => ApprovalInstance::STATUS_PENDING,
            'submitted_by' => $submittedBy,
            'submitted_at' => $submittedAt ?? now(),
        ]);

        $approvable->setRelation('approvalInstance', $instance);

        return $instance;
    }

    /** @param  array<string, mixed>|null  $metadata */
    private function record(ApprovalInstance $instance, ?User $by, string $action, ?string $comment = null, ?array $metadata = null): void
    {
        $instance->actions()->create([
            'tenant_id' => $instance->tenant_id,
            'position' => $instance->current_position,
            'actor_user_id' => $by?->id,
            'action' => $action,
            'comment' => $comment,
            'metadata' => $metadata,
        ]);

        $instance->unsetRelation('actions');
    }

    private function hasApproved(ApprovalInstance $instance, int $userId): bool
    {
        return $instance->actions()
            ->where('actor_user_id', $userId)
            ->where('action', ApprovalAction::APPROVE)
            ->exists();
    }

    private function supervisorOf(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): ?User
    {
        $approvable->loadMissing('employee.supervisor.user');
        $user = $this->employeeOf($approvable)?->supervisor?->user;

        return $user instanceof User ? $user : null;
    }

    private function departmentHeadOf(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): ?User
    {
        $approvable->loadMissing('employee.department.head.user');
        $user = $this->employeeOf($approvable)?->department?->head?->user;

        return $user instanceof User ? $user : null;
    }

    private function userByPublicId(int $tenantId, ?string $publicId): ?User
    {
        if ($publicId === null) {
            return null;
        }

        return User::query()->where('tenant_id', $tenantId)->where('public_id', $publicId)->first();
    }

    private function employeeOf(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): ?Employee
    {
        $approvable->loadMissing('employee');
        $employee = $approvable->getRelation('employee');

        return $employee instanceof Employee ? $employee : null;
    }

    private function isPending(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): bool
    {
        return $this->statusValue($approvable) === ApprovalInstance::STATUS_PENDING;
    }

    private function statusValue(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest $approvable): string
    {
        $status = $approvable->getAttribute('status');

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
