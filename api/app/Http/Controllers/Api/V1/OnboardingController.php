<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OnboardingProgress;
use App\Models\OrganizationTemplate;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function getProgress(): JsonResponse
    {
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

    public function updateStep(Request $request, int $step): JsonResponse
    {
        if ($step < 1 || $step > 7) {
            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Invalid Step',
                'status' => 422,
                'detail' => 'Step must be between 1 and 7.',
            ], 422);
        }

        $progress = OnboardingProgress::firstOrCreate(
            ['tenant_id' => $this->currentTenant->id()],
            ['current_step' => 1, 'completed_steps' => [], 'step_data' => []]
        );

        $progress->markStepComplete($step, $request->all());

        AuditLog::record('onboarding.step_completed', $progress, [
            'step' => $step,
        ]);

        return response()->json($progress);
    }

    public function applyTemplate(Request $request): JsonResponse
    {
        $request->validate([
            'template_slug' => ['required', 'string', 'exists:organization_templates,slug'],
        ]);

        $template = OrganizationTemplate::where('slug', $request->input('template_slug'))->firstOrFail();
        $templateData = $template->template_data ?? [];

        $tenant = $this->currentTenant->get();

        if ($tenant) {
            $tenant->update([
                'type' => $template->slug,
                'settings' => array_merge($tenant->settings ?? [], [
                    'template' => $template->slug,
                    'template_applied_at' => now()->toIso8601String(),
                ]),
            ]);
        }

        AuditLog::record('onboarding.template_applied', $tenant, [
            'template' => $template->slug,
        ]);

        return response()->json([
            'message' => __('general.created', ['resource' => 'Template configuration']),
            'template' => [
                'public_id' => $template->public_id,
                'name' => $template->name,
                'slug' => $template->slug,
            ],
            'data' => $templateData,
        ]);
    }

    public function complete(): JsonResponse
    {
        $progress = OnboardingProgress::where('tenant_id', $this->currentTenant->id())->first();

        if ($progress) {
            $progress->update(['completed_at' => now()]);
        }

        AuditLog::record('onboarding.completed', $this->currentTenant->get());

        return response()->json([
            'message' => 'Onboarding completed successfully.',
            'redirect' => '/dashboard',
        ]);
    }
}
