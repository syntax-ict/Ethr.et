<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OnboardingStep;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Onboarding\ApplyTemplateRequest;
use App\Http\Requests\Onboarding\InviteTeamRequest;
use App\Models\AuditLog;
use App\Models\OnboardingProgress;
use App\Models\OrganizationTemplate;
use App\Services\CurrentTenant;
use App\Services\Onboarding\OrganizationProvisioner;
use App\Services\UserProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly UserProvisioningService $provisioning,
        private readonly OrganizationProvisioner $organizationProvisioner,
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

    public function updateStep(Request $request, int $step): JsonResponse
    {
        Gate::authorize('settings.manage');

        if (! $this->currentTenant->resolved()) {
            return $this->noTenantContext();
        }

        if (OnboardingStep::tryFrom($step) === null) {
            return response()->json([
                'type' => 'https://ethr.et/errors/validation',
                'title' => 'Invalid Step',
                'status' => 422,
                'detail' => sprintf(
                    'Step must be between %d and %d.',
                    OnboardingStep::first()->value,
                    OnboardingStep::last()->value,
                ),
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

    public function applyTemplate(ApplyTemplateRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        $template = OrganizationTemplate::where('slug', $request->input('template_slug'))->firstOrFail();
        $templateData = $template->template_data ?? [];

        $tenant = $this->currentTenant->get();

        if (! $tenant) {
            return response()->json([
                'type' => 'https://ethr.et/errors/tenant-required',
                'title' => 'Tenant Required',
                'status' => 422,
                'detail' => __('general.not_found', ['resource' => 'Tenant']),
            ], 422);
        }

        // Provisioning is create-only and soft-delete aware, so re-applying a
        // template adds whatever is missing and leaves everything else alone.
        $result = $this->organizationProvisioner->apply($tenant, $templateData);

        $tenant->update([
            'type' => $template->slug,
            'settings' => array_merge($tenant->settings ?? [], [
                'template' => $template->slug,
                'template_applied_at' => now()->toIso8601String(),
            ]),
        ]);

        AuditLog::record('onboarding.template_applied', $tenant, [
            'template' => $template->slug,
            'provisioned' => $result->toArray(),
        ]);

        return response()->json([
            'message' => __('general.created', ['resource' => 'Template configuration']),
            'template' => [
                'public_id' => $template->public_id,
                'name' => $template->name,
                'slug' => $template->slug,
            ],
            'provisioned' => $result->toArray(),
            'data' => $templateData,
        ]);
    }

    public function inviteTeam(InviteTeamRequest $request): JsonResponse
    {
        Gate::authorize('settings.manage');

        if (! $this->currentTenant->resolved()) {
            return $this->noTenantContext();
        }

        $tenantId = $this->currentTenant->id();
        $role = UserRole::from($request->input('role', 'employee'));
        $invitedBy = $request->user()->id;
        $emails = array_unique($request->input('emails'));

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($emails, $role, $invitedBy, &$created, &$skipped) {
            foreach ($emails as $email) {
                // Provisioning creates the account in `invited` status and emails
                // an activation link; it returns null if the email already exists.
                $user = $this->provisioning->provision(
                    email: $email,
                    role: $role,
                    invitedBy: $invitedBy,
                );

                if ($user === null) {
                    $skipped[] = $email;

                    continue;
                }

                AuditLog::record('user.invited', $user, [
                    'invited_via' => 'onboarding',
                ]);

                $created[] = [
                    'email' => $user->email,
                    'public_id' => $user->public_id,
                ];
            }
        });

        $progress = OnboardingProgress::firstOrCreate(
            ['tenant_id' => $tenantId],
            ['current_step' => 1, 'completed_steps' => [], 'step_data' => []]
        );
        $progress->markStepComplete(6, [
            'invited_emails' => array_column($created, 'email'),
            'skipped_emails' => $skipped,
        ]);

        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
            'message' => count($created).' user(s) invited, '.count($skipped).' already existed.',
        ]);
    }

    public function complete(): JsonResponse
    {
        Gate::authorize('settings.manage');

        if (! $this->currentTenant->resolved()) {
            return $this->noTenantContext();
        }

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
