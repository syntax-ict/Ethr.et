<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\UpdateAccessRequest;
use App\Models\AuditLog;
use App\Models\OnboardingProgress;
use App\Models\Tenant;
use App\Services\Auth\AuthIdentifierResolver;
use App\Services\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Access & identity step (OnboardingStep::ACCESS_IDENTITY): which identifier
 * types employees may log in with. Lets organizations without staff email use
 * mobile number or employee number as the primary login. See ONBOARDING_V2.md.
 */
class AccessController extends Controller
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function show(): JsonResponse
    {
        Gate::authorize('settings.manage');

        $settings = $this->settingsOf($this->currentTenant->get());

        return response()->json([
            'available' => AuthIdentifierResolver::AVAILABLE,
            'login_identifiers' => AuthIdentifierResolver::sanitizeTypes(
                (array) ($settings['login_identifiers'] ?? AuthIdentifierResolver::DEFAULT)
            ),
            'role_defaults' => $settings['login_identifier_defaults'] ?? [],
        ]);
    }

    public function update(UpdateAccessRequest $request): JsonResponse
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

        $identifiers = AuthIdentifierResolver::sanitizeTypes($request->validated('login_identifiers'));

        $settings = $this->settingsOf($tenant);
        $settings['login_identifiers'] = $identifiers;
        if ($request->has('role_defaults')) {
            $settings['login_identifier_defaults'] = $request->validated('role_defaults');
        }
        $tenant->update(['settings' => $settings]);

        $progress = OnboardingProgress::firstOrCreate(
            ['tenant_id' => $tenant->id],
            ['current_step' => 1, 'completed_steps' => [], 'step_data' => []]
        );
        $progress->markStepComplete(OnboardingStep::ACCESS_IDENTITY->value, ['login_identifiers' => $identifiers]);

        AuditLog::record('onboarding.access_updated', $tenant, ['login_identifiers' => $identifiers]);

        return response()->json([
            'login_identifiers' => $identifiers,
            'role_defaults' => $settings['login_identifier_defaults'] ?? [],
        ]);
    }

    /**
     * Tenant settings as a plain array. Read via getAttribute so the array
     * cast's loose static type does not defeat offset access under analysis.
     *
     * @return array<string, mixed>
     */
    private function settingsOf(?Tenant $tenant): array
    {
        $raw = $tenant?->getAttribute('settings');

        return is_array($raw) ? $raw : [];
    }
}
