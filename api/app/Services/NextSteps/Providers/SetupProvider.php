<?php

declare(strict_types=1);

namespace App\Services\NextSteps\Providers;

use App\Enums\NextStepId;
use App\Enums\OnboardingStep;
use App\Enums\PlanFeature;
use App\Models\OnboardingProgress;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Attendance\AttendanceHealth;
use App\Services\NextSteps\NextStep;
use App\Services\NextSteps\StepProvider;
use App\Services\Onboarding\ReadinessScorer;
use App\Services\PlanFeatureService;

/**
 * Configuration that is genuinely unfinished, for the person who can change it.
 *
 * It reads the readiness rubric and attendance health rather than keeping a
 * third list of what "set up" means. Each failing readiness check becomes a
 * step of its own with its fix link, so an organisation that has no holidays
 * is told that, not that it is "73% ready". A check about a module the plan
 * does not include is left out, since nobody there can fix it.
 *
 * The check labels the scorer carries are English-only and are not used: the
 * step id selects translated copy in the client.
 */
final class SetupProvider implements StepProvider
{
    /** Readiness checks that only mean something when the plan includes the module. */
    private const CHECK_FEATURE = [
        'shifts_exist' => PlanFeature::Attendance,
        'holidays_exist' => PlanFeature::Attendance,
        'attendance_source_configured' => PlanFeature::Attendance,
        'leave_types_exist' => PlanFeature::Leave,
    ];

    public function __construct(
        private readonly ReadinessScorer $readiness,
        private readonly AttendanceHealth $attendance,
        private readonly PlanFeatureService $plans,
    ) {}

    public function steps(User $user, Tenant $tenant): array
    {
        if (! $user->hasPermission('settings.manage')) {
            return [];
        }

        $steps = [];
        $attendanceOn = $this->plans->allows($tenant, PlanFeature::Attendance);
        $blockers = $attendanceOn && $user->hasPermission('attendance.manage')
            ? array_values(array_filter(
                $this->attendance->checks($tenant),
                static fn (array $check): bool => $check['severity'] === 'blocker',
            ))
            : [];

        foreach ($this->readiness->score($tenant->id)['gaps'] as $gap) {
            $id = NextStepId::fromReadinessCheck($gap['id']);
            $feature = self::CHECK_FEATURE[$gap['id']] ?? null;

            if ($id === null || ($feature !== null && ! $this->plans->allows($tenant, $feature))) {
                continue;
            }

            if ($id === NextStepId::SetupShifts && in_array('no_default_schedule', array_column($blockers, 'id'), true)) {
                continue;
            }

            $steps[] = new NextStep($id, $gap['remediation'], 'open');
        }

        if ($blockers !== []) {
            $steps[] = new NextStep(
                NextStepId::AttendanceBlockers,
                $blockers[0]['fix_route'],
                implode(',', array_column($blockers, 'id')),
                count($blockers),
            );
        }

        $summary = $this->summary($user, $tenant);

        if ($summary !== null && ! $summary['complete']) {
            $steps[] = new NextStep(
                NextStepId::SetupWizard,
                '/setup/guided',
                (string) $summary['done'],
                $summary['total'] - $summary['done'],
                $summary['total'],
            );
        }

        return $steps;
    }

    /**
     * How far the guided setup has got, for whoever may run it. One answer for
     * the dashboard card, the sidebar banner and the mobile bar, which used to
     * decide who sees it three different ways.
     *
     * @return array{done: int, total: int, complete: bool}|null
     */
    public function summary(User $user, Tenant $tenant): ?array
    {
        if (! $user->hasPermission('settings.manage')) {
            return null;
        }

        $progress = OnboardingProgress::query()->first();
        $guided = OnboardingStep::guided();
        $finished = array_intersect($guided, (array) ($progress->completed_steps ?? []));

        return [
            'done' => count($finished),
            'total' => count($guided),
            'complete' => $progress?->completed_at !== null,
        ];
    }
}
