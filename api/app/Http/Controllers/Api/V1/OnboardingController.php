<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OnboardingProgress;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The progress record the sidebar's "Getting Started" reads. The guided setup's
 * own controllers (Onboarding\*) mark its steps done.
 *
 * Four v1 endpoints were removed on 2026-10-09: PUT /progress/{step},
 * POST /apply-template, /invite and /complete. Nothing called them. The guided
 * setup does each through its own path: configuration/apply provisions,
 * Settings → Users invites, and go-live completes with a readiness score
 * (POST /complete set completed_at without one).
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function getProgress(): JsonResponse
    {
        Gate::authorize('settings.manage');

        // A platform super admin belongs to no tenant, and `settings.manage`
        // passes for them because hasPermission() short-circuits on the role.
        // Without this the create below inserted a null `tenant_id` and threw a
        // 500 — on every load of the dashboard they land on after signing in.
        // Onboarding is tenant-scoped, so "does not exist here" is the honest
        // answer, not an empty record invented for a tenant that isn't there.
        if (! $this->currentTenant->resolved()) {
            return $this->noTenantContext();
        }

        $progress = OnboardingProgress::where('tenant_id', $this->currentTenant->id())
            ->first();

        if (! $progress) {
            $progress = OnboardingProgress::create([
                'tenant_id' => $this->currentTenant->id(),
                'current_step' => 1,
                'completed_steps' => [],
                'step_data' => [],
            ]);
        }

        return response()->json($progress);
    }

    /**
     * Onboarding only exists inside a tenant. Callers with no tenant resolved —
     * in practice the platform super admin, whose `settings.manage` check passes
     * on role alone — get a 404 rather than a 500 from a null `tenant_id`.
     */
    private function noTenantContext(): JsonResponse
    {
        return response()->json([
            'type' => 'https://ethr.et/errors/not-found',
            'title' => 'Not Found',
            'status' => 404,
            'detail' => __('general.not_found', ['resource' => 'Onboarding progress']),
        ], 404)->header('Content-Type', 'application/problem+json');
    }
}
