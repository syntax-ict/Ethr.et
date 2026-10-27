<?php

declare(strict_types=1);

namespace App\Services\NextSteps\Providers;

use App\Enums\NextStepId;
use App\Enums\PlanFeature;
use App\Models\AttendanceIntegrityFlag;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Device\UnmatchedPunches;
use App\Services\NextSteps\NextStep;
use App\Services\NextSteps\StepProvider;
use App\Services\PlanFeatureService;

/**
 * Day-to-day work that is stuck: open integrity flags, a payroll run that
 * failed or is waiting for a signature, and badges the devices could not
 * match to anyone. These are the steps the dashboard computed in the browser
 * from five requests; each keeps the gate of the endpoint it came from.
 *
 * No row is made from a guess: a step exists only when a count is above zero
 * or the newest payroll run is in a state that needs a hand.
 */
final class OperationsProvider implements StepProvider
{
    public function __construct(
        private readonly PlanFeatureService $plans,
        private readonly UnmatchedPunches $unmatched,
    ) {}

    public function steps(User $user, Tenant $tenant): array
    {
        return [
            ...$this->integrity($user, $tenant),
            ...$this->payroll($user, $tenant),
            ...$this->unmatchedBadges($user, $tenant),
        ];
    }

    /** @return list<NextStep> */
    private function integrity(User $user, Tenant $tenant): array
    {
        if (! $user->hasPermission('attendance.viewConflicts') || ! $this->plans->allows($tenant, PlanFeature::Attendance)) {
            return [];
        }

        $open = AttendanceIntegrityFlag::query()->visibleTo($user)->where('status', 'open')->count();

        return $open > 0
            ? [new NextStep(NextStepId::Integrity, '/attendance/integrity', (string) $open, $open)]
            : [];
    }

    /**
     * The newest run only: an older run left in a bad state is history, and
     * the newest is the one the next signature or retry belongs to.
     *
     * @return list<NextStep>
     */
    private function payroll(User $user, Tenant $tenant): array
    {
        if (! $user->hasPermission('payroll.process')
            || ! $user->hasPermission('payroll.viewAll')
            || ! $this->plans->allows($tenant, PlanFeature::Payroll)) {
            return [];
        }

        $run = PayrollRun::query()->latest('id')->first();

        $id = match ($run?->status) {
            'failed' => NextStepId::PayrollFailed,
            'completed' => NextStepId::PayrollApprove,
            default => null,
        };

        if ($run === null || $id === null) {
            return [];
        }

        return [new NextStep($id, '/payroll/'.$run->public_id, $run->public_id.':'.$run->status, 1, null, null, $run->period_label)];
    }

    /** @return list<NextStep> */
    private function unmatchedBadges(User $user, Tenant $tenant): array
    {
        if (! $user->hasPermission('device.viewAny') || ! $user->hasPermission('attendance.manage')) {
            return [];
        }

        $badges = count($this->unmatched->groups());

        return $badges > 0
            ? [new NextStep(NextStepId::UnmatchedBadges, '/devices', (string) $badges, $badges)]
            : [];
    }
}
