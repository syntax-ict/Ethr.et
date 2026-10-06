<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OnboardingProgress;
use App\Services\CurrentTenant;
use App\Services\Onboarding\ReadinessScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Readiness & Go Live (OnboardingStep::READINESS_GO_LIVE): a scored readiness
 * report with a gap list, and the go-live action that completes onboarding.
 * See ONBOARDING_V2.md.
 */
class ReadinessController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly ReadinessScorer $scorer,
    ) {}

    public function show(): JsonResponse
    {
        Gate::authorize('settings.manage');

        return response()->json($this->scorer->score((int) $this->currentTenant->id()));
    }

    public function goLive(): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = $this->currentTenant->get();
        $report = $this->scorer->score((int) $this->currentTenant->id());

        $progress = OnboardingProgress::firstOrCreate(
            ['tenant_id' => $this->currentTenant->id()],
            ['current_step' => 1, 'completed_steps' => [], 'step_data' => []]
        );
        $progress->markStepComplete(OnboardingStep::READINESS_GO_LIVE->value, [
            'overall_score' => $report['overall_score'],
            'level' => $report['level'],
        ]);
        $progress->update(['completed_at' => now()]);

        AuditLog::record('onboarding.went_live', $tenant, [
            'overall_score' => $report['overall_score'],
            'level' => $report['level'],
        ]);

        return response()->json([
            'message' => 'Organization is live.',
            'readiness' => $report,
            'redirect' => '/dashboard',
        ]);
    }
}
