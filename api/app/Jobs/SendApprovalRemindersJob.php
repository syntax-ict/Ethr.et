<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CorrectionStatus;
use App\Enums\LeaveStatus;
use App\Enums\OvertimeRequestStatus;
use App\Enums\ProfileUpdateStatus;
use App\Models\ApprovalInstance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\ProfileUpdateRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalReminderNotification;
use App\Services\Approval\ApprovalEngine;
use App\Services\CurrentTenant;
use App\Services\Profile\ProfileUpdateRequestService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Nudges approvers about requests that have been sitting for more than 48 hours
 * (PHASE_05 S26: "ApprovalReminderNotification → approver after 48h").
 *
 * `ApprovalReminderNotification` had existed since Phase 5 with a mail template
 * and a preference toggle, but no caller — it could never fire. This job is that
 * caller.
 *
 * One notification per approver, not one per stale item: an approver returning
 * from leave to fifteen pending requests should get a single "you have 15 waiting"
 * rather than fifteen separate mails, which is how reminders become noise people
 * filter away.
 */
class SendApprovalRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    /** Hours a request may wait before its approver is reminded. */
    public const THRESHOLD_HOURS = 48;

    public function __construct(private readonly int $tenantId) {}

    public function handle(CurrentTenant $currentTenant): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $currentTenant->set($tenant);

        $cutoff = CarbonImmutable::now()->subHours(self::THRESHOLD_HOURS);

        // approver user id => list of submission timestamps
        /** @var array<int, array<int, CarbonImmutable>> $waiting */
        $waiting = [];

        $this->collectLeave($cutoff, $waiting);
        $this->collectCorrections($cutoff, $waiting);
        $this->collectProfileUpdates($cutoff, $waiting);
        $this->collectOvertime($cutoff, $waiting);

        $this->escalateOverdueSteps();

        if ($waiting === []) {
            return;
        }

        foreach ($waiting as $userId => $timestamps) {
            $user = User::find($userId);
            if (! $user instanceof User) {
                continue;
            }

            $oldest = min(array_map(static fn (CarbonImmutable $t) => $t->getTimestamp(), $timestamps));
            $oldestHours = (int) CarbonImmutable::createFromTimestamp($oldest)->diffInHours(CarbonImmutable::now());

            $user->notify(new ApprovalReminderNotification(count($timestamps), $oldestHours));
        }

        Log::info('SendApprovalReminders: reminders dispatched', [
            'tenant_id' => $this->tenantId,
            'approvers' => count($waiting),
        ]);
    }

    /**
     * Leave and corrections route to the employee's supervisor.
     *
     * @param  array<int, array<int, CarbonImmutable>>  $waiting
     */
    private function collectLeave(CarbonImmutable $cutoff, array &$waiting): void
    {
        $requests = LeaveRequest::query()
            ->where('status', LeaveStatus::PENDING)
            ->where('created_at', '<=', $cutoff)
            ->with('employee.supervisor.user', 'approvalInstance')
            ->get();

        foreach ($requests as $request) {
            if ($this->addForChain($request, $cutoff, $waiting)) {
                continue;
            }
            $this->addFor($request->employee, $request->created_at, $waiting);
        }
    }

    /**
     * @param  array<int, array<int, CarbonImmutable>>  $waiting
     */
    private function collectCorrections(CarbonImmutable $cutoff, array &$waiting): void
    {
        $corrections = AttendanceCorrection::query()
            ->where('status', CorrectionStatus::PENDING)
            ->where('created_at', '<=', $cutoff)
            ->with('employee.supervisor.user', 'approvalInstance')
            ->get();

        foreach ($corrections as $correction) {
            if ($this->addForChain($correction, $cutoff, $waiting)) {
                continue;
            }
            $this->addFor($correction->employee, $correction->created_at, $waiting);
        }
    }

    /**
     * Overtime asked for in advance routes like a correction: to the chain's
     * current approvers, else the supervisor.
     *
     * @param  array<int, array<int, CarbonImmutable>>  $waiting
     */
    private function collectOvertime(CarbonImmutable $cutoff, array &$waiting): void
    {
        $requests = OvertimeRequest::query()
            ->where('status', OvertimeRequestStatus::PENDING)
            ->where('created_at', '<=', $cutoff)
            ->with('employee.supervisor.user', 'approvalInstance')
            ->get();

        foreach ($requests as $request) {
            if ($this->addForChain($request, $cutoff, $waiting)) {
                continue;
            }
            $this->addFor($request->employee, $request->created_at, $waiting);
        }
    }

    /**
     * Profile updates are reviewed by whoever holds `employee.update`, not by the
     * submitter's supervisor, so they fan out to that group instead.
     *
     * @param  array<int, array<int, CarbonImmutable>>  $waiting
     */
    private function collectProfileUpdates(CarbonImmutable $cutoff, array &$waiting): void
    {
        $pending = ProfileUpdateRequest::query()
            ->where('status', ProfileUpdateStatus::PENDING)
            ->where('created_at', '<=', $cutoff)
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $reviewers = User::where('tenant_id', $this->tenantId)
            ->get()
            ->filter(fn (User $u) => $u->hasPermission('employee.update'));

        $decisions = app(ProfileUpdateRequestService::class);

        foreach ($pending as $request) {
            if ($this->addForChain($request, $cutoff, $waiting)) {
                continue;
            }

            foreach ($reviewers as $reviewer) {
                // Not a change the reviewer may decide, so not one the queue
                // lists for them: the reminder counts what the queue shows.
                if ($decisions->refusal($request, $reviewer) !== null) {
                    continue;
                }

                $waiting[$reviewer->id][] = CarbonImmutable::instance($request->created_at);
            }
        }
    }

    /**
     * A request on a configured approval chain is waiting on its current
     * step's approvers, not on the supervisor, and has waited since the step
     * began rather than since it was filed. False when the request is on the
     * built-in single step, which keeps the reminder it always had.
     *
     * @param  array<int, array<int, CarbonImmutable>>  $waiting
     */
    private function addForChain(LeaveRequest|AttendanceCorrection|ProfileUpdateRequest|OvertimeRequest $request, CarbonImmutable $cutoff, array &$waiting): bool
    {
        $engine = app(ApprovalEngine::class);
        $approvers = $engine->currentApprovers($request);

        if ($approvers === null) {
            return false;
        }

        $instance = $engine->instanceOf($request);
        $since = $instance === null ? null : $engine->stepEnteredAt($instance);

        if ($since === null || CarbonImmutable::instance($since)->greaterThan($cutoff)) {
            return true;
        }

        foreach ($approvers as $approver) {
            $waiting[$approver->id][] = CarbonImmutable::instance($since);
        }

        return true;
    }

    /**
     * A step that names one person and has waited past its time limit is
     * told to someone above them, once (ApprovalEngine::escalateIfOverdue).
     */
    private function escalateOverdueSteps(): void
    {
        $engine = app(ApprovalEngine::class);

        ApprovalInstance::query()
            ->where('status', ApprovalInstance::STATUS_PENDING)
            ->whereNotNull('current_approver_user_id')
            ->with('approvable')
            ->get()
            ->each(fn (ApprovalInstance $instance) => $engine->escalateIfOverdue($instance));
    }

    /**
     * `$employee` arrives typed as a bare Model because the LeaveRequest and
     * AttendanceCorrection relations carry no generics; narrow it here rather than
     * widening the relation signatures, which the PHPStan baseline depends on.
     *
     * @param  array<int, array<int, CarbonImmutable>>  $waiting
     */
    private function addFor(?Model $employee, mixed $submittedAt, array &$waiting): void
    {
        if (! $employee instanceof Employee || $submittedAt === null) {
            return;
        }

        $supervisor = $employee->supervisor;
        $approver = $supervisor instanceof Employee ? $supervisor->user : null;

        if (! $approver instanceof User) {
            return;
        }

        $waiting[$approver->id][] = CarbonImmutable::instance($submittedAt);
    }
}
