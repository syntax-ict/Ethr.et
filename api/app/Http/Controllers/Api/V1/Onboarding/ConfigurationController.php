<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\ApplyConfigurationRequest;
use App\Http\Requests\Onboarding\PreviewConfigurationRequest;
use App\Models\AuditLog;
use App\Models\OnboardingProgress;
use App\Services\CurrentTenant;
use App\Services\Onboarding\IndustryCatalog;
use App\Services\Onboarding\IndustryProfileResolver;
use App\Services\Onboarding\OrganizationProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The smart-configuration step of onboarding (OnboardingStep::SMART_CONFIGURATION).
 *
 * `industries` lists the selectable industries; `preview` returns a scored,
 * editable configuration plan for one; `apply` provisions a (possibly edited)
 * plan through OrganizationProvisioner. See ONBOARDING_V2.md decisions D1–D3.
 */
class ConfigurationController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly IndustryCatalog $catalog,
        private readonly IndustryProfileResolver $resolver,
        private readonly OrganizationProvisioner $provisioner,
    ) {}

    public function industries(): JsonResponse
    {
        Gate::authorize('settings.manage');

        return response()->json(['data' => $this->catalog->all()]);
    }

    public function preview(PreviewConfigurationRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $signals = array_filter([
            'employee_count' => $request->integer('employee_count') ?: null,
            'region' => $request->input('region'),
        ], static fn ($value): bool => $value !== null);

        $plan = $this->resolver->resolve($request->string('industry')->value(), $signals);

        return response()->json($plan->toArray());
    }

    public function apply(ApplyConfigurationRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $tenant = $this->currentTenant->get();

        if (! $tenant) {
            return response()->json([
                'type' => 'https://ethr.et/errors/tenant-required',
                'title' => 'Tenant Required',
                'status' => 422,
                'detail' => __('general.not_found', ['resource' => 'Tenant']),
            ], 422);
        }

        /** @var array<string, mixed> $plan */
        $plan = $request->input('plan');
        $result = $this->provisioner->apply($tenant, $plan);

        $industryKey = $request->input('industry');
        $industry = is_string($industryKey) ? $this->catalog->find($industryKey) : null;

        $settings = $tenant->settings ?? [];
        $settings['configuration_applied_at'] = now()->toIso8601String();
        if ($industry !== null) {
            $settings['industry'] = $industry['key'];
        }
        if ($request->boolean('save')) {
            // A within-tenant save so the admin can revisit or re-apply. Publishing
            // a plan as a globally reusable OrganizationTemplate is deferred — that
            // table is a global catalog and would need tenant scoping first
            // (ONBOARDING_V2.md, Slice 2 note).
            $settings['saved_configuration'] = $plan;
        }

        $tenant->update([
            'type' => $industry['base'] ?? $tenant->type,
            'settings' => $settings,
        ]);

        $progress = OnboardingProgress::firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['current_step' => 1, 'completed_steps' => [], 'step_data' => []]
        );
        $progress->markStepComplete(OnboardingStep::SMART_CONFIGURATION->value, [
            'industry' => $industry['key'] ?? null,
            'provisioned' => $result->toArray(),
        ]);

        AuditLog::record('onboarding.configuration_applied', $tenant, [
            'industry' => $industry['key'] ?? null,
            'saved' => $request->boolean('save'),
            'provisioned' => $result->toArray(),
        ]);

        return response()->json([
            'message' => __('general.created', ['resource' => 'Configuration']),
            'industry' => $industry !== null
                ? ['key' => $industry['key'], 'label' => $industry['label'], 'base' => $industry['base']]
                : null,
            'provisioned' => $result->toArray(),
        ]);
    }
}
