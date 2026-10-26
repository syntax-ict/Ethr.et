<?php

declare(strict_types=1);

namespace App\Services\NextSteps\Providers;

use App\Enums\NextStepId;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dashboard\ManagerDashboardService;
use App\Services\NextSteps\NextStep;
use App\Services\NextSteps\StepProvider;

/**
 * Today's absentees among the people who report to this person. The figure is
 * the manager dashboard's, so the two agree: a workday with no record and no
 * leave, holiday or rest day to explain it.
 */
final class TeamProvider implements StepProvider
{
    public function __construct(private readonly ManagerDashboardService $manager) {}

    public function steps(User $user, Tenant $tenant): array
    {
        if ($user->employee_id === null || ! $user->hasPermission('leave.viewTeam')) {
            return [];
        }

        if (! Employee::query()->where('supervisor_id', $user->employee_id)->exists()) {
            return [];
        }

        $absent = $this->manager->assemble($user)['team_attendance']['absent'];

        if ($absent === 0) {
            return [];
        }

        return [new NextStep(NextStepId::TeamAbsent, '/attendance/team', (string) $absent, $absent)];
    }
}
