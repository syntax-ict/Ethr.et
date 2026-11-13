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
use App\Services\Onboarding\PolicyProfileCatalog;
use App\Services\Onboarding\ProvisioningResult;
use App\Services\Onboarding\WorkforceProfileCatalog;
use App\Services\Tenancy\OrganisationProfile;
use App\Support\OrganisationSectors;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
        private readonly WorkforceProfileCatalog $workforceProfiles,
        private readonly PolicyProfileCatalog $policyProfiles,
        private readonly OrganisationProfile $profile,
        private readonly OrganisationSectors $sectors,
    ) {}

    public function industries(): JsonResponse
    {
        Gate::authorize('settings.manage');

        return response()->json([
            'data' => $this->catalog->all(),
            'workforce_profiles' => $this->workforceProfiles->all(),
            'policy_profiles' => $this->policyProfiles->all(),
            'catalog_version' => IndustryCatalog::VERSION,
        ]);
    }

    public function preview(PreviewConfigurationRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        // A headcount band answers the same question as a count: its lower
        // bound is the conservative reading for the attendance heuristic.
        $band = $request->input('staff_band');
        $fromBand = is_string($band) ? (int) $band : null;

        $signals = array_filter([
            'employee_count' => ($request->integer('employee_count') ?: null) ?? $fromBand,
            'region' => $request->input('region'),
            'workforce_profile' => $request->input('workforce_profile'),
            'policy_profile' => $request->input('policy_profile'),
            'organization_type' => $request->input('organization_type') ?? $this->currentTenant->get()?->organization_type,
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

        $industryKey = $request->input('industry');
        $industry = is_string($industryKey) ? $this->catalog->find($industryKey) : null;
        $profiles = array_filter([
            'workforce_profile' => $request->input('workforce_profile'),
            'policy_profile' => $request->input('policy_profile'),
        ], static fn ($value): bool => is_string($value));

        // One transaction for the records and the bookkeeping about them. The
        // provisioner always ran in its own; the settings, progress and audit
        // writes used to follow outside it, so a failure there left an
        // organisation configured with no record that it had been.
        $result = DB::transaction(function () use ($tenant, $plan, $industry, $profiles, $request): ProvisioningResult {
            $result = $this->provisioner->apply($tenant, $plan);

            $tenant->refresh();
            $settings = $tenant->settings ?? [];
            $settings['configuration_applied_at'] = now()->toIso8601String();
            if ($industry !== null) {
                $settings['industry'] = $industry['key'];
            }
            // Where the configuration came from, so a later catalogue can be
            // compared against it. Records keep no per-row origin: re-applying
            // is create-only, so this plus the audit row below is the history.
            $settings['configuration_profile'] = [
                'industry' => $industry['key'] ?? null,
                'workforce_profile' => $profiles['workforce_profile'] ?? ($industry['profile'] ?? null),
                'policy_profile' => $profiles['policy_profile'] ?? ($industry['policy'] ?? null),
                'catalog_version' => IndustryCatalog::VERSION,
                'applied_at' => $settings['configuration_applied_at'],
            ];
            if (is_string($request->input('staff_band'))) {
                $settings['configuration_profile']['staff_band'] = $request->input('staff_band');
            }
            // The onboarding answers the organisation gave, kept as given: they
            // replace the defaults sign-up wrote.
            foreach (['calendar', 'pay_period'] as $answer) {
                if (is_string($request->input($answer))) {
                    $settings[$answer] = $request->input($answer);
                }
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

            // The profile its statutory rules resolve against: the kind of
            // organisation in law it chose, and its industry as its sector.
            // No statutory row is written for it; the profile is the reference.
            $kind = $request->input('organization_type');
            if (is_string($kind) || $industry !== null) {
                $this->profile->set($tenant, is_string($kind) ? $kind : $tenant->organization_type, $request->user()?->id, $industry['key'] ?? null);
                if (is_string($kind)) {
                    $tenant->update(['organization_type_needs_review' => ! $this->sectors->isPlausible($industry['base'] ?? $tenant->type, $kind)]);
                }
            }

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
                'workforce_profile' => $settings['configuration_profile']['workforce_profile'],
                'policy_profile' => $settings['configuration_profile']['policy_profile'],
                'catalog_version' => IndustryCatalog::VERSION,
                'saved' => $request->boolean('save'),
                'provisioned' => $result->toArray(),
            ]);

            return $result;
        });

        return response()->json([
            'message' => __('general.created', ['resource' => 'Configuration']),
            'industry' => $industry !== null
                ? ['key' => $industry['key'], 'label' => $industry['label'], 'base' => $industry['base']]
                : null,
            'provisioned' => $result->toArray(),
        ]);
    }
}
