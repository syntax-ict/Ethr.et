<?php

declare(strict_types=1);

namespace App\Services\Approval;

use App\Enums\CorrectionStatus;
use App\Enums\LeaveStatus;
use App\Enums\OrgScope;
use App\Enums\ProfileUpdateStatus;
use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use App\Models\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a user may decide right now: the single definition behind the
 * approvals queue and the dashboard's "Pending Approvals" count.
 *
 * The count used to be computed separately, as pending leave from direct
 * reports only. An HR admin with no direct reports saw "All caught up!"
 * while the queue had items, and pending corrections and profile changes
 * never reached the dashboard at all (audit N63). Two definitions of the same
 * number drift; one cannot.
 */
final class DecidableApprovals
{
    /** @return Builder<LeaveRequest> */
    public function leave(User $user): Builder
    {
        return $this->scoped(LeaveRequest::query(), $user, 'leave.approve')
            ->where('status', LeaveStatus::PENDING);
    }

    /** @return Builder<AttendanceCorrection> */
    public function corrections(User $user): Builder
    {
        return $this->scoped(AttendanceCorrection::query(), $user, 'correction.approve')
            ->where('status', CorrectionStatus::PENDING);
    }

    /**
     * Profile-update requests are reviewed by HR (`employee.update`), not by
     * the submitter's supervisor, so they are not narrowed by org scope; a
     * reviewer without that permission simply has none.
     *
     * @return Builder<ProfileUpdateRequest>
     */
    public function profileUpdates(User $user): Builder
    {
        $query = ProfileUpdateRequest::query()->where('status', ProfileUpdateStatus::PENDING);

        return $user->hasPermission('employee.update') ? $query : $query->whereRaw('1 = 0');
    }

    /** @return array{leave: int, correction: int, profile_update: int, total: int} */
    public function counts(User $user): array
    {
        $leave = $this->leave($user)->count();
        $correction = $this->corrections($user)->count();
        $profileUpdate = $this->profileUpdates($user)->count();

        return [
            'leave' => $leave,
            'correction' => $correction,
            'profile_update' => $profileUpdate,
            'total' => $leave + $correction + $profileUpdate,
        ];
    }

    /**
     * The list counterpart of the decide-side policies: nothing without the
     * permission, the caller's org scope below ALL, never their own.
     *
     * @template TModel of LeaveRequest|AttendanceCorrection
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function scoped(Builder $query, User $user, string $permission): Builder
    {
        if (! $user->hasPermission($permission)) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->orgScope() !== OrgScope::ALL) {
            $query->whereHas('employee', fn (Builder $q) => $user->scopeAccessibleEmployees($q));
        }

        if ($user->employee_id !== null) {
            $query->where('employee_id', '!=', $user->employee_id);
        }

        return $query;
    }
}
